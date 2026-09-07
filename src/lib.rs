//! Terrarium — embeds the Wasmtime runtime in a PHP extension so PHP can expose a
//! typed capability SDK to a guest language engine running sandboxed in WASM.
//!
//! This crate provides the low-level engine primitive, registered as the
//! `TerrariumRuntime` class. The public, documented API is the PHP `Terrarium` facade in
//! `lib/Terrarium.php`, which adds `eval()` and type inference (`types()`) on top.
//!
//! The core: load → instantiate → `invoke`, with isolation and resource limits
//! (`memoryLimit` → `StoreLimits`, `timeoutMs` → epoch interruption, `maxStack`
//! → `max_wasm_stack`, `fuel` → instruction metering).
//!
//! The value bridge:
//!   - `register(name, callable)` populates a flat dispatch table — the trust
//!     boundary allowlist.
//!   - the guest reaches it through one `host_call(name, argsBytes)` import;
//!     values cross as msgpack over the guest's linear memory.
//!   - `eval(source)` runs the guest's `eval` entrypoint with the source string
//!     and marshals its result back, raising a guest program error as a typed
//!     `TerrariumGuestException`.
//!   - `grant`/`resolve`/`revoke` expose live PHP objects as opaque handles.
//!
//! Execution modes:
//!   - shared (default): one persistent `Store`/`Instance` for the object's
//!     life, so guest linear memory accumulates across calls (a session/REPL).
//!   - isolated (`isolated: true`): a fresh instance per call, cheaply made from
//!     a pre-compiled `InstancePre`, so each call is hermetic.
//!
//! A guest may also be loaded from a *precompiled artifact* — the machine code
//! `Runtime::precompile()` emits for this exact extension build — with
//! `precompiled: true`, which skips Cranelift entirely (see `deserialize`). That
//! is a host-trusted input, never guest input, and is never inferred: the flag
//! is the host saying so.
//!
//! What is shared between `Runtime` objects: *only immutable compiled code*.
//! Identical wasm bytes compiled under identical engine options resolve to one
//! process-wide `Engine` + `Module` + `InstancePre` (see `compiled_guest`), so
//! the second `new Runtime` of a guest costs an instantiation rather than a
//! recompile/deserialize. Everything that isolates a run stays per Runtime and
//! per `Store`: the `Store` itself, the `Instance` and its linear memory, the
//! `StoreLimits` (`memoryLimit`), deadlines (`timeoutMs`), the fuel budget, and
//! the capability table / handles / output buffer behind the bridge. A `Store`
//! carries a pointer to its own Runtime's `BridgeState`, so a shared
//! `InstancePre` still dispatches every `host_call` to the PHP callables of the
//! Runtime that made the call.

#![allow(non_snake_case)]

use ext_php_rs::binary::Binary;
use ext_php_rs::exception::PhpException;
use ext_php_rs::prelude::*;
use ext_php_rs::types::Zval;
use std::cell::RefCell;
use std::collections::{HashMap, VecDeque};
use std::hash::{BuildHasher, Hash, Hasher, RandomState};
use std::rc::Rc;
use std::sync::{Mutex, OnceLock};
use std::time::Instant;
use wasmtime::{
    Caller, Config, Engine, Instance, InstancePre, Linker, Module, Precompiled, Store, StoreLimits,
    StoreLimitsBuilder, Trap, UpdateDeadline,
};

mod bridge;
mod exceptions;
mod handles;
mod marshal;
mod timeout;

use bridge::{decode_args, BridgeState};
use exceptions::{
    TerrariumException, TerrariumGuestException, TerrariumMemoryException,
    TerrariumTimeoutException, TerrariumTrapException,
};
use marshal::{middle_to_zval, zval_to_middle, MiddleValue};
use timeout::{deadline_after, expired, CallTimeout, EpochTimer, TimeoutMs};

/// Per-`Store` data: the `StoreLimits` the `ResourceLimiter` hook reads, plus a
/// WASI context for guests that link a libc (capability-only guests never touch it).
struct StoreState {
    limits: StoreLimits,
    wasi: wasmtime_wasi::p1::WasiP1Ctx,
    deadline: Option<Instant>,
    /// The owning `Runtime`'s bridge state — the capability table every
    /// `host_call` from this `Store` dispatches against. It is the `Store`, not
    /// the `Linker`, that names the bridge: the compiled `InstancePre` is shared
    /// process-wide between Runtimes built from the same bytes, so a call must
    /// find *its own* Runtime's PHP callables through the data of the `Store`
    /// it is running in.
    ///
    /// A raw pointer because the `host_call` closure must be `Send + Sync +
    /// 'static` while `BridgeState` is `Rc`/`RefCell` (PHP is single-threaded,
    /// NTS). Soundness, unchanged from when the address was captured in the
    /// closure: the guest only ever runs on the PHP thread that owns the
    /// `Runtime`, so the state is never touched concurrently, and the
    /// `Rc<BridgeState>` allocation is owned by that `Runtime` — which outlives
    /// every `Store` it creates (the shared `Store` is its own field; an
    /// isolated one is a local of a call on it).
    bridge: BridgePtr,
}

/// The address of a `Runtime`'s `BridgeState`, carried in its `Store`'s data.
///
/// Wasmtime's linker APIs require the store data to be `Send`, so the bridge
/// can only travel as an address — exactly as it did when the closure captured
/// one directly. (`Sync` is not needed: the closure captures nothing.)
///
/// SAFETY: the marker impl is sound under the invariant the whole extension
/// rests on: PHP is single-threaded (NTS), a `Store` is only ever created and
/// run on the thread that owns its `Runtime`, and nothing here ever hands the
/// pointer to another thread. The pointee is `Rc<BridgeState>`, whose interior
/// mutability is likewise only ever touched from that one thread.
#[derive(Clone, Copy)]
struct BridgePtr(*const BridgeState);
unsafe impl Send for BridgePtr {}

/// A persistent store + its instantiated guest (shared mode).
struct Persistent {
    store: Store<StoreState>,
    instance: Instance,
}

/// The low-level engine primitive (not the public API). The public, documented
/// class is the PHP `Terrarium` facade in `lib/Terrarium.php`, which adds type inference
/// (`types()`) and `eval()` over this. Kept internal under the name
/// `TerrariumRuntime`.
#[php_class]
#[php(name = "Terrarium\\Runtime")]
pub struct Terrarium {
    /// Shared with every other Runtime holding the same `GuestKey`.
    engine: Engine,
    /// Pre-resolved imports for cheap (re)instantiation. Shared likewise: it
    /// resolves the `host_call` import to one closure that reads the calling
    /// `Store`'s bridge pointer, never a particular Runtime's state.
    instance_pre: InstancePre<StoreState>,
    /// The persistent instance in shared mode; lazily created on first use.
    /// `None`/unused in isolated mode (a fresh instance is made per call).
    /// Declared before `state` on purpose: its `Store` carries a pointer to
    /// `state`, and Rust drops fields in declaration order, so the Store is
    /// gone before the bridge it points at.
    shared: RefCell<Option<Persistent>>,
    state: Rc<BridgeState>,
    isolated: bool,
    memory_limit: usize,
    timeout_ms: u64,
    fuel: u64,
}

#[php_impl]
impl Terrarium {
    /// Compile a guest from WebAssembly bytes. Limits default to unbounded; pass
    /// non-zero values to contain resource abuse.
    ///
    /// `isolated: true` runs each call in a fresh instance (hermetic); the
    /// default shares one persistent instance so guest state accumulates.
    ///
    /// `precompiled: true` says `source` is not WebAssembly but an artifact from
    /// `Runtime::precompile()` — machine code this same extension build emitted
    /// — which is loaded without compiling. It must be built with the same
    /// `fuel`-enabled setting; see `deserialize` for why the flag is required
    /// rather than detected, and `precompile` for what an artifact is bound to.
    #[php(defaults(memoryLimit = None, timeoutMs = None, maxStack = None, fuel = None, isolated = false, precompiled = false))]
    pub fn __construct(
        source: &Zval,
        memoryLimit: Option<i64>,
        timeoutMs: Option<i64>,
        maxStack: Option<i64>,
        fuel: Option<i64>,
        isolated: bool,
        precompiled: bool,
    ) -> PhpResult<Self> {
        // A PHP string is a byte string: take the raw `.wasm` bytes directly so
        // binary modules (not valid UTF-8) are accepted. Borrowed for the length
        // of the constructor — a cache hit needs no copy of them at all, and a
        // miss hands them straight to `Module::new`.
        let source = source.zend_str().map(|s| s.as_bytes()).ok_or_else(|| {
            PhpException::from_class::<TerrariumException>("source must be a string".to_owned())
        })?;

        let memory_limit = memoryLimit.unwrap_or(0).max(0) as usize;
        let timeout_ms = timeoutMs.unwrap_or(0).max(0) as u64;
        let max_stack = maxStack.unwrap_or(0).max(0) as usize;
        let fuel = fuel.unwrap_or(0).max(0) as u64;

        // Which of the two loaders may see these bytes is settled here, before
        // either does, so `deserialize` is only ever reached by bytes the host
        // both claimed and Wasmtime recognised as its own artifact. Neither
        // direction is silently corrected: taking wasm for an artifact would
        // hand `Module::deserialize` unvalidated input, and taking an artifact
        // for wasm would report a spurious parse error deep in `Module::new`.
        match (precompiled, Engine::detect_precompiled(source)) {
            (true, Some(Precompiled::Module)) => {}
            (true, Some(_)) => {
                return Err(PhpException::from_class::<TerrariumException>(
                    "precompiled: source is a precompiled component, not a module: \
                     Terrarium loads core modules only"
                        .to_owned(),
                ))
            }
            (true, None) => {
                return Err(PhpException::from_class::<TerrariumException>(
                    "precompiled: source is not a Wasmtime precompiled module. Produce it \
                     with Terrarium\\Runtime::precompile() from this same extension build, \
                     or drop precompiled: true to load WebAssembly"
                        .to_owned(),
                ))
            }
            (false, Some(_)) => {
                return Err(PhpException::from_class::<TerrariumException>(
                    "source is a precompiled Wasmtime artifact, not WebAssembly: pass \
                     precompiled: true to load it (only ever for an artifact this \
                     extension build produced — it is native code, not a sandboxed input)"
                        .to_owned(),
                ))
            }
            (false, None) => {}
        }

        // Compiling a heavy guest (a JS engine in wasm) dominates construction
        // even with the on-disk cache warm, because the artifact still has to be
        // deserialized into a fresh `Engine` per instance. The compiled code is
        // immutable and thread-safe, so identical bytes under identical engine
        // options are compiled once per process and every later Runtime clones
        // the (Arc-backed) handles.
        let Compiled {
            engine,
            instance_pre,
        } = compiled_guest(guest_key(source, precompiled, fuel > 0, max_stack), || {
            if precompiled {
                deserialize(source, fuel > 0, max_stack)
            } else {
                compile(source, fuel > 0, max_stack)
            }
        })?;

        // The capability table is *not* shared: it is what distinguishes two
        // Runtimes over the same guest, and each `Store` points back at its own.
        let state = BridgeState::new();

        Ok(Terrarium {
            engine,
            instance_pre,
            state,
            shared: RefCell::new(None),
            isolated,
            memory_limit,
            timeout_ms,
            fuel,
        })
    }

    /// Compile WebAssembly ahead of time and return the artifact bytes, to be
    /// loaded later with `new Runtime($artifact, precompiled: true)`.
    ///
    /// This is the deployment escape hatch from Cranelift: a heavy guest costs
    /// one to two seconds of compilation on first construction, which a
    /// short-lived process (an AWS Lambda cold start, where the on-disk
    /// `wasmtime::Cache` under `$HOME` is unusable anyway) pays in full. Run
    /// this in the build pipeline, ship the artifact beside the guest, and the
    /// deployed process only deserializes.
    ///
    /// The artifact is native machine code for **this exact extension build** —
    /// this Wasmtime version, this target, this `Config`. Generate it with the
    /// same binary that will load it; Wasmtime refuses anything else, but it is
    /// a build-pipeline invariant rather than something to discover at run time.
    ///
    /// `fuel` and `maxStack` are the constructor's, and only in the sense
    /// `GuestKey` uses them: the artifact is bound to whether fuel metering is
    /// compiled in (the *budget* is per call), and `maxStack` is a run-time
    /// engine setting that never reaches the artifact — it is accepted here so
    /// one set of options describes both ends.
    ///
    /// `portable` (the default) compiles for the baseline of this machine's
    /// architecture rather than for the CPU features Wasmtime detects on the
    /// machine running `precompile()`. An artifact records the ISA features it
    /// was compiled to use, and loading refuses one that needs a feature the
    /// host lacks — so a build machine with AVX-512 would otherwise produce an
    /// artifact a plainer Lambda host cannot load. The baseline costs the guest
    /// little (the bundled engines are scalar interpreters). `portable: false`
    /// compiles for the current CPU, for a machine that builds and runs.
    #[php(defaults(maxStack = None, fuel = None, portable = true))]
    pub fn precompile(
        source: &Zval,
        maxStack: Option<i64>,
        fuel: Option<i64>,
        portable: bool,
    ) -> PhpResult<Binary<u8>> {
        let source = source.zend_str().map(|s| s.as_bytes()).ok_or_else(|| {
            PhpException::from_class::<TerrariumException>("source must be a string".to_owned())
        })?;
        if Engine::detect_precompiled(source).is_some() {
            return Err(PhpException::from_class::<TerrariumException>(
                "source is already a precompiled artifact: precompile() takes WebAssembly"
                    .to_owned(),
            ));
        }

        let max_stack = maxStack.unwrap_or(0).max(0) as usize;
        let fuel = fuel.unwrap_or(0).max(0) as u64;

        // The same `Config` the constructor would build for these options, so
        // the artifact is loadable by exactly the engine that will load it. An
        // explicit target (the host's own triple) is what turns off host CPU
        // feature detection: Wasmtime then uses the triple's baseline ISA
        // flags, and the loader accepts an artifact whose flags are a subset of
        // the loading host's.
        let mut config = engine_config(fuel > 0, max_stack);
        if portable {
            let triple = target_lexicon::Triple::host().to_string();
            config.target(&triple).map_err(|e| {
                PhpException::from_class::<TerrariumException>(format!(
                    "precompile: cannot target {triple}: {e:#}"
                ))
            })?;
        }
        let engine = Engine::new(&config).map_err(|e| {
            PhpException::from_class::<TerrariumException>(format!("engine: {e:#}"))
        })?;
        let artifact = engine.precompile_module(source).map_err(|e| {
            PhpException::from_class::<TerrariumException>(format!("precompile: {e:#}"))
        })?;
        Ok(Binary::new(artifact))
    }

    /// Expose a PHP callable to the guest under a flat, dotted capability name.
    /// The guest reaches it as `host_call("<name>", argsBytes)`, presented as
    /// `<dotted.name>(...)` (no synthetic root — the registered top-level names
    /// are installed as guest globals). This registry is the trust boundary.
    pub fn register(&self, name: String, callable: &Zval) -> PhpResult<()> {
        self.state
            .register(name, callable)
            .map_err(PhpException::default)
    }

    /// The registered capability names (audit surface).
    pub fn manifest(&self) -> Vec<String> {
        self.state.names()
    }

    /// Replace the SDK `.d.ts` served to type-aware guests via the reserved
    /// `$dts` capability. The PHP facade calls this on every `register()`.
    pub fn set_types(&self, dts: String) {
        self.state.set_types(dts);
    }

    /// Replace the compile options served to compiling guests via the reserved
    /// `$opts` capability. Takes a PHP associative array; the guest reads the
    /// keys it understands and ignores the rest, so options are added without
    /// an ABI change and are harmless on a guest that implements none.
    ///
    /// The options the bundled guests define today, both read by the
    /// **TypeScript** guest:
    ///
    /// - `sync_only` (bool) — a compile-time rejection of async/generator
    ///   syntax and of every use of a promise (a promise cannot settle without
    ///   a job queue, so its callbacks are abandoned in silence). Other guests
    ///   accept it and do nothing with it; the QuickJS-based ones instead fail
    ///   *at run time* with `AsyncIncomplete` when a program cannot finish,
    ///   which is on by default everywhere.
    /// - `type_argument_schemas` (list of callee names) — derive a JSON Schema
    ///   from the single type argument of every call to those callees, and
    ///   return them from `analyze()`.
    ///
    /// ```php
    /// $rt->setCompileOptions(['sync_only' => true]);
    /// $rt->setCompileOptions(['type_argument_schemas' => ['ctx.model', 'ctx.agent']]);
    /// ```
    pub fn set_compile_options(&self, options: &Zval) -> PhpResult<()> {
        let entries = match zval_to_middle(options).map_err(PhpException::default)? {
            MiddleValue::Map(entries) => entries,
            // An empty PHP array has sequential (no) keys, so it arrives as an
            // array: treat it as "no options" rather than a type error.
            MiddleValue::Array(items) if items.is_empty() => Vec::new(),
            _ => {
                return Err(PhpException::from_class::<TerrariumException>(
                    "compile options must be an associative array of option => value".to_owned(),
                ))
            }
        };
        self.state.set_compile_options(entries);
        Ok(())
    }

    /// Store a live PHP object host-side and return an opaque handle the guest
    /// can pass back to a capability (which calls `resolve`). The object never
    /// crosses into the sandbox.
    pub fn grant(&self, resource: &Zval) -> i64 {
        self.state.handles.grant(resource)
    }

    /// Resolve a handle back to the live PHP object.
    pub fn resolve(&self, handle: i64) -> PhpResult<Zval> {
        self.state.handles.resolve(handle).ok_or_else(|| {
            PhpException::from_class::<TerrariumException>(format!("unknown handle {handle}"))
        })
    }

    /// Release a granted handle. Returns whether it existed.
    pub fn revoke(&self, handle: i64) -> bool {
        self.state.handles.revoke(handle)
    }

    /// Evaluate guest source and return its result marshaled to a PHP value.
    ///
    /// The guest exports `eval(ptr, len) -> packed` following the byte ABI: the
    /// source string is msgpack at `(ptr, len)` and the packed `i64` result is
    /// `(retPtr << 32) | retLen` into the guest's memory. A guest-side program
    /// error comes back as the sentinel map `{ "$error": "<message>" }`, which
    /// is raised here as a `TerrariumGuestException` rather than returned.
    /// A positive timeoutMs includes guest setup; null retains the constructor
    /// default's setup exemption, zero is unbounded, and negatives are rejected.
    #[php(defaults(timeoutMs = None))]
    pub fn eval(&self, source: String, timeoutMs: Option<TimeoutMs>) -> PhpResult<Zval> {
        let timeout = self.call_timeout(timeoutMs)?;
        // Each run captures its own output; a guest error leaves what was
        // printed before the crash readable via `output()`.
        self.state.clear_output();

        let middle = self.call_entry("eval", source, timeout)?;

        // A guest program error surfaces as the `{ "$error": ... }` sentinel,
        // where the value is either a plain message string or a structured
        // record `{message, type?, line?}` (the host accepts both forms).
        if let MiddleValue::Map(entries) = &middle {
            if let [(key, detail)] = entries.as_slice() {
                if key == "$error" {
                    return Err(PhpException::from_class::<TerrariumGuestException>(
                        format_guest_error(detail),
                    ));
                }
            }
        }
        middle_to_zval(&middle).map_err(PhpException::default)
    }

    /// Type-check guest source without running it (type-aware guests only).
    ///
    /// Calls the guest's optional `check(ptr, len)` export — same byte ABI as
    /// `eval` — which returns every error diagnostic as data; nothing executes
    /// and the output buffer is untouched. Guests without the export raise a
    /// `TerrariumException`. A `$error` sentinel here is an internal guest
    /// failure (e.g. its compiler failed to start), not a program error.
    /// timeoutMs has the same per-call semantics as eval.
    #[php(defaults(timeoutMs = None))]
    pub fn check(&self, source: String, timeoutMs: Option<TimeoutMs>) -> PhpResult<Zval> {
        self.static_entry("check", source, self.call_timeout(timeoutMs)?)
    }

    /// The full static analysis of guest source: the same diagnostics `check()`
    /// returns, plus whatever else the guest was asked to extract, as
    /// `['diagnostics' => [...], 'schemas' => [...]]`.
    ///
    /// Calls the guest's optional `analyze(ptr, len)` export — same byte ABI as
    /// `eval` and `check`, and the same guarantee that nothing runs. A separate
    /// export rather than a wider `check()` result on purpose: the diagnostics
    /// array is the older contract, so a host that knows nothing of the richer
    /// result can never be handed one. Guests without the export raise a
    /// `TerrariumException`.
    ///
    /// The **TypeScript** guest fills `schemas` when the `type_argument_schemas`
    /// compile option names the callees to extract from (see
    /// `setCompileOptions`); with no such option it is always empty.
    /// timeoutMs has the same per-call semantics as eval.
    #[php(defaults(timeoutMs = None))]
    pub fn analyze(&self, source: String, timeoutMs: Option<TimeoutMs>) -> PhpResult<Zval> {
        self.static_entry("analyze", source, self.call_timeout(timeoutMs)?)
    }

    /// The guest output (`console.log` / `print`) captured during the most
    /// recent `eval`, lines joined by `\n`. Preserved even when that `eval`
    /// raised — so output printed before a crash is still readable.
    pub fn output(&self) -> String {
        self.state.output_text()
    }

    /// Drop the persistent shared instance, if any, so the next call starts from
    /// a fresh guest state. No-op in isolated mode. Returns whether one existed.
    pub fn reset(&self) -> bool {
        self.shared.borrow_mut().take().is_some()
    }
}

impl Terrarium {
    fn call_timeout(&self, timeout_ms: Option<TimeoutMs>) -> PhpResult<CallTimeout> {
        let timeout_ms = timeout_ms
            .map(TimeoutMs::value)
            .transpose()
            .map_err(PhpException::from_class::<TerrariumException>)?;
        CallTimeout::new(timeout_ms, self.timeout_ms)
            .map_err(PhpException::from_class::<TerrariumException>)
    }

    /// A guest's optional static entrypoint (`check`, `analyze`): analysis only,
    /// nothing runs, and the output buffer is untouched. Whatever the guest
    /// returns is marshaled through unchanged — the transport is shape-agnostic,
    /// so a guest can widen its result without an ABI change. A `$error`
    /// sentinel here is an internal guest failure (e.g. its compiler failed to
    /// start), not a program error, so it raises the base exception.
    fn static_entry(
        &self,
        entry: &'static str,
        source: String,
        timeout: CallTimeout,
    ) -> PhpResult<Zval> {
        let middle = self.call_entry(entry, source, timeout)?;

        if let MiddleValue::Map(entries) = &middle {
            if let [(key, detail)] = entries.as_slice() {
                if key == "$error" {
                    return Err(PhpException::from_class::<TerrariumException>(
                        format_guest_error(detail),
                    ));
                }
            }
        }
        middle_to_zval(&middle).map_err(PhpException::default)
    }

    /// Marshal `source` into guest memory, invoke the named `(i32, i32) -> i64`
    /// entrypoint (`eval`, or a guest's optional `check` / `analyze`), and decode
    /// the packed result — the shared byte-ABI round trip.
    fn call_entry(
        &self,
        entry: &'static str,
        source: String,
        timeout: CallTimeout,
    ) -> PhpResult<MiddleValue> {
        let bytes = MiddleValue::Str(source)
            .to_msgpack()
            .map_err(|e| PhpException::from_class::<TerrariumException>(format!("encode: {e}")))?;

        self.with_instance(timeout, move |store, instance| {
            let memory = instance
                .get_memory(&mut *store, "memory")
                .ok_or_else(|| no_export("memory"))?;
            let alloc = instance
                .get_typed_func::<i32, i32>(&mut *store, "guest_alloc")
                .map_err(|_| no_export("guest_alloc"))?;
            let entryf = instance
                .get_typed_func::<(i32, i32), i64>(&mut *store, entry)
                .map_err(|_| no_export(entry))?;

            // Write the source into guest-owned memory, then call the entry.
            check_deadline(store.data().deadline)?;
            let ptr = alloc
                .call(&mut *store, bytes.len() as i32)
                .map_err(map_err)?;
            memory
                .write(&mut *store, ptr as usize, &bytes)
                .map_err(|e| map_err(e.into()))?;
            check_deadline(store.data().deadline)?;
            let packed = entryf
                .call(&mut *store, (ptr, bytes.len() as i32))
                .map_err(map_err)?;

            // Read the result back out of guest memory and decode it.
            let (rptr, rlen) = unpack(packed);
            // The guest chose (rptr, rlen) in its packed return; bound it against
            // live memory before allocating, so a bad length can't force a giant
            // host allocation (same reasoning as `host_call`).
            range_in_bounds(rptr, rlen, memory.data_size(&*store))
                .map_err(|m| PhpException::from_class::<TerrariumException>(m.to_owned()))?;
            let mut out = vec![0u8; rlen];
            memory
                .read(&*store, rptr, &mut out)
                .map_err(|e| map_err(e.into()))?;
            MiddleValue::from_msgpack(&out)
                .map_err(|e| PhpException::from_class::<TerrariumException>(format!("decode: {e}")))
        })
    }

    /// Run `f` with an instance, honouring the execution mode. Isolated: a fresh
    /// instance per call from the `InstancePre`. Shared: the persistent instance
    /// (created lazily), reused across calls.
    fn with_instance<R>(
        &self,
        timeout: CallTimeout,
        f: impl FnOnce(&mut Store<StoreState>, &Instance) -> PhpResult<R>,
    ) -> PhpResult<R> {
        if self.isolated {
            return self.run_instance(&mut None, timeout, f);
        }

        // Shared: one persistent instance, reused. `try_borrow_mut` turns a
        // re-entrant call (a capability that calls back into invoke on the same
        // instance) into a clean error rather than a panic.
        let mut slot = self.shared.try_borrow_mut().map_err(|_| {
            PhpException::from_class::<TerrariumException>(
                "re-entrant call on a shared Terrarium instance is not supported (use isolated: true)"
                    .to_owned(),
            )
        })?;
        self.run_instance(&mut slot, timeout, f)
    }

    fn run_instance<R>(
        &self,
        slot: &mut Option<Persistent>,
        timeout: CallTimeout,
        f: impl FnOnce(&mut Store<StoreState>, &Instance) -> PhpResult<R>,
    ) -> PhpResult<R> {
        let setup_deadline = timeout.setup_deadline();
        let result = self.guarded(setup_deadline, || {
            if slot.is_none() {
                let mut store = self.fresh_store(setup_deadline);
                check_deadline(setup_deadline)?;
                let instance = self.instance_pre.instantiate(&mut store).map_err(map_err)?;
                check_deadline(setup_deadline)?;
                initialize_reactor(&mut store, &instance)?;
                check_deadline(setup_deadline)?;
                *slot = Some(Persistent { store, instance });
            }
            let Persistent { store, instance } = slot.as_mut().unwrap();
            self.arm_fuel(store)?;
            match timeout {
                CallTimeout::Legacy(ms) => {
                    // Omitted/null preserves the constructor's setup exemption.
                    let deadline = deadline_after(ms)
                        .map_err(PhpException::from_class::<TerrariumException>)?;
                    arm_deadline(store, deadline);
                    self.guarded(deadline, || f(store, instance))
                }
                CallTimeout::Explicit(deadline) => {
                    // Rearm the epoch, not the clock: setup spent this budget too.
                    arm_deadline(store, deadline);
                    check_deadline(deadline)?;
                    f(store, instance)
                }
            }
        });
        // A sandbox-level fault (trap, timeout, memory) poisons the instance --
        // guest state may be mid-mutation (e.g. an interrupted language-runtime
        // startup). Drop it so the next call instantiates fresh; guest-program
        // errors (the $error sentinel) return Ok and never take this path.
        if result.is_err() {
            *slot = None;
        } else if let Some(persistent) = slot {
            persistent.store.data_mut().deadline = None;
        }
        result
    }

    /// Configure interruption before instantiate/start/_initialize can run.
    fn fresh_store(&self, deadline: Option<Instant>) -> Store<StoreState> {
        let limits = {
            let mut b = StoreLimitsBuilder::new();
            if self.memory_limit > 0 {
                b = b.memory_size(self.memory_limit);
            }
            b.build()
        };
        let wasi = wasmtime_wasi::WasiCtxBuilder::new().build_p1();
        let mut store = Store::new(
            &self.engine,
            StoreState {
                limits,
                wasi,
                deadline,
                // This Runtime's capability table, for the `host_call` import of
                // a possibly shared `InstancePre`. See `StoreState::bridge`.
                bridge: BridgePtr(Rc::as_ptr(&self.state)),
            },
        );
        store.limiter(|s| &mut s.limits);
        store.epoch_deadline_callback(|store| {
            if expired(store.data().deadline) {
                return Err(Trap::Interrupt.into());
            }
            // Isolated nested calls share an Engine. A tick from another call
            // must not expire this Store before its own deadline (or at all
            // when unbounded). The expiring operation keeps ticking until exit.
            Ok(UpdateDeadline::Continue(1))
        });
        store.set_epoch_deadline(1);
        // Keep the existing fuel contract: setup is exempt, even when timed.
        if self.fuel > 0 {
            let _ = store.set_fuel(u64::MAX);
        }
        store
    }

    /// Each operation gets the configured fuel budget, after guest setup.
    fn arm_fuel(&self, store: &mut Store<StoreState>) -> PhpResult<()> {
        if self.fuel > 0 {
            store.set_fuel(self.fuel).map_err(|e| {
                PhpException::from_class::<TerrariumException>(format!("fuel: {e:#}"))
            })?;
        }
        Ok(())
    }

    /// Timer ownership spans every exit, including setup errors and unwinding.
    fn guarded<R>(
        &self,
        deadline: Option<Instant>,
        f: impl FnOnce() -> PhpResult<R>,
    ) -> PhpResult<R> {
        check_deadline(deadline)?;
        let _timer = deadline
            .map(|deadline| EpochTimer::start(self.engine.clone(), deadline))
            .transpose()
            .map_err(|e| {
                PhpException::from_class::<TerrariumException>(format!("timeout timer: {e}"))
            })?;
        let result = f()?;
        // Host work cannot be preempted, but cannot return a late success either.
        // An existing error from f takes precedence and is never masked here.
        check_deadline(deadline)?;
        Ok(result)
    }
}

fn arm_deadline(store: &mut Store<StoreState>, deadline: Option<Instant>) {
    store.data_mut().deadline = deadline;
    store.set_epoch_deadline(1);
}

fn check_deadline(deadline: Option<Instant>) -> PhpResult<()> {
    if expired(deadline) {
        return Err(map_err(Trap::Interrupt.into()));
    }
    Ok(())
}

/// How many distinct compiled guests are kept alive process-wide. A compiled
/// engine is large (tens of MiB for a language engine), so the cache is a small
/// fixed set rather than an unbounded map: a host that cycles through many
/// different guests evicts the least recently *inserted* entry instead of
/// retaining every guest it ever loaded. Eviction only drops this cache's
/// handles — `Engine`/`Module`/`InstancePre` are `Arc`-backed, so an evicted
/// guest stays alive and usable for as long as a `Runtime` holds it, and the
/// next `Runtime` over those bytes simply compiles again.
const GUEST_CACHE_CAPACITY: usize = 8;

/// Identity of a compiled guest: the source bytes, how they are to be read, and
/// every constructor option that reaches the `Config` and therefore the emitted
/// machine code. Two Runtimes agreeing on all of it can share one compilation;
/// anything else about them (memory limit, timeout, fuel *amount*, isolated) is
/// applied per `Store` and so is deliberately absent here. `epoch_interruption`
/// and `wasm_exceptions` are always on, and the on-disk cache is
/// engine-external, so none of those vary.
#[derive(Clone, Copy, PartialEq, Eq, Hash, Debug)]
struct GuestKey {
    /// SipHash-1-3 of the source bytes under a key drawn at random once per
    /// process (`HASH_KEY`), with their length. The bytes are host input — the
    /// host chooses which guest engine (or precompiled artifact) to load, and it
    /// is the *guest program* that is untrusted — so a 64-bit digest, qualified
    /// by an exact length, is enough to name a compilation. The secret key is
    /// defence in depth for a deployment that lets someone else supply guest
    /// bytes: without it a collision with a trusted guest could be prepared
    /// offline (the digest is not collision-resistant with a known key) and
    /// would then serve the wrong module to every later Runtime in the process;
    /// with it, the only route is a blind search against a 64-bit keyed
    /// function. Digesting every byte costs a linear pass over the guest on
    /// each construction, which is the price of an exact identity: it is ~2
    /// orders of magnitude below what it saves, and sampling the bytes instead
    /// would risk answering with the wrong module.
    source_hash: u64,
    source_len: usize,
    /// Whether those bytes are a precompiled artifact (`Module::deserialize`)
    /// rather than WebAssembly (`Module::new`). Part of the identity because it
    /// selects the loader, not merely the input: bytes that happened to hash
    /// alike under the two readings name different modules, and a Runtime must
    /// never be handed a compilation made by the other path.
    precompiled: bool,
    /// `fuel > 0` — whether `consume_fuel` instrumentation was compiled in. The
    /// budget itself is per `Store`.
    fuel: bool,
    /// `maxStack`, 0 meaning Wasmtime's default.
    max_stack: usize,
}

/// The per-process random key behind `GuestKey::source_hash` (see there).
static HASH_KEY: OnceLock<RandomState> = OnceLock::new();

fn guest_key(source: &[u8], precompiled: bool, fuel: bool, max_stack: usize) -> GuestKey {
    let mut hasher = HASH_KEY.get_or_init(RandomState::new).build_hasher();
    source.hash(&mut hasher);
    GuestKey {
        source_hash: hasher.finish(),
        source_len: source.len(),
        precompiled,
        fuel,
        max_stack,
    }
}

/// One compiled guest, shared by every `Runtime` with the same `GuestKey`. All
/// three handles are `Send + Sync` and clone as `Arc` bumps; all three are
/// immutable — no run-time state of any Runtime lives in here.
#[derive(Clone)]
struct Compiled {
    engine: Engine,
    /// Holds the `Module` too (`instance_pre.module()`), so the entry is all
    /// three shared handles.
    instance_pre: InstancePre<StoreState>,
}

/// A fixed-capacity map that evicts in insertion order (least recently inserted
/// first). Re-inserting an existing key refreshes its value without disturbing
/// the order, so a hot guest cannot be kept alive purely by lookups either —
/// the policy is deliberately trivial, and correctness never depends on a hit.
struct BoundedCache<V> {
    entries: HashMap<GuestKey, V>,
    inserted: VecDeque<GuestKey>,
    capacity: usize,
}

impl<V> BoundedCache<V> {
    fn new(capacity: usize) -> Self {
        Self {
            entries: HashMap::new(),
            inserted: VecDeque::new(),
            capacity,
        }
    }

    fn get(&self, key: &GuestKey) -> Option<&V> {
        self.entries.get(key)
    }

    fn insert(&mut self, key: GuestKey, value: V) {
        if self.entries.insert(key, value).is_none() {
            self.inserted.push_back(key);
        }
        while self.inserted.len() > self.capacity {
            if let Some(evicted) = self.inserted.pop_front() {
                self.entries.remove(&evicted);
            }
        }
    }
}

/// The process-wide compiled-guest cache. A `Mutex` (rather than a thread-local)
/// because a `static` must be `Sync` — it is uncontended under PHP NTS, and the
/// values it hands out are themselves `Send + Sync`, so nothing here assumes a
/// single thread.
static GUEST_CACHE: OnceLock<Mutex<BoundedCache<Compiled>>> = OnceLock::new();

/// The compiled guest for `key`, compiling it via `build` on a miss. The lock
/// is *not* held across the build: under PHP NTS there is no second thread to
/// deduplicate against, and a guard held across seconds of compilation would be
/// leaked -- not poisoned, leaked -- by any future PHP bailout inside it,
/// blocking every later construction in the process. Two threads (ZTS) racing
/// on one miss would merely both compile; the second insert replaces the first
/// with an equivalent value.
fn compiled_guest(
    key: GuestKey,
    build: impl FnOnce() -> PhpResult<Compiled>,
) -> PhpResult<Compiled> {
    let cache = GUEST_CACHE.get_or_init(|| Mutex::new(BoundedCache::new(GUEST_CACHE_CAPACITY)));
    // A cache holds no invariant a panic could break, so a poisoned lock is
    // simply taken (and a failed compile inserts nothing).
    let lock = || {
        cache
            .lock()
            .unwrap_or_else(|poisoned| poisoned.into_inner())
    };
    if let Some(hit) = lock().get(&key) {
        return Ok(hit.clone());
    }
    let compiled = build()?;
    lock().insert(key, compiled.clone());
    Ok(compiled)
}

/// The `Config` a guest is compiled — or deserialized — under. One helper for
/// both paths on purpose: a precompiled artifact is only loadable by an engine
/// whose configuration matches the one that produced it, so the two must not be
/// able to drift apart. Its arguments are exactly the engine-level options
/// `GuestKey` names.
///
/// Because the resulting `Engine` is shared between every Runtime with the same
/// key, any *resource* configured here is shared too. Nothing below allocates
/// per-engine resources today; a future setting that does (the pooling
/// instance allocator is the obvious one) would let one Runtime starve another
/// and must either be keyed or kept off.
fn engine_config(fuel: bool, max_stack: usize) -> Config {
    let mut config = Config::new();
    // The exceptions proposal: wasi-sdk's setjmp/longjmp lowering (used by
    // the PHP guest for zend_bailout) compiles to wasm try/throw.
    config.wasm_exceptions(true);
    // Cache compiled modules on disk so a heavy guest (e.g. a JS engine in
    // wasm) is compiled once and reused across instances and processes.
    if let Ok(cache) = wasmtime::Cache::from_file(None) {
        config.cache(Some(cache));
    }
    // A runtime constructed unbounded can still receive a timed call later.
    // Instrumentation must be enabled before compiling the module.
    config.epoch_interruption(true);
    if fuel {
        config.consume_fuel(true);
    }
    if max_stack > 0 {
        config.max_wasm_stack(max_stack);
    }
    config
}

fn engine_for(fuel: bool, max_stack: usize) -> PhpResult<Engine> {
    Engine::new(&engine_config(fuel, max_stack))
        .map_err(|e| PhpException::from_class::<TerrariumException>(format!("engine: {e:#}")))
}

/// Compile wasm bytes into a shareable `Engine`/`Module`/`InstancePre` under the
/// engine-level options that `GuestKey` names.
fn compile(source: &[u8], fuel: bool, max_stack: usize) -> PhpResult<Compiled> {
    let engine = engine_for(fuel, max_stack)?;
    let module = Module::new(&engine, source)
        .map_err(|e| PhpException::from_class::<TerrariumException>(format!("compile: {e:#}")))?;
    link(engine, module)
}

/// Load a precompiled artifact (`Runtime::precompile`) instead of compiling —
/// the same `Compiled`, without Cranelift.
///
/// `Module::deserialize` is `unsafe` for a reason that no check here removes:
/// the bytes *are* machine code, and Wasmtime only lightly validates their
/// framing. Substituted content is not caught, it simply runs — outside the
/// sandbox, with the host's full authority. An artifact is therefore trusted
/// exactly as the extension binary is: it must be produced by this build, in a
/// build pipeline, and shipped alongside it. It must never be guest input, user
/// upload, or anything else that crossed a trust boundary.
///
/// SAFETY: reachable only from `__construct` with an explicit `precompiled:
/// true` from the host — never inferred from the bytes — and only after
/// `Engine::detect_precompiled` has confirmed they carry Wasmtime's own
/// precompiled-module framing, so a mistaken argument (wasm, a text file,
/// arbitrary bytes) is refused before reaching this point rather than
/// deserialized. That framing is a few ELF header bytes and is forgeable at
/// will: it separates mistakes from artifacts, never attacks from artifacts. Beyond that framing check, the guarantee is the host's:
/// Wasmtime's own contract is that the bytes came unmodified from
/// `Engine::precompile_module`/`Module::serialize`. A *version* or `Config`
/// mismatch is not part of that trust — Wasmtime detects it deterministically
/// and it surfaces here as a `Terrarium\Exception`.
fn deserialize(source: &[u8], fuel: bool, max_stack: usize) -> PhpResult<Compiled> {
    let engine = engine_for(fuel, max_stack)?;
    let module = unsafe { Module::deserialize(&engine, source) }.map_err(|e| {
        PhpException::from_class::<TerrariumException>(format!(
            "precompiled: {e:#} (an artifact is only loadable by the extension build \
             that produced it, under the same fuel setting)"
        ))
    })?;
    link(engine, module)
}

/// Resolve a module's imports into the shareable `Compiled` both loaders return.
fn link(engine: Engine, module: Module) -> PhpResult<Compiled> {
    // Build the single `host_call` import once, then pre-resolve imports into an
    // `InstancePre` for cheap instantiation.
    let mut linker = build_linker(&engine)?;
    // Define any imports the guest declares but we don't provide as traps,
    // so guests that link extra runtime glue (e.g. a JS engine's unused
    // clock) instantiate fine and only fail if they actually call them.
    linker
        .define_unknown_imports_as_traps(&module)
        .map_err(|e| PhpException::from_class::<TerrariumException>(format!("link: {e:#}")))?;
    let instance_pre = linker
        .instantiate_pre(&module)
        .map_err(|e| PhpException::from_class::<TerrariumException>(format!("link: {e:#}")))?;

    Ok(Compiled {
        engine,
        instance_pre,
    })
}

/// Build a `Linker` providing the single `host_call` import. It captures nothing
/// Runtime-specific: the closure must be `Send + Sync + 'static`, and — because
/// the resulting `InstancePre` is shared process-wide between Runtimes over the
/// same guest — it must dispatch to the capability table of whichever Runtime is
/// calling. It therefore reads the bridge pointer out of the calling `Store`
/// (`caller.data()`), whose soundness argument is on `StoreState::bridge`.
fn build_linker(engine: &Engine) -> PhpResult<Linker<StoreState>> {
    let mut linker = Linker::new(engine);

    // WASI preview1, for guests built against a libc (e.g. QuickJS via the WASI
    // SDK). Capability-only guests import none of it; the defs are then unused.
    wasmtime_wasi::p1::add_to_linker_sync(&mut linker, |s: &mut StoreState| &mut s.wasi)
        .map_err(|e| PhpException::from_class::<TerrariumException>(format!("wasi: {e:#}")))?;

    linker
        .func_wrap(
            "host",
            "host_call",
            move |mut caller: Caller<'_, StoreState>,
                  name_ptr: i32,
                  name_len: i32,
                  args_ptr: i32,
                  args_len: i32|
                  -> Result<i64, wasmtime::Error> {
                // The Runtime whose `eval` is running, not the one that built
                // the (shared) linker.
                let BridgePtr(bridge) = caller.data().bridge;
                // SAFETY: single-threaded, and the pointee outlives this call;
                // see the `StoreState::bridge` doc comment.
                let state: &BridgeState = unsafe { &*bridge };

                let memory = caller
                    .get_export("memory")
                    .and_then(|e| e.into_memory())
                    .ok_or_else(|| wasmtime::Error::msg("guest has no exported memory"))?;

                // The guest controls these (ptr, len) pairs across the ABI.
                // Validate them against the live memory size *before* sizing a
                // host buffer: a negative length sign-extends to a ~16 EiB
                // `usize`, and even a large positive one forces a multi-GiB
                // allocation (or an OOM abort of the host) *ahead of* Wasmtime's
                // own read bounds-check. A bad value traps cleanly instead.
                let mem_size = memory.data_size(&caller);
                if name_ptr < 0 || name_len < 0 || args_ptr < 0 || args_len < 0 {
                    return Err(wasmtime::Error::msg(
                        "negative pointer or length from guest",
                    ));
                }
                range_in_bounds(name_ptr as usize, name_len as usize, mem_size)
                    .map_err(wasmtime::Error::msg)?;
                range_in_bounds(args_ptr as usize, args_len as usize, mem_size)
                    .map_err(wasmtime::Error::msg)?;

                let mut name_buf = vec![0u8; name_len as usize];
                memory.read(&caller, name_ptr as usize, &mut name_buf)?;
                let mut args_buf = vec![0u8; args_len as usize];
                memory.read(&caller, args_ptr as usize, &mut args_buf)?;

                let name = String::from_utf8(name_buf)
                    .map_err(|_| wasmtime::Error::msg("capability name is not UTF-8"))?;
                let args = decode_args(&args_buf).map_err(wasmtime::Error::msg)?;

                // Re-enters PHP. An unknown capability or a thrown PHP exception
                // becomes a trap here (caught + typed host-side).
                if expired(caller.data().deadline) {
                    return Err(Trap::Interrupt.into());
                }
                let result = state.host_call(&name, args).map_err(wasmtime::Error::msg)?;
                if expired(caller.data().deadline) {
                    return Err(Trap::Interrupt.into());
                }
                let out = result
                    .to_msgpack()
                    .map_err(|e| wasmtime::Error::msg(e.to_string()))?;

                // Hand the result back through guest-owned memory.
                let alloc = caller
                    .get_export("guest_alloc")
                    .and_then(|e| e.into_func())
                    .ok_or_else(|| wasmtime::Error::msg("guest has no guest_alloc"))?
                    .typed::<i32, i32>(&caller)?;
                let ptr = alloc.call(&mut caller, out.len() as i32)?;
                memory.write(&mut caller, ptr as usize, &out)?;
                Ok(pack(ptr, out.len()))
            },
        )
        .map_err(|e| PhpException::from_class::<TerrariumException>(format!("linker: {e:#}")))?;

    Ok(linker)
}

/// Reject a guest-supplied `(ptr, len)` that would fall outside the live linear
/// memory — checked *before* we size a host `Vec`, so a hostile or corrupt
/// length can't force a multi-gigabyte allocation (or an OOM abort of the host)
/// ahead of Wasmtime's own read bounds-check. Returns the reason on rejection.
fn range_in_bounds(ptr: usize, len: usize, mem_size: usize) -> Result<(), &'static str> {
    match ptr.checked_add(len) {
        Some(end) if end <= mem_size => Ok(()),
        Some(_) => Err("guest (ptr, len) is out of bounds"),
        None => Err("guest (ptr, len) overflows"),
    }
}

/// Pack a guest (pointer, length) pair into the ABI's return `i64`.
fn pack(ptr: i32, len: usize) -> i64 {
    ((ptr as u32 as i64) << 32) | (len as u32 as i64)
}

/// Unpack the ABI's `(ptr << 32) | len` return into a usable (offset, length).
fn unpack(packed: i64) -> (usize, usize) {
    let ptr = (packed >> 32) as u32 as usize;
    let len = (packed & 0xffff_ffff) as u32 as usize;
    (ptr, len)
}

fn no_export(name: &str) -> PhpException {
    PhpException::from_class::<TerrariumException>(format!("guest has no export '{name}'"))
}

/// Compose a `TerrariumGuestException` message from the guest's `$error` payload.
/// The payload is either a plain message string or a record `{message, type?,
/// line?}`; the result reads `Type: message (line N)` with whatever is present.
fn format_guest_error(detail: &MiddleValue) -> String {
    let fields = match detail {
        MiddleValue::Str(s) => return s.clone(),
        MiddleValue::Map(fields) => fields,
        other => return format!("{other:?}"),
    };
    let get = |key: &str| fields.iter().find(|(k, _)| k == key).map(|(_, v)| v);
    let message = match get("message") {
        Some(MiddleValue::Str(s)) => s.clone(),
        _ => "guest error".to_owned(),
    };
    let mut out = match get("type") {
        Some(MiddleValue::Str(t)) if !t.is_empty() => format!("{t}: {message}"),
        _ => message,
    };
    if let Some(MiddleValue::Int(line)) = get("line") {
        out.push_str(&format!(" (line {line})"));
    }
    out
}

/// Run a WASI reactor's `_initialize` (libc/global ctors) once after
/// instantiation, if the guest exports it. Capability-only guests don't.
fn initialize_reactor(store: &mut Store<StoreState>, instance: &Instance) -> PhpResult<()> {
    if let Ok(init) = instance.get_typed_func::<(), ()>(&mut *store, "_initialize") {
        init.call(&mut *store, ()).map_err(map_err)?;
    }
    Ok(())
}

/// Classify a Wasmtime execution error into the right typed PHP exception.
fn map_err(err: wasmtime::Error) -> PhpException {
    if let Some(trap) = err.downcast_ref::<Trap>() {
        let trap = *trap;
        if trap == Trap::Interrupt {
            return PhpException::from_class::<TerrariumTimeoutException>(
                "guest execution exceeded the time budget".to_owned(),
            );
        }
        let text = format!("{trap}");
        let lower = text.to_lowercase();
        if lower.contains("fuel") {
            return PhpException::from_class::<TerrariumTimeoutException>(
                "guest execution exhausted its fuel budget".to_owned(),
            );
        }
        if lower.contains("bounds") || lower.contains("memory") {
            return PhpException::from_class::<TerrariumMemoryException>(format!(
                "wasm trap: {text}"
            ));
        }
        return PhpException::from_class::<TerrariumTrapException>(format!("wasm trap: {text}"));
    }
    let msg = format!("{err:#}");
    if msg.to_lowercase().contains("memory") {
        return PhpException::from_class::<TerrariumMemoryException>(msg);
    }
    PhpException::from_class::<TerrariumException>(msg)
}

#[php_module]
pub fn module(module: ModuleBuilder) -> ModuleBuilder {
    module
        // Base exception first so the subclasses can resolve their parent CE.
        .class::<TerrariumException>()
        .class::<TerrariumTrapException>()
        .class::<TerrariumTimeoutException>()
        .class::<TerrariumMemoryException>()
        .class::<TerrariumGuestException>()
        .class::<Terrarium>()
}

#[cfg(test)]
mod tests {
    use super::*;

    fn keys_for(sources: usize) -> Vec<GuestKey> {
        (0..sources)
            .map(|n| guest_key(&[n as u8], false, false, 0))
            .collect()
    }

    #[test]
    fn identical_bytes_and_options_name_one_compilation() {
        // Two separately loaded copies of one guest must land on one entry.
        let loaded = b"\0asm\x01\0\0\0 a guest".to_vec();
        let reloaded = b"\0asm\x01\0\0\0 a guest".to_vec();
        assert_eq!(
            guest_key(&loaded, false, true, 4096),
            guest_key(&reloaded, false, true, 4096)
        );
    }

    #[test]
    fn every_engine_level_option_separates_the_key() {
        let source = b"\0asm\x01\0\0\0 a guest";
        let base = guest_key(source, false, false, 0);
        // Fuel instrumentation and the stack bound are compiled in, so they
        // cannot share a compilation. Different bytes never can either.
        assert_ne!(base, guest_key(source, false, true, 0));
        assert_ne!(base, guest_key(source, false, false, 1 << 20));
        assert_ne!(
            guest_key(source, false, true, 0),
            guest_key(source, false, true, 1 << 20)
        );
        assert_ne!(
            base,
            guest_key(b"\0asm\x01\0\0\0 another guest", false, false, 0)
        );
        // Lengths that differ only by a trailing byte still differ.
        assert_ne!(
            base,
            guest_key(b"\0asm\x01\0\0\0 a guest ", false, false, 0)
        );
    }

    #[test]
    fn a_portable_artifact_targets_the_baseline_and_loads_natively() {
        // What `precompile(portable: true)` produces must load through the
        // constructor's own (native) engine config on the machine that built
        // it; it does on any other host of the architecture too, because the
        // loader accepts an artifact whose enabled ISA features the host has,
        // and the baseline enables none beyond the architecture's own.
        // Pinning the target is what switches host feature detection off:
        // wherever this CPU has features beyond the baseline, the two engine
        // configurations must therefore differ (Wasmtime hashes the ISA flags
        // into its compatibility hash); on a baseline machine they coincide.
        let wat = br#"(module (func (export "f") (result i32) i32.const 42))"#;
        let mut pinned = engine_config(false, 0);
        pinned
            .target(&target_lexicon::Triple::host().to_string())
            .unwrap();
        let pinned = Engine::new(&pinned).unwrap();
        let native = Engine::new(&engine_config(false, 0)).unwrap();

        let portable = pinned.precompile_module(wat).unwrap();
        unsafe { Module::deserialize(&native, &portable) }
            .expect("portable artifact, native engine");

        let fingerprint = |engine: &Engine| {
            let mut hasher = std::hash::DefaultHasher::new();
            engine.precompile_compatibility_hash().hash(&mut hasher);
            hasher.finish()
        };
        let native_artifact = native.precompile_module(wat).unwrap();
        if fingerprint(&native) == fingerprint(&pinned) {
            assert_eq!(
                portable, native_artifact,
                "a baseline machine: the configs coincide"
            );
        } else {
            assert_ne!(
                portable, native_artifact,
                "pinning the target must change the ISA flags"
            );
        }
    }

    #[test]
    fn the_loader_is_part_of_the_identity() {
        // Bytes read as a precompiled artifact are a different module from the
        // same bytes read as WebAssembly, however they hash: one is native code
        // handed to `Module::deserialize`, the other wasm handed to
        // `Module::new`. Nothing may serve a cached compilation across that.
        let source = b"\0asm\x01\0\0\0 a guest";
        assert_ne!(
            guest_key(source, false, false, 0),
            guest_key(source, true, false, 0)
        );
        // And the artifact reading keeps every other distinction the wasm one
        // makes, so an artifact never borrows another's engine options either.
        let artifact = guest_key(source, true, false, 0);
        assert_ne!(artifact, guest_key(source, true, true, 0));
        assert_ne!(artifact, guest_key(source, true, false, 1 << 20));
        assert_eq!(artifact, guest_key(source, true, false, 0));
    }

    #[test]
    fn per_store_settings_are_absent_from_the_key() {
        // memoryLimit / timeoutMs / the fuel *amount* / isolated never reach
        // `guest_key`, which takes only what `Config` consumes.
        let source = b"\0asm\x01\0\0\0";
        assert_eq!(
            guest_key(source, false, true, 4096),
            guest_key(source, false, true, 4096)
        );
    }

    #[test]
    fn the_cache_evicts_the_least_recently_inserted() {
        let keys = keys_for(GUEST_CACHE_CAPACITY + 3);
        let mut cache = BoundedCache::new(GUEST_CACHE_CAPACITY);
        for (n, key) in keys.iter().enumerate() {
            cache.insert(*key, n);
        }
        assert_eq!(cache.entries.len(), GUEST_CACHE_CAPACITY);
        assert_eq!(cache.inserted.len(), GUEST_CACHE_CAPACITY);
        for (n, key) in keys.iter().enumerate() {
            if n < 3 {
                assert_eq!(cache.get(key), None, "entry {n} should have been evicted");
            } else {
                assert_eq!(cache.get(key), Some(&n));
            }
        }
    }

    #[test]
    fn re_inserting_a_key_replaces_it_without_growing_the_cache() {
        let keys = keys_for(2);
        let mut cache = BoundedCache::new(GUEST_CACHE_CAPACITY);
        for key in &keys {
            cache.insert(*key, 1);
        }
        cache.insert(keys[0], 2);
        assert_eq!(cache.get(&keys[0]), Some(&2));
        assert_eq!(cache.entries.len(), 2);
        assert_eq!(cache.inserted.len(), 2);
    }
}

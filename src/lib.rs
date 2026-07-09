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

#![allow(non_snake_case)]

use ext_php_rs::exception::PhpException;
use ext_php_rs::prelude::*;
use ext_php_rs::types::Zval;
use std::cell::RefCell;
use std::rc::Rc;
use std::sync::atomic::{AtomicBool, Ordering};
use std::sync::Arc;
use std::thread;
use std::time::{Duration, Instant};
use wasmtime::{
    Caller, Config, Engine, Instance, InstancePre, Linker, Module, Store, StoreLimits,
    StoreLimitsBuilder, Trap,
};

mod bridge;
mod exceptions;
mod handles;
mod marshal;

use bridge::{decode_args, BridgeState};
use exceptions::{
    TerrariumException, TerrariumGuestException, TerrariumMemoryException,
    TerrariumTimeoutException, TerrariumTrapException,
};
use marshal::{middle_to_zval, MiddleValue};

/// Per-`Store` data: the `StoreLimits` the `ResourceLimiter` hook reads, plus a
/// WASI context for guests that link a libc (capability-only guests never touch it).
struct StoreState {
    limits: StoreLimits,
    wasi: wasmtime_wasi::p1::WasiP1Ctx,
}

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
    engine: Engine,
    /// Pre-resolved imports for cheap (re)instantiation.
    instance_pre: InstancePre<StoreState>,
    state: Rc<BridgeState>,
    /// The persistent instance in shared mode; lazily created on first use.
    /// `None`/unused in isolated mode (a fresh instance is made per call).
    shared: RefCell<Option<Persistent>>,
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
    #[php(defaults(memoryLimit = None, timeoutMs = None, maxStack = None, fuel = None, isolated = false))]
    pub fn __construct(
        source: &Zval,
        memoryLimit: Option<i64>,
        timeoutMs: Option<i64>,
        maxStack: Option<i64>,
        fuel: Option<i64>,
        isolated: bool,
    ) -> PhpResult<Self> {
        // A PHP string is a byte string: take the raw `.wasm` bytes directly so
        // binary modules (not valid UTF-8) are accepted.
        let source = source
            .zend_str()
            .map(|s| s.as_bytes().to_vec())
            .ok_or_else(|| {
                PhpException::from_class::<TerrariumException>("source must be a string".to_owned())
            })?;

        let memory_limit = memoryLimit.unwrap_or(0).max(0) as usize;
        let timeout_ms = timeoutMs.unwrap_or(0).max(0) as u64;
        let max_stack = maxStack.unwrap_or(0).max(0) as usize;
        let fuel = fuel.unwrap_or(0).max(0) as u64;

        let mut config = Config::new();
        // The exceptions proposal: wasi-sdk's setjmp/longjmp lowering (used by
        // the PHP guest for zend_bailout) compiles to wasm try/throw.
        config.wasm_exceptions(true);
        // Cache compiled modules on disk so a heavy guest (e.g. a JS engine in
        // wasm) is compiled once and reused across instances and processes.
        if let Ok(cache) = wasmtime::Cache::from_file(None) {
            config.cache(Some(cache));
        }
        if timeout_ms > 0 {
            config.epoch_interruption(true);
        }
        if fuel > 0 {
            config.consume_fuel(true);
        }
        if max_stack > 0 {
            config.max_wasm_stack(max_stack);
        }

        let engine = Engine::new(&config).map_err(|e| {
            PhpException::from_class::<TerrariumException>(format!("engine: {e:#}"))
        })?;
        let module = Module::new(&engine, &source).map_err(|e| {
            PhpException::from_class::<TerrariumException>(format!("compile: {e:#}"))
        })?;

        // Build the bridge state and the single `host_call` import once, then
        // pre-resolve imports into an `InstancePre` for cheap instantiation.
        let state = BridgeState::new();
        let mut linker = build_linker(&engine, &state)?;
        // Define any imports the guest declares but we don't provide as traps,
        // so guests that link extra runtime glue (e.g. a JS engine's unused
        // clock) instantiate fine and only fail if they actually call them.
        linker
            .define_unknown_imports_as_traps(&module)
            .map_err(|e| PhpException::from_class::<TerrariumException>(format!("link: {e:#}")))?;
        let instance_pre = linker
            .instantiate_pre(&module)
            .map_err(|e| PhpException::from_class::<TerrariumException>(format!("link: {e:#}")))?;

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
    pub fn eval(&self, source: String) -> PhpResult<Zval> {
        // Each run captures its own output; a guest error leaves what was
        // printed before the crash readable via `output()`.
        self.state.clear_output();

        let middle = self.call_entry("eval", source)?;

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
    pub fn check(&self, source: String) -> PhpResult<Zval> {
        let middle = self.call_entry("check", source)?;

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
    /// Marshal `source` into guest memory, invoke the named `(i32, i32) -> i64`
    /// entrypoint (`eval`, or a guest's optional `check`), and decode the packed
    /// result — the shared byte-ABI round trip.
    fn call_entry(&self, entry: &'static str, source: String) -> PhpResult<MiddleValue> {
        let bytes = MiddleValue::Str(source)
            .to_msgpack()
            .map_err(|e| PhpException::from_class::<TerrariumException>(format!("encode: {e}")))?;

        self.with_instance(move |store, instance| {
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
            let ptr = alloc
                .call(&mut *store, bytes.len() as i32)
                .map_err(map_err)?;
            memory
                .write(&mut *store, ptr as usize, &bytes)
                .map_err(|e| map_err(e.into()))?;
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
        f: impl FnOnce(&mut Store<StoreState>, &Instance) -> PhpResult<R>,
    ) -> PhpResult<R> {
        if self.isolated {
            let mut store = self.fresh_store();
            // `fresh_store` pre-arms the epoch deadline, so instantiate and the
            // reactor's `_initialize` run without tripping. `arm` then sets this
            // call's fuel budget and re-arms the deadline just before `guarded`.
            let instance = self.instance_pre.instantiate(&mut store).map_err(map_err)?;
            initialize_reactor(&mut store, &instance)?;
            self.arm(&mut store)?;
            return self.guarded(|| f(&mut store, &instance));
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
        if slot.is_none() {
            let mut store = self.fresh_store();
            let instance = self.instance_pre.instantiate(&mut store).map_err(map_err)?;
            initialize_reactor(&mut store, &instance)?;
            *slot = Some(Persistent { store, instance });
        }
        let Persistent { store, instance } = slot.as_mut().unwrap();
        self.arm(store)?;
        let instance = *instance;
        let result = self.guarded(|| f(store, &instance));
        // A sandbox-level fault (trap, timeout, memory) poisons the instance --
        // guest state may be mid-mutation (e.g. an interrupted language-runtime
        // startup). Drop it so the next call instantiates fresh; guest-program
        // errors (the $error sentinel) return Ok and never take this path.
        if result.is_err() {
            *slot = None;
        }
        result
    }

    /// A fresh `Store` carrying this guest's memory limit. The epoch deadline is
    /// armed here, at creation, because `instantiate()` runs guest code (a wasm
    /// start function / global initializers) and a WASI reactor's `_initialize`
    /// *before* the per-call `arm()` — and with `epoch_interruption` enabled a
    /// store's default deadline is 0, which would trap that pre-call code instantly.
    /// The per-call fuel budget and epoch re-arm still happen in `arm()`.
    fn fresh_store(&self) -> Store<StoreState> {
        let limits = {
            let mut b = StoreLimitsBuilder::new();
            if self.memory_limit > 0 {
                b = b.memory_size(self.memory_limit);
            }
            b.build()
        };
        let wasi = wasmtime_wasi::WasiCtxBuilder::new().build_p1();
        let mut store = Store::new(&self.engine, StoreState { limits, wasi });
        store.limiter(|s| &mut s.limits);
        // Give setup (instantiate + `_initialize`) headroom before the per-call
        // budget is armed. Both are needed because that code runs *before* `arm()`
        // and a fresh store starts sealed: with `epoch_interruption` the default
        // deadline is 0 (traps at once), and with `consume_fuel` the fuel is 0
        // (out of fuel at once). Setup runs unmetered — the wall-clock timer only
        // starts in `guarded()` around the eval — so fuel setup is exempt too, for
        // symmetry; `arm()` then establishes this call's real budget for the eval.
        if self.timeout_ms > 0 {
            store.set_epoch_deadline(1);
        }
        if self.fuel > 0 {
            let _ = store.set_fuel(u64::MAX);
        }
        store
    }

    /// Arm this call's fuel budget and wall-clock deadline on `store`. Re-armed
    /// each call so a shared store gets a fresh budget every time.
    fn arm(&self, store: &mut Store<StoreState>) -> PhpResult<()> {
        if self.fuel > 0 {
            store.set_fuel(self.fuel).map_err(|e| {
                PhpException::from_class::<TerrariumException>(format!("fuel: {e:#}"))
            })?;
        }
        if self.timeout_ms > 0 {
            // Trap once the engine epoch advances one tick past now; the timer
            // thread provides that tick after the wall-clock budget elapses.
            store.set_epoch_deadline(1);
        }
        Ok(())
    }

    /// Run `f` under the wall-clock deadline: a timer thread bumps the engine
    /// epoch once the budget elapses, tripping any in-flight guest execution.
    fn guarded<R>(&self, f: impl FnOnce() -> R) -> R {
        if self.timeout_ms == 0 {
            return f();
        }
        let done = Arc::new(AtomicBool::new(false));
        let timer = {
            let engine = self.engine.clone();
            let done = Arc::clone(&done);
            let ms = self.timeout_ms;
            thread::spawn(move || {
                let deadline = Instant::now() + Duration::from_millis(ms);
                while !done.load(Ordering::Relaxed) {
                    if Instant::now() >= deadline {
                        engine.increment_epoch();
                    }
                    thread::sleep(Duration::from_millis(1));
                }
            })
        };
        let r = f();
        done.store(true, Ordering::Relaxed);
        let _ = timer.join();
        r
    }
}

/// Build a `Linker` providing the single `host_call` import. The closure must be
/// `Send + Sync + 'static`, so it captures only the address of the bridge state
/// and reconstructs the reference inside; this is sound because PHP is
/// single-threaded (NTS) and the guest runs on the PHP thread, so the state is
/// never touched concurrently and outlives the linker (both live on the `Terrarium`).
fn build_linker(engine: &Engine, state: &Rc<BridgeState>) -> PhpResult<Linker<StoreState>> {
    let mut linker = Linker::new(engine);

    // WASI preview1, for guests built against a libc (e.g. QuickJS via the WASI
    // SDK). Capability-only guests import none of it; the defs are then unused.
    wasmtime_wasi::p1::add_to_linker_sync(&mut linker, |s: &mut StoreState| &mut s.wasi)
        .map_err(|e| PhpException::from_class::<TerrariumException>(format!("wasi: {e:#}")))?;

    let state_addr = Rc::as_ptr(state) as usize;

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
                // SAFETY: single-threaded; see the doc comment above.
                let state: &BridgeState = unsafe { &*(state_addr as *const BridgeState) };

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
                let result = state.host_call(&name, args).map_err(wasmtime::Error::msg)?;
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

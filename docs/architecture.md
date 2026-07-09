# Architecture

This is the technical design of **Terrarium** — the rationale, the trust model,
the ABI, the type story, and the decisions behind them. For a high-level overview
and a quickstart, see the [README](../README.md).

**What it is:** a PHP extension for **running guest-language code sandboxed, with
defined access to host PHP behaviour**. You write capabilities as ordinary PHP
functions; untrusted JavaScript, TypeScript, Python, or PHP runs sandboxed and
calls them **by name** as a typed API — with **WebAssembly as a hard, in-process
isolation boundary**. TypeScript is type-checked *inside the sandbox* against
the `.d.ts` generated from the registered SDK, before any guest code executes.

The mechanism is a general one — the extension embeds a **WebAssembly runtime**,
so the guest is just a `.wasm` (a language engine — QuickJS, Boa, RustPython,
php-src — compiled to wasm). But the *product* is the guest-language sandbox with
a PHP-defined, typed capability SDK, not a generic wasm runner.

It is a **single, uniform API**: one public `Terrarium` class. You `register()` PHP
closures, `eval()` guest source, read what it printed with `output()`, and ask
for `types('dts'|'pyi'|'php')`. The guest's language is chosen purely by which
`*_guest.wasm` you load — nothing else changes. There is no low-level / legacy
surface beneath it.

---

## 1. The idea in one paragraph

The host extension embeds a WebAssembly runtime; the guest is a *real language
engine* compiled to wasm, running **inside the sandbox**. PHP registers a flat,
allowlisted SDK — that allowlist is the entire trust boundary; the guest reaches
only what's registered. Values cross as serialized bytes; live objects cross as
opaque handles; resource limits contain abuse — and because the boundary is
enforced by the VM in-process, even a memory-corruption bug in the guest's own
engine cannot reach the host. The guest can be any language that targets WASM.
On top of that, the SDK's types are **inferred from the PHP function signatures**
and emitted per guest language (`.d.ts`, `.pyi`, `.php`) so guest authors get
typed access to the SDK.

---

## 2. Why WebAssembly

### 2.1 Embedding an interpreter natively cannot contain the interpreter

The classic way to run guest code from a host language is to embed the guest's
engine natively (a C interpreter linked into the process). A capability model
then contains *what the guest can reach*, and resource limits contain *abuse* —
but a memory-corruption bug in the embedded engine itself is **not** contained:
it is native code in your process, and an engine bug becomes host RCE. The usual
mitigation is an outer sandbox (gVisor, a microVM, a container) around the whole
process — heavy machinery that reintroduces exactly the ops burden an in-process
sandbox was supposed to avoid.

A WASM runtime closes that gap **in-process**. The guest runs in a linear-memory
sandbox: it cannot form a pointer outside its own memory, cannot call a syscall
you didn't import, cannot reach the host heap. A bug *inside* the guest — even
inside a C language runtime compiled to WASM — stays inside the sandbox. The
capability story sits on top; the memory-safety story is the VM's.

### 2.2 The guest is language-agnostic

Once the host speaks "WASM" instead of any particular engine's API, the guest
can be:

- **JavaScript** — QuickJS-ng or Boa compiled to wasm (both bundled), or any JS
  engine with a WASM build.
- **TypeScript** — the TypeScript compiler is itself pure JavaScript, so it runs
  *inside* a JS engine guest (bundled: QuickJS-ng + tsc as QuickJS bytecode —
  checked against the registered SDK, stripped, run; see §13).
- **PHP** — real php-src compiled to WASM → run PHP-in-PHP (bundled; the
  `guests/php/` build cross-compiles php-src with the WASI SDK — see §13. The
  WordPress Playground `php-wasm` builds are an unrelated, Emscripten-based
  project — and part of why this one is named Terrarium.)
- **Python** — RustPython (bundled), or CPython→WASI.
- **Ruby** — the official `ruby.wasm` (WASI), or mruby.
- **Lua / Go / Rust / C / AssemblyScript / Zig** — anything that produces a
  `.wasm` meeting the tiny guest contract (§6).

The host extension never changes; the guest brings its own language.

---

## 3. Core concepts at a glance

| Concern | Mechanism |
|---|---|
| Guest state & limits | Wasmtime `Engine` + `Store` |
| Unit of isolation | Wasmtime `Instance` (its own linear `Memory`) |
| Host access (the only door) | one import: `host_call(namePtr, nameLen, argsPtr, argsLen)` |
| Trust boundary | flat dispatch table (`HashMap<String, Zval>`) populated by `register()` |
| SDK surface in the guest | per-engine prelude installs the registered top-level names as globals (no synthetic root) |
| Value transport | `MiddleValue` ⇄ msgpack bytes ⇄ PHP zval, over linear memory |
| Live host objects | host-side handle table → opaque `i64` (`grant/resolve/revoke`) |
| Memory limit | `StoreLimits` — VM-enforced; `memory.grow` fails |
| CPU / time limit | **epoch interruption** (wall-clock) or **fuel** (deterministic) |
| Stack limit | `Config::max_wasm_stack` |
| Execution modes | shared persistent instance vs fresh per call (`InstancePre`) |
| Faults | typed `Terrarium\Exception` family (§11) |

---

## 4. Architecture

```
┌─ PHP (trusted, full Zend) ─┐   ┌─ Rust extension ──────┐   ┌─ WASM guest (untrusted) ─┐
│ $w->register('db.query',…) │   │  owns Wasmtime Engine  │   │  linear memory (sandbox)  │
│ $w->grant($pdo)            │◄─►│  Store + Instance      │◄─►│  exports: alloc, eval     │
│ $w->eval($source)          │   │  host_call dispatch    │   │  imports: host_call, …    │
└────────────────────────────┘   └────────────────────────┘   └───────────────────────────┘
        ext-php-rs (zval ↔ Rust)      wasmtime (Rust ↔ wasm)        SDK-globals prelude (per engine)
```

**One process, one thread.** The extension is a Rust `cdylib` PHP loads natively.
The public surface is the `Terrarium` facade (`lib/Terrarium.php`), which wraps
the engine primitive registered as `Terrarium\Runtime`; both it and the
`Terrarium\Exception` family are real classes (the engine primitive and
exceptions implemented in Rust via `ext-php-rs`, the facade in PHP).

The single design principle: **the namespacing is cosmetic; the trust boundary
is a flat dispatch table reached through one host import.** Everything a guest
can ever do funnels through that one door, and the far side of the door is a
WASM linear memory the host only ever copies bytes across.

---

## 5. Runtime choice: Wasmtime

**[Wasmtime](https://wasmtime.dev/)** via the `wasmtime` Rust crate.

Why it's the natural fit:

- **Same stack.** It's a Rust crate that embeds cleanly into the `ext-php-rs`
  `cdylib` — no new language, no FFI gymnastics.
- **First-class resource limits** — `StoreLimits`/`ResourceLimiter` (memory &
  table caps), **fuel** (deterministic instruction metering), **epoch
  interruption** (cheap wall-clock deadlines), `max_wasm_stack`. These map
  one-to-one onto `memoryLimit` / `timeoutMs` / `maxStack`.
- **WASI Preview 1** (`wasmtime-wasi`) — lets libc-based guests (e.g. QuickJS or
  PHP from the WASI SDK) link a working clock/random; capability-only guests
  import none of it.
- **Fast instantiation** — compile a `Module` once, then `InstancePre`
  to instantiate cheaply per call; cache AOT artifacts with
  `Module::serialize`/`deserialize`. This is what makes "isolated mode" cheap.
- **No JIT required if you don't want it** — the Pulley interpreter or Winch
  baseline compiler exist for environments that forbid W^X (e.g. some serverless
  configs). Good for an AWS Lambda / Bref deployment story.

Alternatives considered: **Wasmer** and **WAMR** (tiny, great for embedding, but
C-native). Wasmtime's resource-limit primitives and Rust-first embedding made it
the fit.

---

## 6. The host ABI

The guest needs (a) a way to *call into* the host SDK, and (b) a way for values
to cross. We use a single, byte-oriented ABI — WASM is *natively* byte-oriented
(a guest can only really hand the host a (pointer, length) into its linear
memory), so bytes are the honest common denominator across every guest language.

**Host imports** (what the extension provides to every guest):

```
host_call(name_ptr: i32, name_len: i32, args_ptr: i32, args_len: i32) -> i64
```

- The guest msgpack-encodes its argument array into its own linear memory and
  passes `(args_ptr, args_len)` plus the dotted capability name.
- The host reads those bytes out of the guest `Memory`, decodes to
  `Vec<MiddleValue>`, looks the name up in the **dispatch table** (rejects if
  unregistered — *this is the trust boundary*), converts `MiddleValue → zval`,
  and calls the PHP callable via `ZendCallable`.
- The result is encoded back to msgpack. To return bytes *into* the guest the host
  calls a guest-exported allocator and writes there; the packed return `i64`
  carries `(ret_ptr << 32) | ret_len`. (This is the standard "canonical realloc"
  trick — the same shape `cabi_realloc` uses.)

**Guest contract** (what every guest must export — tiny):

```
memory                       ; the linear memory, exported
guest_alloc(len: i32) -> i32 ; bump/realloc area for host→guest writes
eval(ptr: i32, len: i32) -> i64  ; run source msgpack at (ptr,len); return packed
```

One **optional** export extends it: `check(ptr, len) -> i64` — same byte ABI,
but the guest statically validates the source *without running it* and returns
a msgpack **array** of diagnostics (`{message, type?, line?}`; empty = passed).
Surfaced as `Terrarium::check()`. Every bundled guest implements it at the
depth its language allows: the TypeScript guest runs a full type-check against
the SDK; the JS, Python, and PHP guests report syntax/compile errors (PHP's is
literally `php -l` over the same wrapped form `eval` executes). Nothing
executes, no capability can fire, and the output buffer is untouched. A guest
without the export raises a `Terrarium\Exception`.

A small **prelude** baked into each engine guest hides all of this: at startup it
queries the host for the registered top-level names (the reserved `$names`
capability) and installs each as a global — a recursive proxy where
`<dotted.name>(...)` internally encodes the args, calls `host_call`, and decodes
the result. There is **no synthetic root**: a capability registered as
`user.fetch` is reached directly as `user.fetch(...)` (or `$user->fetch(...)` in
a PHP guest — each prelude speaks its language's idiom). A guest program error
comes back as the sentinel map `{"$error": "<message>"}`, which the host raises
as a typed `Terrarium\GuestException` (§11).

This works with *any* WASM producer the moment its guest exports the three
symbols above. Type richness beyond bytes is handled **on the PHP side** — types
are inferred from the registered closures (Reflection + PHPDoc) and emitted per
language as `.d.ts`/`.pyi`/`.php` (§13), so the ABI stays simple while the guest
author still gets a typed SDK.

Capabilities take and return **data** — scalars, arrays, maps, and handles.
Functions do not cross the boundary: there is no shared object graph between
host and guest, so a closure cannot be passed as a capability argument or
returned as a result. This keeps the ABI one call shape with no callback
re-entrancy to reason about.

---

## 7. Value marshaling & memory management

A neutral middle representation decouples the guest's value model from PHP's:

```
guest value  ──►  MiddleValue  ──►  PHP zval
                      │
                msgpack bytes   (carried over linear memory)
```

The `MiddleValue` table (null/bool/int/float/str/bytes/array/map) is engine-
agnostic — nothing about it is specific to any guest language. The buffer
ownership rules are strict:

- **Host → guest:** host asks the guest to `guest_alloc(n)`, writes `n` bytes
  there, hands the guest `(ptr, len)`. The guest owns and frees it.
- **Guest → host:** guest writes into its own memory, passes `(ptr, len)`; the host
  *copies out* immediately (the bytes may be reclaimed after the call returns).
  Host never retains a raw guest pointer across calls.

Memory is **enforced by the VM**, not advisory: `StoreLimits` caps how large the
linear memory may grow, so an alloc bomb in the guest fails to `memory.grow`
instead of OOM-ing the host.

One precision caveat: WASM itself has true `i64`, but JavaScript engines
represent numbers as doubles, so integers beyond 2^53 lose precision inside a JS
guest — a property of the guest language, not the bridge. Non-JS guests are
unaffected.

---

## 8. Capability handles (live host objects)

`grant($pdo)` stores the live PHP object in a host-side table and returns an
opaque `i64`. The guest can do nothing with that integer but pass it back to a
capability, which `resolve($id)`s the live object host-side. The integer is inert
in the sandbox — it's not even a pointer into anything the guest can reach; the
object never crosses the boundary. `revoke` drops it from the table.

Granted objects are refcount-bumped to survive PHP GC while held.

---

## 9. Isolation & resource limits

| Concern | Enforcement |
|---|---|
| Memory | `StoreLimits` caps linear-memory growth — `memory.grow` fails; VM-enforced, not advisory |
| CPU / time | **epoch interruption** (cheap wall-clock) or **fuel** (deterministic, reproducible) |
| Stack | `Config::max_wasm_stack` |
| Ambient authority | **none unless imported** — give zero WASI and the guest has *no* syscalls, clocks, or I/O |
| Engine bugs | **contained by the sandbox** — a memory-corruption bug inside the guest's language engine stays inside its linear memory |
| Determinism | optional: fuel + no wall-clock + no WASI = reproducible execution |

Two capabilities worth calling out:

1. **No ambient authority by default.** A bare WASM instance with only the
   `host_call` import literally cannot do anything but compute and call back.
   That's a stronger default than any natively-embedded engine, where the guest
   starts with the engine's full stdlib. WASI (clocks, random, preopened dirs)
   is opt-in, per instance.

2. **Deterministic execution** is available (fuel metering + denying
   nondeterministic imports). Useful for reproducible/auditable guest runs.

The honest residual: a bug in *Wasmtime itself* is in the trust base. But that's
a small, Rust, memory-safe, heavily-fuzzed surface — a far better bet than
trusting every bundled language engine's C codebase.

---

## 10. Execution modes

> The user-facing treatment — the comparison table, `reset()`, fault recovery,
> and how to choose — is in **[execution-modes.md](execution-modes.md)**. This
> section is the design rationale.

- **Shared (default):** one `Store` + `Instance` for the object's life, reused
  across calls. Cheaper (no re-instantiation) and it keeps engine-internal warm
  state — for the TypeScript guest, the persistent compiler context.
- **Isolated:** a fresh `Store`/`Instance` per call, discarded after. The
  `Module` is compiled once and instantiated cheaply via `InstancePre` — this is
  the part Wasmtime makes *fast*. A guaranteed-fresh linear memory each call.
- **Strongest:** a fresh `Engine`/`Store` per tenant → separate everything.

One caveat worth stating precisely: the bundled guests each run an `eval` in a
**fresh language runtime** (a new JS runtime / Python interpreter per call), so
guest *program* globals do **not** accumulate across evals in either mode — the
guests are hermetic per eval by design. What the mode controls is the *instance*
around that runtime: shared reuses the linear memory (keeping engine-internal
warm state, e.g. the TS compiler); isolated drops it per call. State that lives
*host-side* (dispatch table, handle table) is unaffected by either.

---

## 11. Errors and output

> The exception family, the accessors, and the caller's view are in
> **[errors.md](errors.md)**. This section is the design rationale.

Two failure layers, cleanly separated, plus a captured output channel — the three
things a caller (a test runner, a REPL, an agent loop) needs back from a run.

**Sandbox-level faults** surface as Wasmtime `Trap`s/errors and map to a typed PHP
exception family: `Terrarium\TrapException` (unreachable, OOB access),
`Terrarium\TimeoutException` (epoch deadline or fuel exhausted), `Terrarium\MemoryException`
(`memory.grow` denied / a memory bound). The engine aborts the call; the instance
is poisoned, and the runtime recovers by dropping it — the next call instantiates
fresh (in isolated mode it was fresh anyway, so recovery is *free*).

**Guest-program errors** (a thrown JS exception, a Python traceback, an uncaught
PHP exception) are *not* traps: the guest catches them and returns the sentinel
value `{ "$error": {message, type?, line?} }`. The host recognises that exact
shape in `eval` and raises **`Terrarium\GuestException`** with a composed message
`Type: message (line N)` — QuickJS, RustPython, and the PHP guest report the
source line; Boa reports type + message (its public API exposes no reliable
span). All four exception classes extend `Terrarium\Exception`, so a single
`catch (Terrarium\Exception)` covers both layers:

```
\Exception
  └─ Terrarium\Exception
       ├─ Terrarium\TrapException      ┐
       ├─ Terrarium\TimeoutException   │ sandbox-level (the engine aborted the call)
       ├─ Terrarium\MemoryException    ┘
       └─ Terrarium\GuestException       guest program raised (returned the sentinel)
```

**Output** (`console.log` / `print` / `echo`) is the third channel. Each guest
prelude routes it through the reserved `$out` capability — intercepted
host-side, *before* the dispatch-table lookup, into a per-`eval` buffer (objects
are `JSON`/`str`-formatted). It is separate from the return value: read it with
`output()` after the call. The buffer is cleared at the *start* of each `eval` and
survives a throw, so anything printed before a crash is still readable — exactly
what a fix-up loop needs.

All of this reuses the *one* `host_call` import and the *one* `eval` return
path — `$out` is a reserved name, `$error` is a reserved result shape, `$names`
is queried once at startup to learn which top-level globals to install (there is
no synthetic root), and `$dts` serves the SDK's generated `.d.ts` to the
type-aware TypeScript guest. No new wasm import, no ABI change; the guest stays
dumb and all policy lives host-side.

---

## 12. What the WASM boundary trades

**Gain**
- In-process memory-corruption isolation — no outer container/microVM needed
  for memory safety.
- Language-agnostic guests; one host, many runtimes.
- VM-enforced memory limits; deterministic metering (fuel) available.
- Zero ambient authority by default; opt-in WASI.
- Reusable compiled modules + cheap instantiation (fast isolated mode).
- Typed SDK for the guest author — `.d.ts`/`.pyi`/`.php` inferred from the PHP
  signatures (Reflection + PHPDoc), so guest code is type-checked against the SDK.

**Costs**
- No shared object graph — capabilities exchange data (and handles), not
  functions (§6).
- Per-call serialization into linear memory has higher baseline overhead than an
  in-process engine value (still fast; just not free).
- Guest artifacts are larger (a whole language runtime in WASM) and have a
  cold-start cost — mitigated with on-disk module caching (`wasmtime::Cache`) +
  `InstancePre`, and for a guest that bootstraps heavy state (the TypeScript
  compiler + lib parse) with **build-time pre-initialization** via Wizer, which
  bakes the warmed heap into the module so first eval — and every fresh isolated
  instance — starts warm (§13). The cost it trades back is a larger fixture.
- Two sandboxes to reason about (WASM boundary + the guest runtime's own model).

---

## 13. Status — what's built

Everything below is implemented and covered by the test suites (`make test`).
Each engine's build pipeline, toolchain, and upstream shims live in its own
[`guests/<name>/README.md`](../guests); this section is the cross-cutting summary.

1. **The engine primitive.** `ext-php-rs` cdylib embedding Wasmtime: load a
   `.wasm`, run its `eval` entrypoint, return to PHP. `memoryLimit`
   (`StoreLimits`), `timeoutMs` (epoch interruption), `maxStack`, and `fuel` all
   wired; the typed exception family classifies every fault, and a faulted
   shared instance is dropped so the next call starts fresh.
2. **The capability bridge.** `MiddleValue`/msgpack marshaling over linear
   memory. `host_call` import + the guest allocator contract. `register()` + the
   flat dispatch table (the trust boundary). `grant/resolve/revoke` handles,
   including re-entrant `resolve()` from inside a `host_call`. `register()`
   validates names: every dotted segment must be an identifier (which also
   rejects the bridge-reserved `$`-names), prelude-machinery names (`__host`, …)
   are rejected, and shadowing a guest builtin (`console`, `print`, `Math`, …)
   warns — with no synthetic root, top-level names become guest globals.
3. **Execution modes.** Shared (persistent `Store`/`Instance`) vs isolated (fresh
   per call from a pre-compiled `InstancePre`), plus `reset()` and on-disk module
   caching (`wasmtime::Cache`) so a heavy guest compiles once.
4. **Real engine guests — five of them.** `eval(source)` returns a value and the
   guest reaches the host via a by-name prelude over `host_call` (registered
   top-level names installed as globals; no synthetic root). Each engine runs
   *inside* wasm, so an engine bug cannot reach the host — no container required.
   Each guest pins the upstream version it tracks (its `build.sh` or
   `Cargo.toml` is the source of truth); the committed fixtures are built from
   these pins.
   - **Boa** `0.20` (pure-Rust JS) on `wasm32-unknown-unknown`, no C toolchain.
   - **QuickJS-ng** `v0.15.1` — JavaScript, compiled from C with the WASI SDK
     (reactor mode) behind the identical host ABI.
   - **RustPython** `0.5` (pure-Rust Python) on `wasm32-unknown-unknown`.
   - **PHP** `8.3.14` — real php-src via its embed SAPI (`php_embed_init` /
     `zend_eval_stringl`), cross-compiled with the WASI SDK: **sandboxed PHP
     inside PHP** (part of why the project is named Terrarium). The build
     (`guests/php/`) carries a set of `wasi-shims/` headers + weak stubs for the
     POSIX surface php-src assumes but wasi-libc omits or hides behind wasip1
     guards (users/groups, sockets, syslog, dl, ucontext); every stub fails
     cleanly (`ENOSYS`-style) and a sandboxed SDK-calling guest never exercises
     them. `zend_bailout`'s setjmp/longjmp is lowered by LLVM
     (`-mllvm -wasm-enable-sjlj`, linked against wasi-libc's `-lsetjmp`) with
     `-wasm-use-legacy-eh=false` so the emitted exception handling is the
     *standardized* encoding — the one Wasmtime executes; the host enables it
     with `Config::wasm_exceptions(true)`. The guest reaches the SDK as proxy
     objects (`$user->fetch(42)`, `$api->v1->hello(...)`, invokable `$ping()`);
     the source runs inside an IIFE (`extract($GLOBALS)` in scope) so a
     top-level `return` is the eval result; `echo`/`print` is captured via a
     SAPI `ub_write` hook into `$out`; uncaught exceptions *and* fatal errors
     (`zend_first_try`/`zend_catch`) become the `$error` sentinel. Fibers
     compile but abort if used (real fibers need Asyncify).
   - **TypeScript** — QuickJS-ng `v0.15.1` with the real TypeScript compiler
     `5.7.3` (and Bloomberg's ts-blank-space `0.9.0`) embedded as
     **precompiled QuickJS bytecode**
     (a *native* `qjsc` from the same pinned quickjs-ng tree generates it —
     bytecode is version-locked but architecture-portable). Two contexts, one
     rule: a persistent "compiler" context per instance (tsc + the non-DOM
     `lib.es2020` chain + the driver), and a fresh "user" context per eval,
     identical to the plain QuickJS guest. The compiler context is
     **pre-initialized with [Wizer](https://github.com/bytecodealliance/wizer)**
     at build time (`build.sh` step 6, via the self-contained `wizen/` tool):
     the ~500 ms a cold context otherwise pays — not loading tsc, but
     `ts.createProgram` *parsing and binding the ~88 lib `.d.ts` files* on the
     first check — is run once, offline, and the warmed heap is snapshotted into
     the module's data segments. So the SDK-independent lib parse/bind is baked
     in: every eval starts warm, dropping first eval **~500 ms → ~20 ms (~25×)**
     and — because the win is per fresh instance, not amortized across a shared
     one — making **isolated mode just as fast** (~23 ms/call vs ~500 ms
     before); warm shared evals stay ~5 ms. The trade is fixture size
     (~6 MB → ~28 MB: the baked compiler heap becomes data segments) — but it
     stays a portable `.wasm`, not a Wasmtime-version-locked precompiled
     artifact, so any host loads it. Wizer strips both its `wizer.initialize`
     entrypoint and the reactor's `_initialize` from the snapshot, so the host
     instantiates it directly with no re-initialization and no host-side change. Each eval fetches the SDK's `.d.ts`
     (reserved `$dts` cap) and **type-checks the source against it** — the type
     environment *is* the capability environment (no DOM lib, no Node lib,
     `console` declared because the prelude provides it). Check failures return
     the `$error` sentinel as `TS<code>` with the exact source line, before any
     guest code runs; `// @ts-nocheck` (TypeScript's own pragma, leading
     comments only) skips the check. Types are then erased
     whitespace-preserving, so the executed JS is positionally identical to the
     submitted TS — runtime error lines stay exact. Non-erasable syntax (enums,
     namespaces) is a clear error.

   Five guests, four languages, one host extension and bridge (its only
   guest-motivated change: the `wasm_exceptions` engine flag) →
   **language-agnosticism proven.** `wasmtime-wasi` (preview1) lets libc-based
   guests (QuickJS, PHP) link a working clock/random; capability-only guests
   import none of it.
5. **Typed SDK for the guest author.** Types are inferred from the registered
   closures (Reflection + a PHPDoc recursive-descent parser, incl. nullable,
   unions, `int[]`, and nested `array{…}` object shapes) and emitted as `.d.ts`
   (TypeScript), `.pyi` (Python `TypedDict`s), and a `.php` stub (namespace
   classes + `\Closure`-typed variables, shapes in docblocks) via
   `types('dts'|'pyi'|'php')`. A capability's docblock summary becomes a JSDoc
   comment / `#` line / docblock line in the output.
6. **Output and structured errors.** `console.log`/`print`/`echo` is captured to
   a per-`eval` buffer (`output()`); guest-program errors raise
   `Terrarium\GuestException` with a `Type: message (line N)` message (§11).
7. **The single uniform API.** One public `Terrarium` class
   (`register`/`eval`/`check`/`output`/`types`/`grant`/`resolve`/`revoke`/
   `manifest`/`reset`) over the `Terrarium\Runtime` engine primitive — no
   low-level/legacy surface; the typed-author experience is delivered by
   PHP-side inference over the one byte ABI (§6).
8. **Static validation without execution.** `check(source)` returns every
   diagnostic as data (`{message, type?, line?}`; `[]` = passed) via the
   optional `check` guest export (§6) — all five guests implement it: a full
   type-check against the SDK in the TypeScript guest, a syntax/compile check
   in the JS, Python, and PHP guests. An explicit check ignores `@ts-nocheck`.

---

## 14. Open decisions

- **Fuel vs epoch interruption?** Epoch = cheap wall-clock (what `timeoutMs`
  uses); fuel = deterministic/reproducible but higher overhead. Both are wired;
  pick per use case.
- **WASI level:** none (pure capability) / Preview 1. Default is effectively
  *none* — the WASI context is built sealed (no fs/net/env/stdio); only libc
  guests touch p1's clock/random. Keep WASI opt-in and minimal.
- **How are guests distributed?** Bundle curated `.wasm` (quickjs, python, php, …)
  with releases, or let users supply their own? Probably both.
- **Where the consumer (e.g. an agent) lives:** out of scope by design. This
  library is a general execution primitive — sandbox + typed SDK + faithful
  feedback (result / output / errors / types). Deciding *which* capabilities to
  register, presenting the types to a model, and driving a run→fix loop are a
  *consumer* concern built on top, not part of Terrarium. The library exposes the
  raw material (`manifest()`, `types()` with descriptions); a discovery/search
  layer over a large SDK belongs in that outer layer.

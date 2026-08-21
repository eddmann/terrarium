# TypeScript guest — checked inside the sandbox

[QuickJS-ng](https://github.com/quickjs-ng/quickjs) `v0.16.2` carrying the real
[TypeScript compiler](https://github.com/microsoft/TypeScript) `6.0.3` (and
Bloomberg's [ts-blank-space](https://github.com/bloomberg/ts-blank-space) `0.9.0`)
embedded as **precompiled QuickJS bytecode**. Every eval is type-checked against
the `.d.ts` generated from your registered SDK — the type environment *is* the
capability environment — then erased and run.

## Build

```sh
make typescript-guest      # WASI_SDK=/path/to/wasi-sdk   (and cargo, for Wizer)
```

`build.sh` is a six-step pipeline:

1. fetch quickjs-ng (`v0.16.2` — same pin as the [QuickJS guest](../quickjs/README.md);
   bytecode is version-locked)
2. build a **native** `qjsc` from that tree
3. fetch the pinned `typescript` + `ts-blank-space` npm tarballs
4. generate the JS payloads (the lib map, the shimmed ts-blank-space) and compile
   each to bytecode with the native `qjsc`
5. compile `ts_guest.c` + the bytecode arrays + quickjs into a base wasm
   (12 MiB linker stack — the checker recurses deeply)
6. **pre-initialize with [Wizer](https://github.com/bytecodealliance/wizer)** (see
   below), producing `tests/wasm/typescript_guest.wasm`

### Reproducible builds

The fixture is **byte-for-byte reproducible** on the pinned toolchain — the same
inputs always produce the same `typescript_guest.wasm`. To check, drop the
intermediates and rebuild (keeping the fetched sources and the native `qjsc`):

```sh
cd guests/typescript
rm -f build/*_bc.c build/tsblank.js build/libs.js build/*.wasm
./build.sh && sha256sum ../../tests/wasm/typescript_guest.wasm
```

Three things hold that up:

- **The WASI SDK is pinned.** `WASI_SDK_VERSION` (default `25.0`) and
  `WASI_SDK_CLANG_VERSION` (default `19.1.5`) are asserted against
  `$WASI_SDK/VERSION` and `clang --version` before anything is compiled; a
  mismatch aborts, naming the release to install. Codegen differs between clang
  releases, so an unpinned compiler quietly breaks byte-stability.
- **Wizer runs against a deterministic WASI** — see below.
- **Payload generation is order-stable** (the lib map comes from a sorted
  directory listing).

Two smaller build-environment notes: the native `qjsc` is compiled with
`-D_GNU_SOURCE`, which glibc requires for the `environ` declaration
`quickjs-libc.c` reaches for; and the quickjs-ng fetch falls back from the
GitHub archive tarball to `git clone --depth 1 --branch $QJS_VERSION`, since
proxies commonly 403 the codeload redirect while allowing git over HTTPS.

## Two contexts, one rule

- A persistent **compiler** context (tsc + the non-DOM `lib.es2020` chain + the
  driver in `driver.js`) — created once per instance; lib parses and `Program`
  state amortize across evals.
- A fresh **user** context per eval — identical to the plain QuickJS guest.

Each eval fetches the SDK `.d.ts` (reserved `$dts` capability) and type-checks the
source against it. A failure returns the `$error` sentinel as `TS<code>` with the
exact source line, **before any guest code runs**. `// @ts-nocheck` (TypeScript's
own pragma, leading comments only) skips the check. Types are then erased
whitespace-preserving, so runtime error lines match the TS you submitted, and the
JS runs in the user context. `check()` runs the full type-check and returns *every*
diagnostic as data.

## Wizer pre-initialization

The ~500 ms a cold compiler context otherwise pays — `ts.createProgram` parsing
and binding the ~88 lib `.d.ts` files on the first check — is run once at build
time (`wizer.initialize` in `ts_guest.c` calls the bring-up plus one warm-up
check) and the warmed heap is snapshotted into the module's data segments by the
self-contained `wizen/` tool.

The effect: `ensure_compiler()` is a no-op at runtime, so first eval drops
**~500 ms → ~20 ms** and — because the win is per fresh instance, not amortized
across a shared one — [isolated mode](../../docs/execution-modes.md) is just as
fast (~23 ms/call). The trade is fixture size (~6 MB → ~28 MB: the baked compiler
heap becomes data segments), but it stays a portable `.wasm`, not a
Wasmtime-version-locked artifact. Wizer strips both its `wizer.initialize`
entrypoint and the reactor's `_initialize` from the snapshot, so the host
instantiates it directly with no re-init and no host-side change.

**Determinism.** `wizen/` does *not* use Wizer's `allow_wasi(true)`; it installs
the five WASI preview1 functions the guest actually imports (`clock_time_get`,
`fd_close`, `fd_fdstat_get`, `fd_seek`, `fd_write` — there is no `random_get`,
filesystem, env or args) through Wizer's `make_linker` hook, with the clocks
pinned to a fixed epoch advanced a fixed step per read. Without that the
snapshot is not reproducible: QuickJS seeds every context's PRNG from the wall
clock (`js_random_init` → `js__gettimeofday_us()`), so the live seed lands in the
baked data segments and two wizenings of the same base module differ.

That constrains only what the *snapshot* bakes, never runtime behaviour — the
host instantiates the finished module against its own real WASI, so `Date.now()`
and `Math.random()` in user code read the real clock as usual. What persists is a
fixed PRNG seed inside the pre-baked *compiler* context, which exists only to
parse, check and type-erase source.

## Build internals & upstream shims

- **`typescript.js`** is a CommonJS bundle — the compiler context is given
  `module` / `exports` and a stub `process` before its bytecode is evaluated.
- **ts-blank-space** ships ESM importing `"typescript"` and `"./blank-string.js"`;
  `build.sh` rewrites those imports to the globals the compiler context provides.
- **libs** are the `lib.*.d.ts` chain minus the environments the sandbox doesn't
  have (`dom`, `webworker`, `scripthost`) — the type environment must equal the
  real execution environment.
- The checker is pinned to **`target: ES2020` / `lib: ["lib.es2020.d.ts"]`**
  (`driver.js`), which is deliberately narrower than the engine. QuickJS-ng runs
  well ahead of that — `Object.groupBy`, `Iterator` helpers, `RegExp.escape`,
  `Float16Array` and friends all exist at runtime — but the checker rejects them,
  so an engine bump does **not** widen what submitted TypeScript may use. Anything
  past ES2020 is reachable only via `@ts-nocheck`. Widening the surface is a
  separate, deliberate change: raise the `target`/`lib` pin in `driver.js` (and
  the `.d.ts` the runtime is described by) rather than expecting a QuickJS-ng
  upgrade to do it.

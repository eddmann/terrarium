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
diagnostic as data; `analyze()` runs the same pass and adds the extracted
type-argument schemas (below).

## Asynchrony: refused up front, and caught at run time

The sandbox has no event loop — nothing drains the job queue, so a program that
suspends never resumes. This guest closes that from both ends.

**`sync_only`, opt-in, compile time.** The host's compile options arrive per
eval/check through the reserved **`$opts`** capability (the same shape as
`$dts`), as an open map the driver reads the keys it understands from. With
`sync_only`, `driver.js` walks the parsed `/main.ts` with `forEachChild` — syntax
only, so it costs one parse rather than a `Program` — and reports every:

| Construct | Detected as |
|---|---|
| `async` function / expression / arrow / class method / object-literal method | an `AsyncKeyword` in the node's modifiers |
| `await`, including top-level `await` | `AwaitExpression` |
| `for await (… of …)` | `ForOfStatement` with an `awaitModifier` |
| `function*`, `*method()` | an `asteriskToken` on a function/method node |
| `yield` | `YieldExpression` |

Each becomes a **`TSSyncOnly`** diagnostic with the source line and a message
that names the synchronous alternative. Because it is an AST walk, prose is
safe — `"we await your reply"` in a string, a comment about async pipelines, a
property named `async` — which a text-level `\b(async|await)\b` ban is not.

**The pragma asymmetry is deliberate:** `// @ts-nocheck` skips the *type* check,
which is an author's preference about their own annotations; `sync_only` is a
capability the host does not have, so the walk runs regardless of the pragma.
`eval` gates on the first violation (counting the rest, like type errors);
`check()` returns them all, ahead of the type diagnostics.

**`AsyncIncomplete`, always on, run time.** Independently of the option, an eval
that yields a `Promise` (in any state) or leaves jobs queued comes back as the
`$error` sentinel typed `AsyncIncomplete` — see the
[QuickJS guest](../quickjs/README.md#no-event-loop-asynchronous-code-fails-loudly),
which uses the identical check. That covers what the option cannot: `@ts-nocheck`
source, or a promise chain built without `async`/`await` at all.

## Type argument → JSON Schema

**`type_argument_schemas`, opt-in, static.** Given a list of callee names in the
same `$opts` map, the driver derives a JSON Schema from the single type argument
of every call to them, and returns the results from the guest's second static
export, **`analyze`** (`{diagnostics, schemas}`), alongside the diagnostics
`check` already returned. `check`'s own array shape never changes.

```ts
const audit = ctx.agent<{ verdicts: { id: string; judgment: "pass" | "fail" }[] }>({ … });
```

```php
$ts = new Terrarium($wasm, typeArgumentSchemas: ['ctx.model', 'ctx.agent']);
$ts->analyze($source)['schemas'];
// [['ordinal' => 0, 'callee' => 'ctx.agent', 'line' => 1, 'schema' => '{"type":"object",…}']]
```

This is the inverse of schema-first authoring: the author writes the *type*, the
host derives the contract. The full accepted/refused matrix lives in
[docs/api.md](../../docs/api.md#type-argument-schemas); the parts that are
properties of *this* implementation:

- **The walk is structural, not textual.** A callee matches by its identifier
  chain (`ctx` `.` `agent`), never by `getText()`, so spacing, line wrapping and
  interleaved comments cannot make or break a match. Only calls carrying exactly
  one type argument match; a call without one is left alone entirely.
- **Identity is the call ordinal** — the 0-based index among matched calls in
  source order (collected then sorted by start position, so it is a property of
  the text rather than of the traversal). A line:column would be repointed by
  every reformat; the ordinal is not. A call whose type argument has no schema
  form still consumes its ordinal, yielding a `TSSchemaError` diagnostic that
  carries that ordinal in the data (and a line, for the human) instead of a
  schema — so one bad call cannot renumber its neighbours.
- **`line` rides alongside it as the runtime bridge** — the 1-based line of the
  call's start, computed exactly as the diagnostics compute theirs, so a
  refusal and the entry it displaced agree. Entries keep their start-position
  order, so `line` is non-decreasing and two matched calls on one line share it
  without complaint; policing that is the consumer's business. It sits beside
  `schema`, never within it, so the hashable schema bytes never move when a
  call does.
- **The JSON is emitted as text**, key by key, rather than `JSON.stringify`'d
  from an object: property order then follows declaration order exactly, and
  integer-like property names (`{ "2": string; "10": string }`) cannot be
  hoisted by the engine's own key ordering. The same source always yields
  byte-identical schema bytes, which is what makes them safe to bake and hash.
- **Refusals name the member path** (`verdicts[].judgment: function types cannot
  be expressed as JSON Schema`) and are total: anything the host's type-level
  reader could not turn back into the author's type is refused rather than
  approximated. Recursive types are detected by an identity stack and refused by
  naming the cycle, never by looping.
- **Extraction never gates execution.** It runs on the `check`/`analyze` path
  only; `eval` is untouched.

`tools/dev-driver.mjs` runs this exact `driver.js` under plain Node against the
same pinned `typescript` package, so the serializer can be iterated in
milliseconds instead of per 28 MB fixture rebuild:

```sh
cd guests/typescript
node tools/dev-driver.mjs analyze path/to/source.ts ctx.model ctx.agent
```

It is a development aid with no part in the build; the authoritative
expectations are the golden matrix in `tests/php/12_typescript_schemas.php`.

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

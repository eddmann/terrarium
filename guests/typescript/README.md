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

Five things hold that up, and every one is enforced by the build rather than
merely intended:

- **The WASI SDK is pinned.** `WASI_SDK_VERSION` (default `25.0`) and
  `WASI_SDK_CLANG_VERSION` (default `19.1.5`) are asserted against
  `$WASI_SDK/VERSION` and `clang --version` before anything is compiled; a
  mismatch aborts, naming the release to install. Codegen differs between clang
  releases, so an unpinned compiler quietly breaks byte-stability.
- **Every fetched source is checksum-verified**, and a mismatch is a hard
  failure. The pins live in [`guests/pinned-sources.sh`](../pinned-sources.sh).
  The `typescript` and `ts-blank-space` npm tarballs are pinned by sha256 (an
  npm `.tgz` is content-addressed and immutable). quickjs-ng is pinned by a
  digest over the *extracted tree* — a sorted manifest of the sha256 of every
  top-level `.c`/`.h`, which is exactly the set the build compiles — because a
  **generated** GitHub archive tarball is not guaranteed byte-stable and pinning
  its hash would pin the wrong thing. The git-clone fallback additionally
  asserts the tag resolves to the pinned commit before `.git` is dropped, so
  that path is verified twice, independently.
- **The build's own tool is locked.** `wizen/Cargo.lock` is committed (the
  repository `.gitignore` carries an explicit exception for it), so the Wizer
  step resolves the same dependency graph everywhere.
- **Wizer runs against a deterministic WASI** — see below.
- **Payload generation is order-stable** (the lib map comes from a sorted
  directory listing and is serialised with sorted keys).

What is *not* claimed: that a different clang, a different Rust toolchain, or a
moved upstream tag reproduces the committed bytes. The claim is that the pinned
inputs are verified to be the pinned inputs, and that on the pinned toolchain
the output is stable — which is why each of those checks aborts the build rather
than printing a warning.

Two smaller build-environment notes: the native `qjsc` is compiled with
`-D_GNU_SOURCE`, which glibc requires for the `environ` declaration
`quickjs-libc.c` reaches for; and the quickjs-ng fetch falls back from the
GitHub archive tarball to `git clone --depth 1 --branch $QJS_VERSION`, since
proxies commonly 403 the codeload redirect while allowing git over HTTPS.

## Two contexts, one rule

- A persistent **compiler** context (tsc + the non-DOM `lib.es2024` chain + the
  driver in `driver.js`) — created once per instance; lib parses and `Program`
  state amortize across evals. The last `Program` and its checker are reused
  when the source and SDK declaration text match exactly, including a shared
  `check()` followed by `eval()`. Constraints and schema extraction still use
  each call's current options; changed text rebuilds the `Program`. A *new*
  source against unchanged declarations reuses the parsed SDK `.d.ts` too —
  `createProgram` only carries a file over when the host hands back the same
  `SourceFile`, so without that the whole SDK was re-parsed and re-bound per
  check, at a cost linear in its size.
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

**Promises, not just keywords.** A promise settles by running a job, and no job
ever runs, so `sync_only` refuses the whole family — and it does so through the
checker rather than by spelling:

| Construct | Detected as |
|---|---|
| `new Promise(…)`, `Promise.resolve(…)`, `Promise` in a type | an identifier resolving to the **global** `Promise`/`PromiseLike` declaration (a class of your own named `Promise` is a different symbol, and is fine) |
| any expression whose type is a promise — including `db.query()` on a capability declared to return one | the checker's type at that node, named `Promise`/`PromiseLike` or with a `then` that hands back one |
| `.then` / `.catch` / `.finally` on such an expression | a call through that member, with the receiver's type checked |

Each is a `TSSyncOnly` diagnostic at the **outermost** promise-typed node, so a
chain reports once rather than once per sub-expression. An object with an
ordinary `then(cb)` method of its own is *not* a promise: a thenable's `then`
returns another thenable, and that one returns `void`.

This closes the hole `AsyncIncomplete` cannot: `new Promise(() => {}); p.then(f)`
with a plain result queues no job at all, so the run-time guards see nothing to
complain about.

**The `@ts-nocheck` compile path is conservative, deliberately.** No Program is
built there, so the promise rules match on shape alone: `new Promise`, the
identifier `Promise`, and a call through a member named `then`/`catch`/`finally`.
The last over-matches a user object's own `.then`. That is an accepted trade in
an opt-in strict mode: `sync_only` + `@ts-nocheck` is a caller asking for the
strict environment and then declining the type information that makes the check
exact. Dropping the pragma restores precise matching, and `check()`/`analyze()`
always build a Program, so they are never conservative.

**`AsyncIncomplete`, always on, run time.** Independently of the option, an eval
that yields a `Promise` (in any state), leaves jobs queued, or registered any
promise reaction comes back as the `$error` sentinel typed `AsyncIncomplete` —
see the
[QuickJS guest](../quickjs/README.md#no-event-loop-asynchronous-code-fails-loudly),
which uses the identical check and sets out both the soundness argument and the
one residual gap. That covers what the option cannot: `@ts-nocheck` source, a
promise chain built without `async`/`await` at all, and hosts that never set the
option.

## Engine-unsupported syntax

**Always on, no option.** The checker's library describes a language; the engine
implements one. Where they disagree about what can even be *parsed*, a clean
`check()` would otherwise mean nothing — the code publishes and then dies with a
`SyntaxError` inside the sandbox. `driver.js` refuses those constructs as
**`TSEngineUnsupported`** diagnostics, with the line and the alternative:

| Construct | Why |
|---|---|
| `accessor` class members (`class C { accessor x = 1 }`) | quickjs-ng `v0.16.2` raises a `SyntaxError` on the keyword, and ts-blank-space emits it verbatim (it is not a type) |

The list is meant to grow and shrink. To add a case, extend
`engineUnsupportedAt()` in `driver.js` and add a probe to
`tests/php/11_es_surface.php` proving the engine really rejects it; to retire one
after a quickjs-ng bump implements it, delete the case and flip that probe from
"absent" to "implemented". `tools/lib-audit/audit.php --parity` is what catches a
construct that *should* be on the list: it fails whenever a probe passes
`check()` and then throws at `eval()`.

Like `sync_only`, this is not skipped by `// @ts-nocheck` — it states a fact
about the engine, not a preference about the source.

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

- **Matching is semantic, not textual.** Each configured name is resolved once
  per program to the declarations its calls must land on, and a call matches
  when `getResolvedSignature()` points at one of them. A name is not an
  identity: comparing the callee's *text* silently missed `(ctx.model)<T>()`,
  `ctx!.model<T>()`, `ctx["model"]<T>()` and `const m = ctx.model; m<T>()` — no
  schema and no diagnostic, so a program published clean and failed on its first
  run — while *matching* a locally shadowed `ctx.agent`, which is a different
  function that happens to share a spelling, baking a wrong schema and shifting
  every ordinal after it. Letting the checker resolve the call fixes both at
  once, and keeps reformatting inert for free. A call without type arguments is
  left alone entirely (schema-first authoring stays legal); a matched call
  carrying more than one is a loud `TSSchemaError`, never a silent skip.
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
  have — the type environment must equal the real execution environment. Three
  exclusions, at two granularities:
  - `dom`, `webworker`, `scripthost` are not bundled at all;
  - `*.intl.d.ts` and `*.sharedmemory.d.ts` are bundled but served **empty**,
    which keeps the `/// <reference lib=…>` graph resolvable while declaring
    nothing (there is no `Intl` and no `Atomics` in this engine);
  - `lib.es5.d.ts` carries its own `declare namespace Intl`, which no per-file
    rule can reach, so `build.sh` emits a second entry — `lib.es5.no-intl.d.ts`
    — with the namespace's three **value** declarations (`var Collator`,
    `var NumberFormat`, `var DateTimeFormat`) removed and every interface kept.
    `driver.js` serves that in place of the original. `Intl` therefore survives
    as a *type-only* namespace: `new Intl.NumberFormat()` is a check error
    rather than clean code that dies at run time, while
    `(1).toLocaleString("en", opts)` and `"a".localeCompare(…)` — which do exist,
    locale-blind — keep the `Intl.*Options` types their signatures reference.
    Deleting the namespace outright would have broken those; keeping the values
    would have kept lying. `tests/php/11_es_surface.php` holds both halves.
- The checker is pinned to **`target: ES2024` / `lib: ["lib.es2024.d.ts"]`**
  (`driver.js`), which is still deliberately narrower than the engine.
  QuickJS-ng runs ahead of it — `Array.fromAsync`, `using` declarations and
  `Symbol.dispose` all exist at runtime — but the checker rejects them, so an
  engine bump does **not** silently widen what submitted TypeScript may use.
  Anything past the pin is reachable only via `@ts-nocheck`. Widening the
  surface is a separate, deliberate change: raise the `target`/`lib` pin in
  `driver.js` with the audit in `tools/lib-audit/` as the evidence, rather than
  expecting a QuickJS-ng upgrade to do it.

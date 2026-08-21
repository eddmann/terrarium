# QuickJS guest — JavaScript

[QuickJS-ng](https://github.com/quickjs-ng/quickjs) `v0.16.2`, compiled from C to
`wasm32-wasip1` (reactor mode) via the WASI SDK. This is the **reference guest**:
the smallest complete implementation of the [guest contract](../../docs/architecture.md#6-the-host-abi),
and the base the PHP and TypeScript guests build on.

## Build

```sh
make quickjs-guest      # WASI_SDK=/path/to/wasi-sdk
```

`build.sh` downloads the pinned quickjs-ng source (`v0.16.2`, not vendored),
compiles `quickjs_guest.c` + the engine (`quickjs.c`, `libregexp.c`,
`libunicode.c`, `dtoa.c`) with a 1 MiB linker stack, and copies the result to
`tests/wasm/quickjs_guest.wasm` (the committed fixture). Needs a
[WASI SDK](https://github.com/WebAssembly/wasi-sdk); nothing at runtime.

## The guest contract

Exports `memory`, `guest_alloc(len)`, `eval(ptr,len)`, and `check(ptr,len)`;
imports one host function, `host_call`. Values cross as msgpack over linear
memory. A small prelude (`quickjs_guest.c`) installs the registered capability
names as JS globals (no synthetic root — `user.fetch(...)`), and routes
`console.log`/`error`/`warn`/… through the reserved `$out` capability into
`output()`.

`check()` here is a **parse check**: it compiles the source without running it and
returns any `SyntaxError` as a diagnostic (`[]` = parses).

## No event loop: asynchronous code fails loudly

Nothing calls `JS_ExecutePendingJob`, so the job (microtask) queue is never
drained and a program that suspends never resumes. Rather than half-run it in
silence, `eval` asks three questions before marshaling the result, and returns
the `$error` sentinel typed **`AsyncIncomplete`** when any of them says yes:

- **the result is a `Promise`** (`JS_IsPromise`), in *any* state — an
  already-fulfilled promise still failed, because `.then` callbacks are queued
  rather than called, so the chain's continuations never ran;
- **jobs are queued** (`JS_IsJobPending`) — the program returned a plain value
  but left work behind it;
- **a promise reaction was registered** — any `.then` / `.catch` / `.finally`
  during the eval, counted by an instrument the prelude installs ahead of user
  code.

### Why the third check exists, and why it is sound

The first two missed a whole shape, which is how it was reported:

```js
const p = new Promise(() => {});
p.then(() => mark());
42
```

The result is `42`, not a promise. And **no job is queued**: a reaction on a
*pending* promise is stored on the promise and becomes a job only when it
settles, which this one never does. Both guards passed, `eval` returned 42, and
`mark()` was abandoned without a word — precisely the failure the guards exist
to eliminate.

QuickJS's public C API cannot see it: there is no way to enumerate a pending
promise's reactions. So the count is made in JS, by wrapping
`Promise.prototype.then` in the prelude. `catch` and `finally` are specified in
terms of `then`, and quickjs-ng implements them that way, so the one wrapper
also covers `Promise.all` / `race` / `any` / `allSettled`.

A non-zero count is a **sound** verdict, not a heuristic. Under a queue that is
never drained, a reaction registered on an already-settled promise becomes a job
that never runs (which check 2 catches anyway), and a reaction registered on a
pending promise never becomes anything at all. Either way the callback provably
did not run — so *any* reaction registered during an eval is abandoned work, and
nothing is ever failed for work that actually happened, because under these
rules no such work can exist.

**The residual gap, stated rather than papered over.** `await` does not go
through `Promise.prototype.then` — the engine uses an internal path — so a
fire-and-forget async function suspended on a promise that never settles is
invisible to all three checks:

```js
(async () => { await new Promise(() => {}); mark(); })();   // result discarded
42                                                          // eval returns 42
```

Nothing in the engine's public API can detect that, and this guest does not
pretend otherwise. The TypeScript guest's `sync_only` rejects it at compile
time; here it is a documented limitation.

All three checks are on by default and not configurable: they report facts about
the environment. Whatever the program printed first is preserved in `output()`,
exactly as for a thrown exception. The `sync_only` compile option (reserved
`$opts` capability) is *accepted* here but not implemented — there is no
compiler to enforce it against; see the
[TypeScript guest](../typescript/README.md), which rejects both the syntax and
every use of a promise up front.

## Notes

- The pin is shared with the [TypeScript guest](../typescript/README.md): its
  embedded compiler is QuickJS **bytecode**, which is version-locked, so both must
  track the same quickjs-ng tree.
- Errors carry the source line, parsed from the engine's own stack.

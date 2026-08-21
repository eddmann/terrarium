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
silence, `eval` inspects the result before marshaling it and returns the
`$error` sentinel typed **`AsyncIncomplete`** when either holds:

- **the result is a `Promise`** (`JS_IsPromise`), in *any* state — an
  already-fulfilled promise still failed, because `.then` callbacks are queued
  rather than called, so the chain's continuations never ran;
- **jobs are queued** (`JS_IsJobPending`) — the program returned a plain value
  but left work behind it.

This is on by default and not configurable: it reports a fact about the
environment. Whatever the program printed first is preserved in `output()`,
exactly as for a thrown exception. The `sync_only` compile option (reserved
`$opts` capability) is *accepted* here but not implemented — there is no
compiler to enforce it against; see the
[TypeScript guest](../typescript/README.md), which rejects the syntax up front.

## Notes

- The pin is shared with the [TypeScript guest](../typescript/README.md): its
  embedded compiler is QuickJS **bytecode**, which is version-locked, so both must
  track the same quickjs-ng tree.
- Errors carry the source line, parsed from the engine's own stack.

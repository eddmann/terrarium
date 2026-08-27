# Errors

A run gives back three independent things: the **result** (`eval`'s return
value), the **output** (`output()`), and — on failure — a typed **exception**.
Failures come in two cleanly separated layers, and the guest's own program errors
surface as real PHP exceptions located at the original source line.

## Exception hierarchy

```
\Exception
  └─ Terrarium\Exception              (base for everything the extension throws)
       ├─ Terrarium\TrapException     (the guest trapped: unreachable, bad indirect call, OOB)
       ├─ Terrarium\TimeoutException  (the wall-clock deadline or fuel budget tripped)
       ├─ Terrarium\MemoryException   (a linear-memory bound was hit)
       └─ Terrarium\GuestException    (the guest program itself raised an error)
```

All four extend `Terrarium\Exception`, so a single `catch` covers every failure —
or catch a leaf to be specific:

```php
try {
    $result = $t->eval($source);
} catch (Terrarium\TimeoutException $e) {
    // infinite loop / over budget
} catch (Terrarium\GuestException $e) {
    // the guest program raised (a JS throw, a Python traceback, a failed type-check)
} catch (Terrarium\Exception $e) {
    // anything else the sandbox produced
}
```

An explicit per-call timeout can also expire during Wasm start or `_initialize`.
The partial instance is discarded, and the same Runtime can be retried with a
new budget. Timeouts use the existing `TimeoutException`; check/analyze still
return diagnostics as data for source errors, but a sandbox timeout is an
exception. See [timeout scope and callback limitations](api.md#per-call-timeouts).

Invalid per-call timeout arguments raise the base exception before touching
output. Output printed before a timeout remains readable, just as after a guest
error. Check/analyze/reset never clear output. A timeout does not undo PHP
callback side effects, and cannot interrupt PHP while it is blocked.

## The two layers

**Sandbox-level faults** are the engine aborting the call — a Wasmtime trap or a
tripped limit. They map to `Terrarium\TrapException` /
`Terrarium\TimeoutException` / `Terrarium\MemoryException`. The instance is poisoned
and dropped; the runtime recovers on the next call (see
[execution modes](execution-modes.md#fault-recovery)). These fire from the
interrupt handler / allocator, so they carry no source location.

**Guest-program errors** are *not* traps: the guest catches its own language-level
error and returns a sentinel value the host recognizes,
`{ "$error": {message, type?, line?} }`. The host raises it as a
**`Terrarium\GuestException`** with a composed message:

```php
$ts->register('user.fetch',
    /** @return array{name: string, roles: string[]} */
    fn (int $id): array => ['name' => 'Ada', 'roles' => ['admin']]);

try {
    // Type-checks fine — roles[9] is typed `string`; it's only undefined at runtime.
    $ts->eval("const u = user.fetch(42);\nu.roles[9].toUpperCase();");
} catch (Terrarium\GuestException $e) {
    echo $e->getMessage();   // "TypeError: cannot read property 'toUpperCase' of undefined (line 2)"
}
```

The message reads `Type: message (line N)` — the guest error's type, its text,
and the **source line**. Because a type-aware guest erases types
whitespace-preserving (see below), that line points at the source you submitted,
not at some transformed intermediate.

## Guest error types

The `type` in `Type: message (line N)` is the guest's own error name where the
language has one (`TypeError`, `SyntaxError`, a Python exception class). Two
types are Terrarium's own, and are the ones worth matching on:

| Type | Raised by | When |
|---|---|---|
| `TS<code>` | TypeScript guest | a type diagnostic — the compiler's own code (`TS2345`, `TS2322`, …) |
| `TSSyntaxError` | TypeScript guest | syntax that cannot be type-erased (`enum`, `namespace`, …) |
| `TSSyncOnly` | TypeScript guest, with `syncOnly: true` | `async`, `await`, `for await`, `function*`, `yield`, and every use of a promise — rejected before anything runs |
| `TSEngineUnsupported` | TypeScript guest, **always** | syntax the sandbox engine cannot parse though the checker accepts it (`accessor` class members) |
| `TSSchemaError` | TypeScript guest, with `typeArgumentSchemas:` | a matched call's type argument has no JSON Schema form — a **static** diagnostic only, carrying the call `ordinal` and the offending member path |
| `AsyncIncomplete` | QuickJS + TypeScript guests, **always** | the eval produced a `Promise`, left jobs queued, or registered a promise reaction: nothing drains the job queue, so it can never finish |

`AsyncIncomplete` is the loud version of a failure that used to be silent. The
sandbox has no event loop, so an async program half-ran and looked fine:

```php
try {
    $js->eval('(async () => { console.log("step 1"); await 1; save(); })()');
} catch (Terrarium\GuestException $e) {
    echo $e->getMessage();   // "AsyncIncomplete: asynchronous guest code cannot complete: …"
    echo $js->output();      // "step 1"   — and save() never ran
}
```

### Exactly what `AsyncIncomplete` catches, and what it does not

Three independent checks run after every JS/TS eval, in this order. Each names
itself in the message:

1. **the result is a `Promise`**, in any state (`JS_IsPromise`);
2. **jobs are queued** at the end of the eval (`JS_IsJobPending`) — which is
   what a settled promise's reactions become;
3. **any promise reaction was registered** during the eval — a
   `.then`/`.catch`/`.finally`, counted by an instrument the guest prelude
   installs before user code runs.

Check 3 exists because 1 and 2 together miss a whole class:
`const p = new Promise(() => {}); p.then(f); 42`. The result is `42` and *no job
is queued* — a reaction on a promise that never settles is stored on the
promise and only becomes a job when it settles. Both of the first two checks
pass and `f` is abandoned in silence. The engine's public C API cannot enumerate
a pending promise's reactions, so the count is made in JS instead; it is a sound
verdict on its own, because under a queue that is never drained *no* reaction
ever runs, whether it became a job or not.

**The documented limitation.** `await` does not go through
`Promise.prototype.then` — the engine uses an internal path — so a fire-and-forget
async function suspended on a promise that never settles is still invisible to
all three checks:

```js
(async () => { await new Promise(() => {}); save(); })();   // result discarded
42                                                          // eval returns 42
```

Nothing in the public QuickJS API can see that, and Terrarium does not pretend
otherwise. The TypeScript guest's [`syncOnly: true`](api.md#synchronous-only-guests)
rejects it at compile time; on the plain JavaScript guest it remains undetected.

`TSSyncOnly` is the same hazard caught a step earlier, at compile time, when the
host opts in. Both are `Terrarium\GuestException`s; neither poisons the instance.

`TSEngineUnsupported` is a different kind of honesty: the checker's library
declares more than the engine implements, and where the engine cannot even
*parse* something the checker accepts, refusing at check time is the only way a
clean `check()` keeps meaning "this will run". It is always on and is not
skipped by `// @ts-nocheck` — it states a fact about the engine, not a
preference about the source.

## Static validation vs. runtime errors

`check()` is the other side of this: it returns diagnostics as **data**, never
as exceptions, and never runs the guest.

```php
$ts->check('user.fetch("42")');
// [['message' => "Argument of type 'string' is not assignable ...", 'type' => 'TS2345', 'line' => 1]]
```

`TSSchemaError` lives only here: it is produced by
[type-argument extraction](api.md#type-argument-schemas), which runs on the
`check()`/`analyze()` path and never gates `eval()`, so it is a diagnostic and
never an exception.

Use `check()` to lint/validate; let `eval()` throw for errors that only appear at
run time. A failed **type-check** inside `eval()` (TypeScript guest) still raises
`Terrarium\GuestException` — with the `TS<code>` as the type — *before any guest
code executes*.

## Source lines stay exact

The TypeScript guest erases types with
[ts-blank-space](https://github.com/bloomberg/ts-blank-space), which replaces type
syntax with whitespace of the same width. The executed JavaScript is therefore
**positionally identical** to the TypeScript you submitted, so a runtime error's
`(line N)` matches your source exactly — no source-map indirection required.

Per-guest reporting depth:

| Guest | Line in errors | Notes |
|---|---|---|
| QuickJS, RustPython, PHP | yes | parsed from the engine's own stack/trace |
| TypeScript | yes | exact (whitespace-preserving erasure) |
| Boa | type + message | Boa's public API exposes no reliable span |

## Output survives a throw

`output()` is a separate channel from the return value and the exception. The
buffer is cleared at the **start** of each `eval` and preserved through a throw,
so anything the guest printed before it crashed is still readable:

```php
try {
    $t->eval('console.log("step 1"); throw new Error("boom");');
} catch (Terrarium\GuestException $e) {
    echo $t->output();   // "step 1"   (printed before the throw)
}
```

This is exactly what a run→inspect→fix loop needs: the partial output *and* the
typed error, both recoverable from the same failed call.

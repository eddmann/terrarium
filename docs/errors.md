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

## Static validation vs. runtime errors

`check()` is the other side of this: it returns diagnostics as **data**, never
as exceptions, and never runs the guest.

```php
$ts->check('user.fetch("42")');
// [['message' => "Argument of type 'string' is not assignable ...", 'type' => 'TS2345', 'line' => 1]]
```

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

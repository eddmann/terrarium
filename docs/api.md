# API reference

The library exposes a single `Terrarium` class (`lib/Terrarium.php`), a thin,
typed facade over the Rust-backed `Terrarium\Runtime` engine primitive. For the
bigger picture see [architecture](architecture.md); for shared vs. isolated see
[execution modes](execution-modes.md); for the exception family see
[errors](errors.md).

The guest's *language* is decided entirely by which `*_guest.wasm` you load —
every method below is identical across guests.

All classes live under the `Terrarium\` namespace — the facade is
`Terrarium\Terrarium`, the exceptions are `Terrarium\Exception` and its
subclasses. Import what you use (`use Terrarium\Terrarium;`) or reference the
fully-qualified names. Install via `composer require eddmann/terrarium` (which
declares the `ext-terrarium` requirement) or require `lib/Terrarium.php` directly.

### `new Terrarium(string $path, ?int $memoryLimit = null, ?int $timeoutMs = null, ?int $maxStack = null, ?int $fuel = null, bool $isolated = false, bool $syncOnly = false)`

Load a guest engine from a `.wasm` file. Limits default to unbounded; pass
non-zero values to contain resource abuse:

- **`memoryLimit`** (bytes) — caps linear-memory growth; a `memory.grow` past it
  raises `Terrarium\MemoryException`.
- **`timeoutMs`** — a wall-clock deadline (epoch interruption); an over-budget
  run raises `Terrarium\TimeoutException`.
- **`maxStack`** (bytes) — the native call-stack cap.
- **`fuel`** — deterministic instruction metering (an alternative to `timeoutMs`
  for reproducible runs); exhaustion raises `Terrarium\TimeoutException`.
- **`isolated`** — `true` runs each `eval()` in a fresh instance (hermetic); the
  default shares one persistent instance so guest state accumulates across calls
  (see [execution modes](execution-modes.md)).
- **`syncOnly`** — `true` makes a compiling guest **reject asynchronous and
  generator syntax at compile time** (see below).

#### Synchronous-only guests

There is no event loop in the sandbox: no guest drains the job (microtask)
queue, so a program that suspends never resumes. Terrarium closes that hole from
both ends.

**At run time, always on.** On the QuickJS-based guests (JavaScript, TypeScript)
an `eval` whose result is a `Promise` — in *any* state, since `.then` callbacks
are queued rather than called — or that leaves jobs queued raises a
`Terrarium\GuestException` of type **`AsyncIncomplete`**. Previously such a
program half-ran in silence: an async IIFE returned a pending promise and its
continuation was dead code. Output printed before the suspension is preserved,
as with any other guest error.

```php
$js->eval('(async () => { console.log("before"); await 1; save(); })()');
// Terrarium\GuestException: AsyncIncomplete: asynchronous guest code cannot
// complete: the program evaluated to a Promise, and the job queue is never
// drained here, so its continuation never ran. …
echo $js->output();   // "before"  — save() never ran
```

**At compile time, opt in with `syncOnly: true`.** The **TypeScript** guest
walks the parsed source and rejects every `async` function (declaration,
expression, arrow, class or object-literal method), `await` (including top-level
`await`), `for await`, generator `function*`/`*method()`, and `yield` — each as
a diagnostic of type **`TSSyncOnly`** carrying the source line and a message
naming the synchronous alternative:

```php
$ts = new Terrarium('typescript_guest.wasm', syncOnly: true);
$ts->eval('const rows = await db.query("…");');
// Terrarium\GuestException: TSSyncOnly: `await` is not supported: this
// environment is synchronous; SDK calls return values directly — remove
// `await` and use the returned value. (line 1)
```

Because this is an AST walk and not a text search, prose is safe: a string
literal or comment containing *"we await your reply"* is not a violation, and
neither is an identifier or property merely named `async` or `await`.

Two properties worth knowing:

- **`// @ts-nocheck` does not disable it.** The pragma opts out of the *type*
  check — an author's preference about their own annotations. `syncOnly` is a
  constraint of the host, which genuinely cannot finish such a program, so the
  walk runs regardless.
- **`eval()` reports the first violation** (and counts the rest, as with type
  errors); **`check()` lists every one**, ahead of the type diagnostics.

Guests without a compiler (JavaScript, Python, PHP) accept the option and do
nothing with it — only the TypeScript guest has an AST to enforce it against.
The QuickJS guest is still covered by the run-time `AsyncIncomplete` failure
above; for Boa and RustPython the option is currently inert.

The engine primitive underneath takes the option as an open map, which is the
call to reach for when using `Terrarium\Runtime` directly:

```php
$rt = new Terrarium\Runtime($wasmBytes);
$rt->setCompileOptions(['sync_only' => true]);   // guests ignore keys they don't know
```

### `register(string $name, callable $fn): void`

Expose a PHP callable to the guest under a flat, dotted name — reached as
`<dotted.name>(...)` in the guest, with **no synthetic root** (a capability named
`user.fetch` is called as `user.fetch(...)`, not `sdk.user.fetch(...)`). This
flat registry is the **entire** trust boundary: the guest can reach nothing you
did not register.

Types are inferred from the closure's signature and PHPDoc (see
[`types()`](#typesstring-format--dts-string)); no separate schema. Names are
validated — every dotted segment must be an identifier, bridge-reserved names
(`__host`, `$out`, …) are rejected, and shadowing a guest builtin (`console`,
`Math`, …) warns.

```php
$t->register('user.fetch',
    /** @return array{name: string, roles: string[]} */
    fn (int $id): array => ['name' => 'Ada', 'roles' => ['admin', 'dev']]);
```

### `eval(string $source): mixed`

Run guest source and marshal the result back to PHP. A guest-program error (a
thrown JS exception, a Python traceback, a failed TypeScript type-check) raises
a `Terrarium\GuestException` whose message reads `Type: message (line N)`, located
at the original source line (see [errors](errors.md)). Anything the guest printed
is captured separately — read it with [`output()`](#output-string).

### `check(string $source): array`

Statically validate guest source **without running it**. Returns every
diagnostic as `{message, type?, line?}`; an empty array means it passed. Nothing
executes: no capability can fire and `output()` is untouched.

The depth is the strongest the guest's language offers — the TypeScript guest
type-checks against the registered SDK (and ignores `// @ts-nocheck`, since an
explicit check asks for the diagnostics); the JS, Python, and PHP guests report
syntax/compile errors.

```php
$t->check('const u = user.fetch("42"); const n: number = u.name;');
// [['message' => "Argument of type 'string' is not assignable ...", 'type' => 'TS2345', 'line' => 1],
//  ['message' => "Type 'string' is not assignable to type 'number'.",  'type' => 'TS2322', 'line' => 1]]
```

### `output(): string`

What the most recent `eval` printed via `console.log` (JS/TS) or `print`
(Python) / `echo` (PHP), lines joined by `\n`. Captured into a per-`eval` buffer
and **preserved even when that `eval` threw**, so output printed before a crash
is still readable.

### `types(string $format = 'dts'): string`

The generated type declaration for the registered SDK, inferred from the
registered closures (Reflection + PHPDoc, incl. nested `array{…}` shapes):

- **`'dts'`** — a TypeScript `.d.ts` (what the TypeScript guest type-checks
  against).
- **`'pyi'`** — a Python stub (`TypedDict`s).
- **`'php'`** — a PHP stub (namespace classes; the PHP guest's view).

An unknown format raises `InvalidArgumentException`.

### `grant(mixed $resource): int` / `resolve(int $handle): mixed` / `revoke(int $handle): bool`

Capability handles for live, stateful objects (DB connections, file handles).
The object stays host-side; the guest only ever sees an opaque integer it can
pass back to a capability, which `resolve()`s it. The handle **is** the
capability. `revoke()` drops it, returning whether it existed.

```php
$pdo = new PDO('sqlite:app.db');
$h   = $t->grant($pdo);
$t->register('db.query', fn (int $handle, string $sql) => $t->resolve($handle)->query($sql)->fetchAll());
```

### `manifest(): array`

The registered capability names, sorted — the audit surface.

### `reset(): bool`

Drop the persistent shared instance, so the next `eval()` re-instantiates the
guest (and re-warms any engine-internal state, e.g. the TypeScript compiler
context). A no-op in isolated mode (every call is already fresh). Returns whether
an instance existed.

> Note: the bundled guests run each `eval` in a fresh runtime, so guest *program*
> globals don't accumulate across evals regardless — see
> [execution modes](execution-modes.md#guests-are-hermetic-per-eval--in-both-modes).

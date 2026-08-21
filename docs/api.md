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

### `new Terrarium(string $path, ?int $memoryLimit = null, ?int $timeoutMs = null, ?int $maxStack = null, ?int $fuel = null, bool $isolated = false, bool $syncOnly = false, ?array $typeArgumentSchemas = null)`

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
- **`typeArgumentSchemas`** — a list of callee names (`['ctx.model',
  'ctx.agent']`) whose **single type argument** a compiling guest should derive
  a JSON Schema from, returned by [`analyze()`](#analyzestring-source-array).

#### Synchronous-only guests

There is no event loop in the sandbox: no guest drains the job (microtask)
queue, so a program that suspends never resumes. Terrarium closes that hole from
both ends.

**At run time, always on.** On the QuickJS-based guests (JavaScript, TypeScript)
an `eval` raises a `Terrarium\GuestException` of type **`AsyncIncomplete`** when
any of three things holds afterwards:

1. **the result is a `Promise`** — in *any* state, since `.then` callbacks are
   queued rather than called, so even a "resolved" chain's continuations never
   ran;
2. **jobs are queued** — the program returned a plain value but left work behind
   it;
3. **a promise reaction was registered** — any `.then` / `.catch` / `.finally`
   during the eval.

Previously such a program half-ran in silence: an async IIFE returned a pending
promise and its continuation was dead code. Output printed before the suspension
is preserved, as with any other guest error.

```php
$js->eval('(async () => { console.log("before"); await 1; save(); })()');
// Terrarium\GuestException: AsyncIncomplete: asynchronous guest code cannot
// complete: the program evaluated to a Promise, and the job queue is never
// drained here, so its continuation never ran. …
echo $js->output();   // "before"  — save() never ran
```

The third check closes the gap the first two leave. In
`const p = new Promise(() => {}); p.then(save); 42` the result is `42` *and*
nothing is queued — a reaction on a promise that never settles never becomes a
job — so without it the eval returned 42 and dropped `save` without a word.
[errors.md](errors.md#exactly-what-asyncincomplete-catches-and-what-it-does-not)
sets out how the count is made, why it is sound, and **the one case it still
cannot see**: `await` bypasses `Promise.prototype.then`, so a fire-and-forget
async function suspended on a promise that never settles stays invisible to the
engine's public API. That is a documented limitation, not a guarantee — and
`syncOnly` is what actually rules it out.

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

**Promises go with them, keyword or no keyword.** A promise needs a job queue to
settle and there isn't one, so the ban covers the whole family: the global
`Promise` (constructed, named, or written in a type), any expression whose type
is a promise — including a capability that returns one — and any
`.then`/`.catch`/`.finally` on one.

```php
$ts->check('const p = new Promise(() => {}); p.then(save); 42');
// [ TSSyncOnly (line 1) `Promise` cannot be used here: promises cannot settle
//     in a synchronous guest … ,
//   TSSyncOnly (line 1) `.then` / `.catch` / `.finally` cannot run here … ]
```

Because this is a walk over the *checked* program and not a text search, prose
is safe: a string literal or comment containing *"we await your reply"* is not a
violation, neither is an identifier or property merely named `async` or `await`,
a class of your own called `Promise` is not the global one, and an object with
an ordinary `then(cb)` method of its own is not a promise (a thenable's `then`
hands back another thenable; that one returns `void`).

Three properties worth knowing:

- **`// @ts-nocheck` does not disable it.** The pragma opts out of the *type*
  check — an author's preference about their own annotations. `syncOnly` is a
  constraint of the host, which genuinely cannot finish such a program, so the
  walk runs regardless. With the pragma there is no Program to consult, so the
  promise rules fall back to *shape*: `new Promise`, the identifier `Promise`,
  and a call through a member named `then`/`catch`/`finally`. The last of those
  over-matches an object with a `then` method of its own — a deliberate trade,
  because opting out of the type information is what removed the precision.
  Drop the pragma and matching is exact again. (`check()` always builds a
  Program, so it stays precise either way.)
- **`eval()` reports the first violation** (and counts the rest, as with type
  errors); **`check()` lists every one**, ahead of the type diagnostics.
- **Every occurrence is listed**, so a promise-returning capability used in
  three places yields three diagnostics — one per expression, reported at the
  outermost promise-typed node, so a chain is not reported over and over.

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

### `analyze(string $source): array`

The same static pass as `check()`, with everything the guest was additionally
asked to extract:

```php
['diagnostics' => [...], 'schemas' => [...]]
```

`diagnostics` is *exactly* what `check()` returns — same entries, same order.
`schemas` is empty unless the guest was constructed with
`typeArgumentSchemas:` (below). Nothing executes, as with `check()`. A guest
without the optional `analyze` export raises a `Terrarium\Exception`; of the
bundled guests only **TypeScript** has one.

> **Why a second method rather than a wider `check()`.** `check(): array` of
> diagnostics is the older contract and stays literally unchanged — a host that
> knows nothing of schemas can never be handed a shape it does not expect, and
> nothing has to be version-sniffed. `analyze()` is the widenable one: future
> extractions join its map without touching either existing shape.

#### Type-argument schemas

Opt-in and additive: with no `typeArgumentSchemas` the guest behaves exactly as
it did before this existed — `schemas` is `[]`, no walk runs, and no diagnostic
can come from it. Construct the guest with the callees to extract from:

```php
$ts = new Terrarium('typescript_guest.wasm', typeArgumentSchemas: ['ctx.model', 'ctx.agent']);
$out = $ts->analyze('const v = ctx.agent<{ ok: boolean; note?: string }>({ ... }); v;');

$out['schemas'];
// [['ordinal' => 0, 'callee' => 'ctx.agent', 'line' => 1,
//   'schema' => '{"type":"object","properties":{"ok":{"type":"boolean"},"note":{"type":"string"}},'
//             . '"required":["ok"],"additionalProperties":false}']]
```

The guest walks the source for calls whose callee is one of those names **and**
which carry exactly one type argument, resolves that type argument with the
TypeScript checker, and serialises it. This inverts the usual arrangement: the
author writes the *type* and the host derives the schema, instead of the author
writing a schema literal and inferring the type from it.

Four properties make the result usable as a stored artifact:

- **`schema` is canonical JSON text.** Fixed key order (`type`, `properties`,
  `required`, `additionalProperties`; `type`, `items`), no whitespace, emitted
  as text rather than serialised from an object — so integer-like property names
  cannot be reordered by the engine. The same source always produces
  byte-identical bytes, which is what makes it safe to hash or fold into a
  replay digest.
- **`ordinal` is the identity, never line:column.** A call is identified by its
  0-based index among matched calls in source order. Reformatting, renaming a
  binding, rewrapping arguments, and adding comments all move a line:column and
  none of them move an ordinal. Adding or removing a matched call *does*
  renumber the ones after it — the numbering is positional, meaningful only
  within one version of one source, which is why it is re-derived on every
  analysis rather than stored against a call.
- **A refused call still consumes its ordinal.** It contributes a
  `TSSchemaError` diagnostic (carrying `ordinal` in the data and `line` for the
  human) and no `schemas` entry, so one inexpressible type argument cannot
  renumber its neighbours.
- **`line` is the runtime bridge, alongside the ordinal and never inside the
  schema.** It is the 1-based line of the *call's start* in the submitted source
  — the same convention `TSSchemaError` (and every other diagnostic) uses, so a
  refusal and the entry it displaced name the same line — and entries stay
  sorted by start position, so `line` is non-decreasing across them and two
  matched calls on one line simply share it (whether that is allowed is the
  consumer's policy, not the guest's). It exists because a consumer whose
  compiled artifact is immutable per version can only key its baked schemas by
  line: at execution time the running guest knows nothing but the line it is on,
  so the ordinal → schema pairing is done once at publish time, when the source
  and this extraction are both in hand. Being a sibling of `schema` rather than
  a member of it is what keeps the schema bytes hashable: moving a call moves
  its `line` and not one byte of its `schema`.

Calls to a listed callee written *without* a type argument are untouched — no
schema, no diagnostic. Schema-first authoring stays legal; deciding whether a
call must use one style or the other is the host's rule, not the guest's.

**The expressible subset** is deliberately narrow: exactly what a type-level
schema reader can turn back into the type the author wrote.

| TypeScript | JSON Schema |
| --- | --- |
| `{ a: string; b?: number }` | `{"type":"object","properties":{…},"required":["a"],"additionalProperties":false}` |
| `readonly` members | identical (readonly says nothing about JSON) |
| `T[]`, `readonly T[]`, `Array<T>` | `{"type":"array","items":…}` |
| `string` / `number` / `boolean` / `null` | `{"type":"string"}` … (`number` stays `number` — never guessed as `integer`) |
| `"yes"` / `42` / `true` | `{"const":"yes"}` |
| `"a" \| "b"` | `{"type":"string","enum":["a","b"]}` |
| `"a" \| 1 \| true` | `{"enum":[1,"a",true]}` (JSON-compatible, no single `type`) |
| `string \| null` | `{"type":["string","null"]}` |
| `"a" \| "b" \| null` | `{"type":["string","null"],"enum":["a","b",null]}` |
| `A & B`, `Partial<T>`, `Pick<T, K>`, `Omit<T, K>` | whatever plain object they reduce to |

Everything else is **refused**, with a diagnostic naming the offending member
path (`verdicts[].judgment: function types cannot be expressed as JSON Schema`)
— because a schema the other side reads back as a *different* type is worse than
no schema at all:

`any`, `unknown`, `never`, `undefined` (including `T | undefined`, which should
be `?`), `void`, `bigint`, `symbol`, functions and methods, constructor types,
class instances, TypeScript `enum` (erased here, so nothing survives to name),
`Date`/`Map`/`Set`/`RegExp` and other named library types, `Promise`, index
signatures (`Record<string, T>` included), tuples, template literal types,
unresolved generic parameters, unresolved names, `object`, intersections that do
not reduce to a plain object, unions other than nullable primitives and
literals — including **`{…} | null` and `T[] | null`**, since the readable
nullable spelling is `type: [x, "null"]` and carries primitives only — and
recursive types, which are refused by naming the cycle rather than looping.

`check()` reports those `TSSchemaError` diagnostics too, so an existing
validation path picks them up without changing its call. `eval()` is untouched:
extraction is a static concern and never gates execution.

The engine primitive takes the option directly:

```php
$rt->setCompileOptions(['type_argument_schemas' => ['ctx.model', 'ctx.agent']]);
$rt->analyze($source);   // ['diagnostics' => [...], 'schemas' => [...]]
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

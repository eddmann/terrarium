# Execution modes

A `Terrarium` *instance* is the unit of isolation. The `isolated` constructor
flag chooses the lifecycle of the underlying **wasm instance**.

```php
use Terrarium\Terrarium;

$shared   = new Terrarium('guest.wasm');                  // default: one persistent instance
$isolated = new Terrarium('guest.wasm', isolated: true);  // a fresh instance per eval()
```

## Guests are hermetic per eval — in both modes

This is the key thing to understand up front, and it differs from a classic
embedded interpreter: **every bundled guest runs each `eval()` in a fresh
language runtime.** The QuickJS/Boa/TypeScript guests create a new JS runtime per
eval; RustPython a new interpreter; the PHP guest a fresh execution. So guest
*program* state — a JS `globalThis` assignment, a Python name, a PHP `$GLOBALS`
entry — **does not carry from one `eval()` to the next**, in *either* mode:

```php
$js = new Terrarium('quickjs_guest.wasm');   // shared
$js->eval('globalThis.x = 1;');
$js->eval('typeof globalThis.x;');            // => "undefined"  (fresh runtime, even shared)
```

The mode does **not** change this. What it changes is the *wasm instance* around
that runtime.

## What the mode actually controls

A Wasmtime **`Instance`** owns the guest's linear memory — the engine's compiled
code and any state it keeps *between* the per-eval runtimes it spins up. The
`Engine`/`Store` own the compiled module and the limits. Everything the bridge
needs — the capability dispatch table, the handle table, the output buffer —
lives host-side.

| | Shared (default) | Isolated (`isolated: true`) |
|---|---|---|
| Wasm instance per `Terrarium` | one, reused for its life | a fresh one per `eval()`, discarded after |
| Guest program state across evals | **gone** (fresh runtime per eval) | **gone** (fresh runtime per eval) |
| Engine-internal warm state | **kept** (e.g. the TS compiler) | rebuilt each call |
| Linear memory per call | reused | fresh |
| Registered capabilities / handles | work | work |
| Output capture (`output()`) | works | works |
| Per-call limits (memory/time/fuel) | yes | yes |

Because capabilities exchange **data, not functions** — closures never cross the
boundary — there is no callback-that-outlives-its-eval hazard in either mode.

## Shared mode — reuse the instance, keep the engine warm

The default. One wasm instance for the object's life; each `eval()` still runs in
its own fresh guest runtime. The win is **cost and warmth**, not state
persistence:

- No re-instantiation per call.
- Engine-internal caches survive between evals. This matters most for the
  [TypeScript guest](../guests/typescript/README.md): its compiler context (tsc +
  the parsed lib `.d.ts` chain + the last `Program`) is kept warm, so repeat
  checks are **~5 ms** instead of the cold ~500 ms.

`reset()` drops the shared instance, so the next `eval()` re-instantiates (and,
for the TS guest, re-warms the compiler).

## Isolated mode — a fresh instance per eval

A brand-new wasm instance per `eval()`, discarded afterward — a guaranteed-fresh
linear memory each call. Guest program state is fresh either way (see above); what
isolated adds is **defense-in-depth**: no cross-call reuse of the engine's linear
memory at all, so not even an engine-level memory-corruption bug can carry from
one call to the next.

Instantiation is cheap — the module is compiled once, then each call instantiates
from a pre-resolved `InstancePre`. The trade is that engine-internal warm state is
rebuilt per call; for the TypeScript guest that would be the ~500 ms compiler
bring-up, which is why it is [Wizer](../guests/typescript/README.md)-snapshotted
so a fresh instance starts with the compiler already warm (~23 ms/call).

## Fault recovery

A sandbox-level fault (a trap, a timeout, a memory-limit hit) **poisons** the
instance. Terrarium drops it: in shared mode the next `eval()` instantiates fresh;
in isolated mode it was fresh anyway, so recovery is free. A guest-*program* error
(the `$error` sentinel — see [errors](errors.md)) is not a fault and leaves the
instance usable.

## Choosing a mode

- **Shared (default):** the right choice almost always — cheapest per call, and it
  keeps a stateful engine (the TS compiler) warm. Guests are already hermetic per
  eval, so you get inter-eval isolation of the guest program for free.
- **Isolated:** when you want a guaranteed-fresh linear memory per call as
  defense-in-depth, and can afford per-call engine bring-up.
- **Strongest isolation:** a brand-new `Terrarium` per tenant — a fresh
  `Engine`/`Store`, a separate compiled module and its own limits.

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
`Store` owns that instance and its limits; the `Engine`/`Module` own only the
compiled code. Everything the bridge needs — the capability dispatch table, the
handle table, the output buffer — lives host-side.

| | Shared (default) | Isolated (`isolated: true`) |
|---|---|---|
| Wasm instance per `Terrarium` | one, reused for its life | a fresh one per `eval()`, discarded after |
| Guest program state across evals | **gone** (fresh runtime per eval) | **gone** (fresh runtime per eval) |
| Engine-internal warm state | **kept** (e.g. the TS compiler) | rebuilt each call |
| Linear memory per call | reused | fresh |
| Registered capabilities / handles | work | work |
| Output capture (`output()`) | works | works |
| Per-call limits (memory/time/fuel) | yes | yes |
| Compiled code (`Engine`/`Module`/`InstancePre`) | shared with every `Terrarium` over the same bytes and engine options | same |
| Store, instance, linear memory, limits, deadlines, fuel, capabilities | this `Terrarium`'s alone | this `Terrarium`'s alone |

Because capabilities exchange **data, not functions** — closures never cross the
boundary — there is no callback-that-outlives-its-eval hazard in either mode.

## What two `Terrarium` objects share — and what they never do

Constructing a second `Terrarium` from the same `.wasm` does **not** compile it
again: identical bytes read the same way (as wasm, or as a
[precompiled artifact](api.md#static-precompilestring-path-int-maxstack--null-int-fuel--null-bool-portable--true-string))
under identical engine-level options (whether fuel metering is on, and
`maxStack`) resolve to one process-wide `Engine`, `Module` and `InstancePre`, so
the second construction costs a hash of the bytes plus an instantiation rather
than another compile: for the 28 MB TypeScript guest, about 8 ms (release build)
against the ~300 ms it previously spent being deserialized on every
construction. The cache holds a small, fixed number of distinct guests and
evicts the least recently inserted; an evicted compilation stays alive for any
`Terrarium` still holding it. Note the flip side: up to that many compiled guests
stay resident for the life of the process even after every `Terrarium` over
them is gone (that is what makes the next construction cheap), and this memory
is outside any `memoryLimit` — for a heavy guest, on the order of its artifact
size per entry.

Compiled code is immutable, so this shares nothing that isolates a run. Each
`Terrarium` keeps its own `Store` and instance (and so its own linear memory),
its own `memoryLimit`, `timeoutMs` deadlines and fuel budget, and its own
capability table, granted handles and output buffer. Two Runtimes over one guest
can register *different* callables under the *same* name and each call reaches
its own: a `Store` carries a pointer to its own Runtime's bridge, so the shared
`host_call` import dispatches against whichever Runtime is executing. A
capability registered on one is unknown to the other, a timeout or trap on one
leaves the other running, and neither can see the other's output — see
[tests/php/13_shared_engine.php](../tests/php/13_shared_engine.php).

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

`check()` and `analyze()` use the same shared instance too. Each operation can
receive its own [timeout override](api.md#per-call-timeouts), so shrinking an
enclosing deadline does not require rebuilding the Runtime. An explicit positive
timeout covers initialization; omitted/null preserves the constructor default's
historical setup exemption.

Reset retains the compiled guest (the process-wide `Engine`/`Module`/`InstancePre`,
which it never owned alone) and all host state: callbacks, declarations,
compile options, output and granted handles. Registering an existing name
replaces its callable, but removing a declaration does not revoke that callable.
For session reuse, refresh fixed callback slots and declarations/options before
execution; reset and release captured host context at the session boundary, and
revoke any handles. Check/analyze do not clear previous output, so do not attribute
an earlier eval's output to a subsequent validation failure.

Fresh user globals do not mean fresh linear memory. Compiler state and old
allocations remain within the shared instance until reset; bound session size
and retain isolated mode when per-call fresh memory is required. Runtime use is
sequential and process-local (PHP NTS). Recursive shared calls are rejected, and
reset must not be called from inside a shared callback.

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
- **Strongest isolation:** a brand-new `Terrarium` per tenant — its own
  `Store`, instance and linear memory, its own limits and deadlines, and its own
  capability table, handles and output. The compiled code behind it is shared
  with any other `Terrarium` over the same guest, which is immutable and carries
  no tenant state.

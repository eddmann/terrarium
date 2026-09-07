<?php
// Compiled guests are shared process-wide; Runtimes are not.
//
// Identical wasm bytes under identical engine options are compiled once and
// every later `Terrarium` clones the same Engine/Module/InstancePre (src/lib.rs
// `compiled_guest`), so the second construction of a heavy guest costs an
// instantiation instead of a fresh deserialize. Only *immutable compiled code*
// is shared: this suite pins down that everything which isolates a run — the
// Store, the instance and its linear memory, the capability table, the output
// buffer, the memory limit, the deadline, the fuel budget — stays per Runtime,
// including for two Runtimes over the very same bytes.

declare(strict_types=1);

require __DIR__ . '/_harness.php';
use Terrarium\Terrarium;
use Terrarium\Exception as TerrariumException;
use Terrarium\TrapException;
use Terrarium\TimeoutException;
use Terrarium\MemoryException;
use Terrarium\GuestException;

$wasm = require_guest(__DIR__ . '/../wasm/quickjs_guest.wasm');

// A loop long enough that a foreign deadline elsewhere expires while it is
// still executing (so its Store really does field another Runtime's epoch
// ticks), but bounded, so an unbounded Runtime always completes it.
const LONG_LOOP = 'let s = 0; for (let i = 0; i < 3e6; i++) s += i; s > 0';
// Far too little fuel for the guest to run anything at all.
const FUEL_LOW = 100_000;
// Comfortably more than a trivial eval needs (a bare `1 + 1` costs a few
// million: the guest builds a fresh JS runtime for every eval).
const FUEL_AMPLE = 100_000_000;

// Must run before anything else builds this guest: the point of the measurement
// is the very first (compiling) construction in this process against a later one.
echo "construction reuses the compiled guest\n";
$cold = -hrtime(true);
$first = new Terrarium($wasm);
$cold += hrtime(true);
$warm = -hrtime(true);
$second = new Terrarium($wasm);
$warm += hrtime(true);
printf("  first construct %.1f ms, second %.3f ms\n", $cold / 1e6, $warm / 1e6);

check('both constructions produce a working Runtime', function () use ($first, $second) {
    eq(2, $first->eval('1 + 1'));
    eq(4, $second->eval('2 + 2'));
});
// Deliberately loose (the real gap is ~1000x): this asserts the cache is in
// play at all without turning a slow CI box into a false failure.
check('the second construction is far cheaper than the first', function () use ($cold, $warm) {
    if ($warm > $cold / 2) {
        throw new RuntimeException(sprintf(
            'expected a cheap second construct, got %.3f ms against %.3f ms',
            $warm / 1e6,
            $cold / 1e6
        ));
    }
});

echo "\ncapability tables stay per Runtime\n";
foreach ([false, true] as $isolated) {
    $mode = $isolated ? 'isolated' : 'shared';
    check("$mode: one name, two Runtimes, two callables", function () use ($wasm, $isolated) {
        $a = new Terrarium($wasm, isolated: $isolated);
        $b = new Terrarium($wasm, isolated: $isolated);
        $a->register('whoami', fn (): string => 'A');
        $b->register('whoami', fn (): string => 'B');
        // Interleaved, because a shared InstancePre would only ever be wrong
        // for whichever Runtime did not build it.
        eq('A', $a->eval('whoami()'));
        eq('B', $b->eval('whoami()'));
        eq('A', $a->eval('whoami()'));
        eq('B', $b->eval('whoami()'));
    });
    check("$mode: arguments and results reach the right side", function () use ($wasm, $isolated) {
        $a = new Terrarium($wasm, isolated: $isolated);
        $b = new Terrarium($wasm, isolated: $isolated);
        $a->register('tag', fn (string $s): string => "A:$s");
        $b->register('tag', fn (string $s): string => "B:$s");
        eq(['A:x', 'B:x'], [$a->eval('tag("x")'), $b->eval('tag("x")')]);
        eq(['B:y', 'A:y'], [$b->eval('tag("y")'), $a->eval('tag("y")')]);
    });
}

check('a capability registered on one Runtime is unknown to another', function () use ($wasm) {
    $a = new Terrarium($wasm);
    $b = new Terrarium($wasm);
    // The same top-level name, so the guest installs the namespace either way
    // and the call really does reach the host's trust-boundary lookup.
    $a->register('svc.alpha', fn (): string => 'from A');
    $b->register('svc.beta', fn (): string => 'from B');
    eq('from A', $a->eval('svc.alpha()'));
    eq('from B', $b->eval('svc.beta()'));
    foreach ([[$b, 'svc.alpha'], [$a, 'svc.beta']] as [$rt, $foreign]) {
        try {
            $rt->eval("$foreign()");
            throw new RuntimeException("expected $foreign to be unknown");
        } catch (TerrariumException $e) {
            contains($e->getMessage(), "unknown capability: $foreign");
        }
    }
});

check('granted handles are not visible to another Runtime', function () use ($wasm) {
    $a = new Terrarium($wasm);
    $b = new Terrarium($wasm);
    $handle = $a->grant(new ArrayObject(['n' => 7]));
    eq(7, $a->resolve($handle)['n']);
    throws(TerrariumException::class, fn () => $b->resolve($handle));
});

echo "\ncaptured output stays per Runtime\n";
check('output does not leak between Runtimes over the same guest', function () use ($wasm) {
    $a = new Terrarium($wasm);
    $b = new Terrarium($wasm);
    $a->eval('console.log("from A")');
    $b->eval('console.log("from B")');
    eq('from A', $a->output());
    eq('from B', $b->output());
    $a->eval('console.log("A again")');
    eq('A again', $a->output());   // cleared per eval, and only its own
    eq('from B', $b->output());
});

echo "\ndeadlines stay per Runtime\n";
check('a timed-out call does not disturb a later unbounded one', function () use ($wasm) {
    $bounded = new Terrarium($wasm, timeoutMs: 150);
    $free = new Terrarium($wasm);
    throws(TimeoutException::class, fn () => $bounded->eval('while (true) {}'));
    eq(true, $free->eval(LONG_LOOP));   // longer than the budget that just tripped
    eq(2, $free->eval('1 + 1'));
});
check('an unbounded call is unaffected by a timeout that follows it', function () use ($wasm) {
    $free = new Terrarium($wasm);
    $bounded = new Terrarium($wasm, timeoutMs: 150);
    eq(true, $free->eval(LONG_LOOP));
    throws(TimeoutException::class, fn () => $bounded->eval('for (;;) {}'));
    eq(true, $free->eval(LONG_LOOP));
    eq(4, $bounded->eval('2 + 2'));              // and it recovers
});
check('a nested timed-out call cannot interrupt the Runtime that made it', function () use ($wasm) {
    $outer = new Terrarium($wasm);                      // unbounded
    $inner = new Terrarium($wasm, timeoutMs: 100);
    $outer->register('spin', function () use ($inner): bool {
        try {
            $inner->eval('while (true) {}');
        } catch (TimeoutException $e) {
            return true;
        }
        return false;
    });
    eq(true, $outer->eval('spin()'));   // the outer eval completes normally
    eq(4, $inner->eval('2 + 2'));       // the inner Runtime recovers
    eq(2, $outer->eval('1 + 1'));
    $outer->register('spin', fn (): bool => false);   // release the capture
});
check('an expiring Runtime cannot cut short a nested unbounded one', function () use ($wasm) {
    $bounded = new Terrarium($wasm, timeoutMs: 50);
    $free = new Terrarium($wasm);
    $inner = null;
    // The nested guest is still executing when the outer budget expires, so the
    // outer Runtime's epoch ticks arrive mid-run on the inner Store. They must
    // be answered against the inner Store's own (absent) deadline.
    $bounded->register('work', function () use ($free, &$inner): bool {
        $inner = $free->eval(LONG_LOOP);
        return false;
    });
    throws(TimeoutException::class, fn () => $bounded->eval('work()'));
    eq(true, $inner);   // the nested call ran to completion, uninterrupted
    $bounded->register('work', fn (): bool => false);
    eq(2, $free->eval('1 + 1'));
    eq(4, $bounded->eval('2 + 2'));
});

echo "\nmemory limits stay per Runtime\n";
check('a limit too small to start does not bind another Runtime', function () use ($wasm) {
    $tiny = new Terrarium($wasm, memoryLimit: 256 * 1024);
    $ample = new Terrarium($wasm, memoryLimit: 64 << 20);
    throws(TerrariumException::class, fn () => $tiny->eval('1 + 1'));
    eq(2, $ample->eval('1 + 1'));
    throws(TerrariumException::class, fn () => $tiny->eval('1 + 1'));
    eq(4, $ample->eval('2 + 2'));
});
check('a growth ceiling binds only the Runtime that set it', function () use ($wasm) {
    $bounded = new Terrarium($wasm, memoryLimit: 24 << 20);
    $ample = new Terrarium($wasm, memoryLimit: 256 << 20);
    $alloc = 'new Uint8Array(48 * 1024 * 1024).length';
    throws(TerrariumException::class, fn () => $bounded->eval($alloc));
    eq(48 * 1024 * 1024, $ample->eval($alloc));
    eq(2, $bounded->eval('1 + 1'));   // and the bounded one is still usable
});

echo "\nfuel and stack settings select their own compiled guest\n";
check('fuel exhaustion trips on the metered Runtime only', function () use ($wasm) {
    $metered = new Terrarium($wasm, fuel: FUEL_LOW);
    $free = new Terrarium($wasm);   // no fuel: a separate compilation entirely
    throws(TimeoutException::class, fn () => $metered->eval('1 + 1'));
    eq(2, $free->eval('1 + 1'));
    eq(true, $free->eval(LONG_LOOP));
});
check('two metered Runtimes share the engine but not the budget', function () use ($wasm) {
    $ample = new Terrarium($wasm, fuel: FUEL_AMPLE);
    $starved = new Terrarium($wasm, fuel: FUEL_LOW);
    eq(2, $ample->eval('1 + 1'));
    throws(TimeoutException::class, fn () => $starved->eval('1 + 1'));
    eq(4, $ample->eval('2 + 2'));
});
check('maxStack binds only the Runtime that set it', function () use ($wasm) {
    // Each stack bound compiles its own engine; neither Runtime may borrow the
    // other's headroom, in either order.
    $tiny = new Terrarium($wasm, maxStack: 64 * 1024);
    $ample = new Terrarium($wasm, maxStack: 2 << 20);
    $recurse = fn (int $depth): string => "const f = n => n <= 0 ? 0 : 1 + f(n - 1); f($depth)";
    eq(1000, $ample->eval($recurse(1000)));
    throws(TrapException::class, fn () => $tiny->eval($recurse(1000)));
    eq(1000, $ample->eval($recurse(1000)));
    eq(100, $tiny->eval($recurse(100)));   // shallow recursion still runs
});

echo "\ntraps stay per Runtime\n";
$fixture = file_get_contents(__DIR__ . '/../wasm/adversarial/timeout_initialize.wat');
check('a trap in one Runtime leaves another over the same guest usable', function () use ($fixture) {
    $make = function () use ($fixture): \Terrarium\Runtime {
        $rt = new \Terrarium\Runtime($fixture);
        $rt->register('initialize', fn (): bool => false);
        $rt->register('execute', fn (): bool => false);
        return $rt;
    };
    $trapping = $make();
    $healthy = $make();
    $trapping->register('execute', fn (): int => 2);   // the fixture then traps
    throws(TrapException::class, fn () => $trapping->eval(''));
    eq(42, $healthy->eval(''));                        // same compiled module
    $trapping->register('execute', fn (): bool => false);
    eq(42, $trapping->eval(''));                       // and it recovers itself
    eq(42, $healthy->eval(''));
});

echo "\nan evicted compiled guest outlives the cache entry\n";
check('a Runtime keeps working after its entry is evicted, and the guest rebuilds', function () use ($wasm, $fixture) {
    $held = new Terrarium($wasm);
    $held->register('whoami', fn (): string => 'held');
    eq('held', $held->eval('whoami()'));
    // Fill the cache past GUEST_CACHE_CAPACITY (8) with byte-distinct guests.
    // A trailing comment changes the bytes and so the GuestKey, without
    // changing the module; eviction is by insertion order, so nine fresh keys
    // push the quickjs entry out wherever in the queue it sat.
    $filler = [];
    for ($i = 0; $i < 9; $i++) {
        $filler[] = new \Terrarium\Runtime($fixture . "\n;; $i\n");
    }
    // Eviction drops the cache's handles only: Engine/Module/InstancePre are
    // Arc-backed, so the Runtime still holding them is untouched -- its Store,
    // its instance and its capability table all keep working.
    eq('held', $held->eval('whoami()'));
    eq(2, $held->eval('1 + 1'));
    // ... and the next Runtime over those bytes simply builds them again
    // (a fresh compile, or a hit in Wasmtime's own on-disk cache).
    $rebuild = -hrtime(true);
    $fresh = new Terrarium($wasm);
    $rebuild += hrtime(true);
    printf("  rebuild after eviction %.1f ms\n", $rebuild / 1e6);
    $fresh->register('whoami', fn (): string => 'fresh');
    eq('fresh', $fresh->eval('whoami()'));
    eq('held', $held->eval('whoami()'));   // two compilations, two capability tables
    eq(9, count($filler));
});

echo "\na Runtime may be dropped while another Runtime's call is on the stack\n";
check('the callee is dropped from inside the caller\'s capability', function () use ($wasm) {
    $a = new Terrarium($wasm);
    $b = new Terrarium($wasm);
    $b->register('answer', fn (): int => 41);
    // $b's Store -- which holds a raw pointer to $b's own bridge -- is freed
    // while $a's guest is suspended in this host call. Nothing of $b's is
    // reachable from $a, so the caller must be entirely undisturbed.
    $a->register('drop', function () use (&$b): int {
        $result = $b->eval('answer() + 1');
        $b = null;
        return $result;
    });
    eq(42, $a->eval('drop()'));
    eq(null, $b);                  // really released, not merely nulled after
    eq(2, $a->eval('1 + 1'));      // the caller's own Store survived it
    eq(4, (new Terrarium($wasm))->eval('2 + 2'));   // so did the shared compilation
    $a->register('drop', fn (): int => 0);          // release the capture
});
check('a third Runtime over the same guest is dropped mid-call', function () use ($wasm) {
    $a = new Terrarium($wasm);
    $c = new Terrarium($wasm);
    $c->register('whoami', fn (): string => 'C');
    eq('C', $c->eval('whoami()'));   // so $c really owns a live Store and instance
    // This time the freed Runtime is not the one the callback called: it is a
    // bystander sharing the caller's InstancePre.
    $a->register('sweep', function () use (&$c): bool {
        $c = null;
        return true;
    });
    $a->register('whoami', fn (): string => 'A');
    eq(true, $a->eval('sweep()'));
    eq(null, $c);
    eq('A', $a->eval('whoami()'));   // the caller still dispatches to its own table
    $later = new Terrarium($wasm);
    $later->register('whoami', fn (): string => 'later');
    eq('later', $later->eval('whoami()'));
    eq('A', $a->eval('whoami()'));
    $a->register('sweep', fn (): bool => false);
});

echo "\nreset() drops only the resetting Runtime's instance\n";
check('two Runtimes over one guest reset independently', function () use ($wasm) {
    $a = new Terrarium($wasm);
    $b = new Terrarium($wasm);
    $a->register('whoami', fn (): string => 'A');
    $b->register('whoami', fn (): string => 'B');
    eq('A', $a->eval('whoami()'));
    eq('B', $b->eval('whoami()'));
    eq(true, $a->reset());    // it had a persistent instance ...
    eq(false, $a->reset());   // ... and exactly one, its own
    // The dropped Store carried a pointer to $a's bridge; $b's Store, instance
    // and table are untouched, and $a re-instantiates from the shared
    // InstancePre against its own table rather than its neighbour's.
    eq('A', $a->eval('whoami()'));
    eq('B', $b->eval('whoami()'));
    eq(true, $b->reset());
    eq('B', $b->eval('whoami()'));
    eq('A', $a->eval('whoami()'));
    // Interleaved after both resets, in the opposite order to the first calls.
    eq(['B', 'A'], [$b->eval('whoami()'), $a->eval('whoami()')]);
});

summary();

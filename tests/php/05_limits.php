<?php
// Resource limits and isolation, exercised through a real guest. The same four
// containment knobs from the engine — memoryLimit, timeoutMs, fuel, maxStack —
// plus the shared/isolated execution modes, all surfaced through the `Terrarium`
// facade and verified to recover cleanly after they trip.

declare(strict_types=1);

require __DIR__ . '/_harness.php';
use Terrarium\Terrarium;
use Terrarium\Exception as TerrariumException;
use Terrarium\TrapException;
use Terrarium\TimeoutException;
use Terrarium\MemoryException;
use Terrarium\GuestException;

$wasm = require_guest(__DIR__ . '/../wasm/boa_guest.wasm');

echo "timeouts\n";
check('an infinite loop trips TimeoutException', function () use ($wasm) {
    $g = new Terrarium($wasm, timeoutMs: 200);
    throws(TimeoutException::class, fn () => $g->eval('while (true) {}'));
});
check('the timeout subclass is a TerrariumException', function () use ($wasm) {
    $g = new Terrarium($wasm, timeoutMs: 200);
    throws(TerrariumException::class, fn () => $g->eval('for (;;) {}'));
});
check('the engine recovers and is usable after a timeout', function () use ($wasm) {
    $g = new Terrarium($wasm, timeoutMs: 200);
    try { $g->eval('while (true) {}'); } catch (TimeoutException $e) {}
    eq(4, $g->eval('2 + 2'));
});

echo "\nfuel metering\n";
check('a low fuel budget trips (timeout family)', function () use ($wasm) {
    $g = new Terrarium($wasm, fuel: 100_000);
    throws(TimeoutException::class, fn () => $g->eval('let s = 0; for (let i = 0; i < 1e9; i++) s += i; s'));
});
check('an ample fuel budget completes', function () use ($wasm) {
    $g = new Terrarium($wasm, fuel: 5_000_000);
    eq(10, $g->eval('let s = 0; for (let i = 0; i < 5; i++) s += i; s'));
});

echo "\nmemory limit\n";
check('a tiny memory limit fails cleanly as a TerrariumException', function () use ($wasm) {
    // Far too small for a JS engine to instantiate; must surface as a typed
    // error, never a host crash.
    throws(TerrariumException::class, function () use ($wasm) {
        $g = new Terrarium($wasm, memoryLimit: 64 * 1024); // 64 KiB
        $g->eval('1 + 1');
    });
});
check('a generous memory limit runs fine', function () use ($wasm) {
    $g = new Terrarium($wasm, memoryLimit: 64 << 20); // 64 MiB
    eq(3, $g->eval('1 + 2'));
});

echo "\nexecution modes\n";
check('shared (default): one persistent instance backs eval()', function () use ($wasm) {
    $g = new Terrarium($wasm);                 // shared by default
    eq(2, $g->eval('1 + 1'));
    eq(4, $g->eval('2 + 2'));            // same persistent instance, reused
    eq(true, $g->reset());               // an instance existed -> dropped
    eq(false, $g->reset());              // nothing left to drop
    eq(6, $g->eval('3 + 3'));            // next call lazily builds a fresh one
});
check('isolated: every eval() runs in a fresh instance', function () use ($wasm) {
    $g = new Terrarium($wasm, isolated: true);
    eq(2, $g->eval('1 + 1'));
    eq(4, $g->eval('2 + 2'));
    eq(false, $g->reset());              // nothing persistent to reset
});

/** A tiny real Wasm guest, independent of language-engine startup costs. */
function timedGuest(bool $isolated = false, ?int $default = null, string $fixture = 'timeout_initialize'): \Terrarium\Runtime
{
    $rt = new \Terrarium\Runtime(
        file_get_contents(__DIR__ . '/../wasm/adversarial/' . $fixture . '.wat'),
        timeoutMs: $default,
        isolated: $isolated,
    );
    $rt->register('initialize', fn (): bool => false);
    $rt->register('execute', fn (): bool => false);
    return $rt;
}

echo "\nper-call deadlines\n";
foreach ([false, true] as $isolated) {
    $mode = $isolated ? 'isolated' : 'shared';
    foreach (['eval', 'check', 'analyze'] as $entry) {
        check("$mode $entry: unbounded default then timed call and recovery", function () use ($isolated, $entry) {
            $rt = timedGuest($isolated);
            eq(42, $rt->$entry(''));
            $rt->register('execute', fn (): bool => true);
            throws(TimeoutException::class, fn () => $rt->$entry('', timeoutMs: 30));
            eq(false, $rt->reset()); // the interrupted/isolated Store is gone
            $rt->register('execute', fn (): bool => false);
            eq(42, $rt->$entry('', timeoutMs: 1000));
            eq(42, $rt->$entry('')); // the override did not become the default
        });
        check("$mode $entry: omitted/null inherit, zero disables, override is local", function () use ($isolated, $entry) {
            $rt = timedGuest($isolated, 30);
            $rt->register('execute', function (): bool { usleep(70_000); return false; });
            throws(TimeoutException::class, fn () => $rt->$entry(''));
            throws(TimeoutException::class, fn () => $rt->$entry('', timeoutMs: null));
            eq(42, $rt->$entry('', timeoutMs: 0));
            eq(42, $rt->$entry('', timeoutMs: 1000));
            throws(TimeoutException::class, fn () => $rt->$entry(''));
            throws(TerrariumException::class, fn () => $rt->$entry('', timeoutMs: -1));
        });
    }
    foreach (['timeout_initialize', 'timeout_start'] as $fixture) {
        foreach (['eval', 'check', 'analyze'] as $entry) {
            check("$mode $entry: interrupts $fixture and recovers", function () use ($isolated, $fixture, $entry) {
                $rt = timedGuest($isolated, fixture: $fixture);
                $rt->register('initialize', fn (): bool => true);
                throws(TimeoutException::class, fn () => $rt->$entry('', timeoutMs: 30));
                eq(false, $rt->reset());
                $rt->register('initialize', fn (): bool => false);
                eq(42, $rt->$entry('', timeoutMs: 1000));
            });
        }
        check("$mode: $fixture keeps legacy setup exemption", function () use ($isolated, $fixture) {
            $rt = timedGuest($isolated, 30, $fixture);
            $rt->register('initialize', function (): bool { usleep(70_000); return false; });
            eq(42, $rt->eval(''));
            $rt->reset();
            eq(42, $rt->eval('', timeoutMs: null));
            $rt->reset();
            throws(TimeoutException::class, fn () => $rt->eval('', timeoutMs: 30));
        });
        check("$mode: $fixture and execution share one explicit budget", function () use ($isolated, $fixture) {
            $rt = timedGuest($isolated, fixture: $fixture);
            $pause = function (): bool { usleep(70_000); return false; };
            $rt->register('initialize', $pause);
            $rt->register('execute', $pause);
            throws(TimeoutException::class, fn () => $rt->eval('', timeoutMs: 100));
            eq(false, $rt->reset());
        });
    }
    check("$mode: completed and failed calls leave no stale timer", function () use ($isolated) {
        $rt = timedGuest($isolated);
        eq(42, $rt->eval('', timeoutMs: 100));
        $rt->register('execute', fn (): int => 2); // fixture executes unreachable
        throws(TrapException::class, fn () => $rt->eval('', timeoutMs: 100));
        $rt->register('execute', function (): bool { usleep(150_000); return false; });
        eq(42, $rt->eval('', timeoutMs: 1000));
        eq(42, $rt->eval('', timeoutMs: 0));
        $rt->reset();
        eq(42, $rt->eval('', timeoutMs: 1000));
    });
}

check('shared calls rearm shrinking deadlines without resetting the instance', function () {
    $rt = timedGuest();
    $initializations = 0;
    $rt->register('initialize', function () use (&$initializations): bool { $initializations++; return false; });
    foreach ([1000, 500, 250] as $ms) {
        eq(42, $rt->check('', timeoutMs: $ms));
        eq(42, $rt->eval('', timeoutMs: $ms));
    }
    eq(1, $initializations);
    $rt->register('execute', fn (): bool => true);
    throws(TimeoutException::class, fn () => $rt->eval('', timeoutMs: 30));
    $rt->register('execute', fn (): bool => false);
    eq(42, $rt->eval('', timeoutMs: 1000));
    eq(2, $initializations);
});

check('isolated inner timeout does not expire the outer Store', function () {
    $rt = timedGuest(true);
    $depth = 0;
    $rt->register('execute', function () use ($rt, &$depth): bool {
        if ($depth > 0) return true;
        $depth++;
        try {
            throws(TimeoutException::class, fn () => $rt->eval('', timeoutMs: 30));
        } finally {
            $depth--;
        }
        return false;
    });
    eq(42, $rt->eval('', timeoutMs: 1000));
    eq(42, $rt->eval('', timeoutMs: 0));
    $rt->register('execute', fn (): bool => false); // release self capture
});

check('shared recursive calls remain rejected without poisoning the outer call', function () {
    $rt = timedGuest();
    $rt->register('execute', function () use ($rt): bool {
        throws(TerrariumException::class, fn () => $rt->check('', timeoutMs: 30));
        return false;
    });
    eq(42, $rt->eval('', timeoutMs: 1000));
    $rt->register('execute', fn (): bool => false);
    eq(42, $rt->eval('', timeoutMs: 1000));
    eq(true, $rt->reset());
});

check('isolated outer expiry cannot prematurely interrupt a longer or unbounded child', function () {
    foreach ([1000, 0] as $innerTimeout) {
        $rt = timedGuest(true);
        $depth = 0;
        $innerResult = null;
        $rt->register('execute', function () use ($rt, &$depth, &$innerResult, $innerTimeout): bool {
            if ($depth > 0) { usleep(70_000); return false; }
            $depth++;
            try {
                $innerResult = $rt->eval('', timeoutMs: $innerTimeout);
            } finally {
                $depth--;
            }
            return false;
        });
        throws(TimeoutException::class, fn () => $rt->eval('', timeoutMs: 30));
        eq(42, $innerResult);
        $rt->register('execute', fn (): bool => false);
    }
});

check('negative constructor timeouts retain their legacy unbounded meaning', function () {
    eq(42, timedGuest(default: -1)->eval(''));
});

check('invalid native timeout types cannot silently select an unbounded default', function () {
    $rt = timedGuest();
    $calls = 0;
    $rt->register('execute', function () use (&$calls): bool { $calls++; return false; });
    foreach (['eval', 'check', 'analyze'] as $entry) {
        $parameter = (new ReflectionMethod($rt, $entry))->getParameters()[1];
        eq('timeoutMs', $parameter->getName());
        eq('?int', (string) $parameter->getType());
        eq(true, $parameter->isOptional());
        foreach (['30', 'invalid', 1.5, true, [], new stdClass] as $invalid) {
            throws(TerrariumException::class, fn () => $rt->$entry('', timeoutMs: $invalid));
        }
    }
    eq(0, $calls);
    eq(false, $rt->reset());
    eq(42, $rt->eval('', timeoutMs: null));
});

summary();

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

summary();

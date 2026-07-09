<?php

// Host ABI hardening: a malicious or corrupt guest can hand the host an
// out-of-bounds (ptr, len) — across the host_call import, or in its packed
// eval() return. The host must reject these as a *catchable* exception, never
// abort the process. Without the range_in_bounds() guard (src/lib.rs) a negative
// i32 length sign-extends to a ~16 EiB allocation and SIGABRTs the PHP host.
//
// The adversarial guests are hand-written WAT (Wasmtime parses text directly),
// so no engine build is needed.

declare(strict_types=1);

require __DIR__ . '/_harness.php';
use Terrarium\Terrarium;
use Terrarium\Exception as TerrariumException;
use Terrarium\TrapException;
use Terrarium\TimeoutException;
use Terrarium\MemoryException;
use Terrarium\GuestException;

$adv = __DIR__ . '/../wasm/adversarial';

echo "the host rejects hostile (ptr, len) instead of aborting\n";

// eval() calls host_call with name_len = -1.
check('host_call: a negative length is a catchable trap, not an abort', function () use ($adv) {
    try {
        (new Terrarium("$adv/bad_hostcall_len.wat"))->eval('1');
    } catch (TerrariumException $e) {
        contains($e->getMessage(), 'negative pointer or length');
        return;
    }
    throw new RuntimeException('expected a TerrariumException, nothing thrown');
});

// eval() returns a packed (ptr=0, len=~4 GiB).
check('eval: an oversized packed return length is rejected', function () use ($adv) {
    try {
        (new Terrarium("$adv/bad_return_len.wat"))->eval('1');
    } catch (TerrariumException $e) {
        contains($e->getMessage(), 'out of bounds');
        return;
    }
    throw new RuntimeException('expected a TerrariumException, nothing thrown');
});

// The rejections trap cleanly, so the host stays alive and a real guest works.
check('the host is still alive and usable afterwards', function () {
    $quickjs = require_guest(__DIR__ . '/../wasm/quickjs_guest.wasm');
    eq(2, (new Terrarium($quickjs))->eval('1 + 1'));
});

summary();

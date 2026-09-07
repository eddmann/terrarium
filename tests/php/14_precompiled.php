<?php
// Precompiled guests — compiling ahead of time, loading without Cranelift.
//
// `Terrarium::precompile()` emits the machine code this extension build would
// have produced at construction; `precompiled: true` loads it. A deployment
// that cannot keep a warm module cache (an AWS Lambda cold start) pays a
// deserialize instead of a compile. This suite pins down three things: an
// artifact behaves exactly as the wasm it came from, the flag is required in
// both directions so `Module::deserialize` never sees anything unclaimed, and
// artifacts go through the same process-wide compiled-guest cache as wasm.

declare(strict_types=1);

require __DIR__ . '/_harness.php';
use Terrarium\Terrarium;
use Terrarium\Exception as TerrariumException;
use Terrarium\TrapException;
use Terrarium\TimeoutException;
use Terrarium\GuestException;

$wasm = require_guest(__DIR__ . '/../wasm/quickjs_guest.wasm');

/**
 * The extension binary this process loaded, for the cold-start subprocesses.
 * `-d extension=` is not readable through ini_get, so ask the loader (Linux)
 * and otherwise look where the Makefile builds. Null skips that one check.
 */
function extension_path(): ?string
{
    if (is_readable('/proc/self/maps')) {
        foreach (file('/proc/self/maps') ?: [] as $line) {
            if (preg_match('#(/\S*libterrarium\.(?:so|dylib))#', $line, $m)) {
                return $m[1];
            }
        }
    }
    foreach (['debug', 'release'] as $profile) {
        foreach (['so', 'dylib'] as $suffix) {
            $path = __DIR__ . "/../../target/$profile/libterrarium.$suffix";
            if (is_file($path)) {
                return realpath($path) ?: null;
            }
        }
    }
    return null;
}

// Far too little fuel for the guest to run anything at all; and comfortably
// more than a trivial eval needs (the guest builds a fresh JS runtime per eval).
const FUEL_LOW = 100_000;
const FUEL_AMPLE = 100_000_000;

$tmp = sys_get_temp_dir() . '/terrarium-precompiled-' . getmypid();
@mkdir($tmp);
register_shutdown_function(function () use ($tmp): void {
    foreach (glob("$tmp/*") ?: [] as $file) {
        is_dir($file) ? @rmdir($file) : @unlink($file);
    }
    @rmdir($tmp);
});

/** Write an artifact to disk and return its path. */
function artifact(string $tmp, string $name, string $bytes): string
{
    $path = "$tmp/$name.cwasm";
    file_put_contents($path, $bytes);
    return $path;
}

echo "producing artifacts\n";
$t0 = -hrtime(true);
$plain = artifact($tmp, 'quickjs', Terrarium::precompile($wasm));
$t0 += hrtime(true);
printf("  precompile %.2f s -> %d bytes (wasm %d)\n", $t0 / 1e9, filesize($plain), filesize($wasm));
// Fuel metering is compiled in, so a metered Runtime needs its own artifact.
$metered = artifact($tmp, 'quickjs-fuel', Terrarium::precompile($wasm, fuel: 1));

check('an artifact is not the wasm it came from', function () use ($plain, $wasm) {
    if (file_get_contents($plain) === file_get_contents($wasm)) {
        throw new RuntimeException('precompile() returned its input');
    }
    // Wasmtime's own framing, not a wasm header.
    eq("\0asm", substr(file_get_contents($wasm), 0, 4));
    if (str_starts_with(file_get_contents($plain), "\0asm\x01\0\0\0")) {
        throw new RuntimeException('artifact looks like a wasm module');
    }
});
check('maxStack does not reach the artifact (it is a run-time engine setting)', function () use ($wasm, $plain) {
    // Documented, and verified rather than assumed: only `fuel` changes the
    // emitted code, so the same bytes come back whatever stack bound is named.
    eq(file_get_contents($plain), Terrarium::precompile($wasm, maxStack: 1 << 20));
});
check('portable and native artifacts both load and run here', function () use ($wasm) {
    // `portable` (the default) compiles for the architecture's baseline CPU
    // so the artifact loads on a plainer host; `portable: false` compiles
    // for this machine's features. Both must load and behave the same on the
    // machine that built them. Whether they differ in bytes depends on how
    // far this CPU is above the baseline, so that is printed, not asserted;
    // the baseline-vs-native refusal itself is pinned in the Rust unit tests.
    $bytes = file_get_contents($wasm);
    $portable = \Terrarium\Runtime::precompile($bytes);
    $native = \Terrarium\Runtime::precompile($bytes, portable: false);
    printf("  portable %d bytes, native %d bytes (%s)\n", strlen($portable), strlen($native), $portable === $native ? 'identical: a baseline CPU' : 'differ');
    $p = new \Terrarium\Runtime($portable, precompiled: true);
    $n = new \Terrarium\Runtime($native, precompiled: true);
    $p->register('tag', fn (string $s): string => "p:$s");
    $n->register('tag', fn (string $s): string => "n:$s");
    eq(['p:x', 'n:x'], [$p->eval('tag("x")'), $n->eval('tag("x")')]);
    eq(42, $p->eval('6 * 7'));
    eq(42, $n->eval('6 * 7'));
});

check('precompile() refuses an artifact as input', function () use ($tmp, $plain) {
    try {
        Terrarium::precompile($plain);
        throw new RuntimeException('expected a refusal');
    } catch (TerrariumException $e) {
        contains($e->getMessage(), 'already a precompiled artifact');
    }
});

// Must run before anything else loads this artifact: the measurement is the
// first (deserializing) construction in this process against a later one.
echo "\nartifacts go through the process-wide compiled-guest cache\n";
$cold = -hrtime(true);
$first = new Terrarium($plain, precompiled: true);
$cold += hrtime(true);
$warm = PHP_INT_MAX;
$second = null;
for ($i = 0; $i < 3; $i++) {
    $run = -hrtime(true);
    $second = new Terrarium($plain, precompiled: true);
    $run += hrtime(true);
    $warm = min($warm, $run);
}
printf("  first construct %.1f ms, cheapest of three more %.1f ms\n", $cold / 1e6, $warm / 1e6);

check('both constructions produce a working Runtime', function () use ($first, $second) {
    eq(2, $first->eval('1 + 1'));
    eq(4, $second->eval('2 + 2'));
});
// Loose on purpose, and looser than 13_shared_engine.php's: a hit still hashes
// the artifact to name it, and an artifact is several times the size of its
// wasm, so in a debug build that hash is most of what a hit costs. The claim
// under test is only that the deserialize is not repeated.
check('a repeat construction over the same artifact does not deserialize again', function () use ($cold, $warm) {
    if ($warm >= $cold) {
        throw new RuntimeException(sprintf(
            'expected a cheaper repeat construct, got %.1f ms against %.1f ms',
            $warm / 1e6,
            $cold / 1e6
        ));
    }
});
check('two Runtimes over one artifact keep separate capability tables', function () use ($plain) {
    $a = new Terrarium($plain, precompiled: true);
    $b = new Terrarium($plain, precompiled: true);
    $a->register('whoami', fn (): string => 'A');
    $b->register('whoami', fn (): string => 'B');
    // Interleaved: a shared InstancePre that captured one bridge would only
    // ever be wrong for whichever Runtime did not build it.
    eq('A', $a->eval('whoami()'));
    eq('B', $b->eval('whoami()'));
    eq('A', $a->eval('whoami()'));
    eq('B', $b->eval('whoami()'));
});
check('an artifact and its wasm are separate cache entries, both usable', function () use ($plain, $wasm) {
    $a = new Terrarium($plain, precompiled: true);
    $w = new Terrarium($wasm);
    eq(2, $a->eval('1 + 1'));
    eq(2, $w->eval('1 + 1'));
});

echo "\nan artifact behaves exactly like the wasm it came from\n";
$w = new Terrarium($wasm);
$a = new Terrarium($plain, precompiled: true);

check('eval returns the same values', function () use ($w, $a) {
    foreach (['1 + 1', '"a" + "b"', '[1, 2, 3].map(n => n * 2)', 'JSON.stringify({a: 1})', 'null'] as $src) {
        eq($w->eval($src), $a->eval($src));
    }
});
check('a registered capability dispatches the same', function () use ($wasm, $plain) {
    $pair = [new Terrarium($wasm), new Terrarium($plain, precompiled: true)];
    $seen = [];
    foreach ($pair as $i => $rt) {
        $rt->register('svc.add', function (int $x, int $y) use (&$seen, $i): int {
            $seen[] = $i;
            return $x + $y;
        });
        eq(7, $rt->eval('svc.add(3, 4)'));
    }
    eq([0, 1], $seen);   // each Runtime called its own closure, once
});
check('output capture is the same', function () use ($w, $a) {
    foreach ([$w, $a] as $rt) {
        $rt->eval('console.log("one"); console.log("two")');
        eq("one\ntwo", $rt->output());
    }
});
check('a guest error is the same', function () use ($w, $a) {
    foreach ([$w, $a] as $rt) {
        try {
            $rt->eval('throw new TypeError("nope")');
            throw new RuntimeException('expected a guest error');
        } catch (GuestException $e) {
            contains($e->getMessage(), 'nope');
        }
    }
});
check('a memory limit binds the same', function () use ($wasm, $plain) {
    $pair = [new Terrarium($wasm, memoryLimit: 24 << 20), new Terrarium($plain, precompiled: true, memoryLimit: 24 << 20)];
    foreach ($pair as $rt) {
        throws(TerrariumException::class, fn () => $rt->eval('new Uint8Array(48 * 1024 * 1024).length'));
        eq(2, $rt->eval('1 + 1'));   // and each recovers
    }
});
check('a timeout trips and recovers the same', function () use ($wasm, $plain) {
    $pair = [new Terrarium($wasm, timeoutMs: 200), new Terrarium($plain, precompiled: true, timeoutMs: 200)];
    foreach ($pair as $rt) {
        throws(TimeoutException::class, fn () => $rt->eval('while (true) {}'));
        eq(4, $rt->eval('2 + 2'));
    }
});
check('fuel meters the same, from an artifact built with fuel enabled', function () use ($wasm, $metered) {
    $starved = [new Terrarium($wasm, fuel: FUEL_LOW), new Terrarium($metered, precompiled: true, fuel: FUEL_LOW)];
    foreach ($starved as $rt) {
        throws(TimeoutException::class, fn () => $rt->eval('1 + 1'));
    }
    $ample = [new Terrarium($wasm, fuel: FUEL_AMPLE), new Terrarium($metered, precompiled: true, fuel: FUEL_AMPLE)];
    foreach ($ample as $rt) {
        eq(2, $rt->eval('1 + 1'));
    }
});
check('maxStack binds the loading Runtime, not the one that precompiled', function () use ($plain) {
    // The artifact carries no stack bound at all (checked above), so the
    // Runtime that loads it decides — in both directions, from one artifact.
    $recurse = fn (int $depth): string => "const f = n => n <= 0 ? 0 : 1 + f(n - 1); f($depth)";
    $tiny = new Terrarium($plain, precompiled: true, maxStack: 64 * 1024);
    $ample = new Terrarium($plain, precompiled: true, maxStack: 2 << 20);
    eq(1000, $ample->eval($recurse(1000)));
    throws(TrapException::class, fn () => $tiny->eval($recurse(1000)));
    eq(100, $tiny->eval($recurse(100)));
});

echo "\nan artifact is bound to the fuel setting it was built with\n";
check('an artifact built without fuel is refused by a metered Runtime', function () use ($plain) {
    try {
        new Terrarium($plain, precompiled: true, fuel: FUEL_AMPLE);
        throw new RuntimeException('expected a refusal');
    } catch (TerrariumException $e) {
        contains($e->getMessage(), 'fuel support');
        contains($e->getMessage(), 'only loadable by the extension build that produced it');
    }
});
check('an artifact built with fuel is refused by an unmetered Runtime', function () use ($metered) {
    try {
        new Terrarium($metered, precompiled: true);
        throw new RuntimeException('expected a refusal');
    } catch (TerrariumException $e) {
        contains($e->getMessage(), 'fuel support');
    }
});
check('the fuel *budget* is not part of the artifact', function () use ($metered) {
    // Only "metered or not" is compiled in; any positive budget loads.
    eq(2, (new Terrarium($metered, precompiled: true, fuel: FUEL_AMPLE))->eval('1 + 1'));
    eq(2, (new Terrarium($metered, precompiled: true, fuel: 200_000_000))->eval('1 + 1'));
});

echo "\nthe flag is explicit in both directions\n";
check('garbage bytes with precompiled: true are refused before deserialize', function () use ($tmp) {
    foreach (['random' => random_bytes(4096), 'text' => 'not a module at all', 'empty' => ''] as $name => $bytes) {
        $path = artifact($tmp, "junk-$name", $bytes);
        try {
            new Terrarium($path, precompiled: true);
            throw new RuntimeException("expected $name to be refused");
        } catch (TerrariumException $e) {
            contains($e->getMessage(), 'not a Wasmtime precompiled module');
        }
    }
});
check('a forged artifact never reaches Module::deserialize', function () use ($tmp, $plain, $wasm) {
    // `Engine::detect_precompiled` is the whole guard in front of an `unsafe`
    // deserialize, so what it rejects matters. It parses Wasmtime's container,
    // not just the leading ELF header: a genuine header over a truncated or
    // zero-filled body -- the shape a corrupted download or a half-written
    // deploy artifact takes -- is refused by the constructor, and the bytes
    // are never handed to `Module::deserialize` at all.
    $real = file_get_contents($plain);
    eq("\x7fELF", substr($real, 0, 4));
    $forged = [
        'header only' => substr($real, 0, 64),
        'header + zeros' => substr($real, 0, 64) . str_repeat("\0", 64 * 1024),
        'truncated in half' => substr($real, 0, intdiv(strlen($real), 2)),
        'header + wasm body' => substr($real, 0, 64) . file_get_contents($wasm),
    ];
    foreach ($forged as $name => $bytes) {
        $path = artifact($tmp, 'forged-' . str_replace(' ', '-', $name), $bytes);
        try {
            new Terrarium($path, precompiled: true);
            throw new RuntimeException("expected $name to be refused");
        } catch (TerrariumException $e) {
            // A refusal, not a crash, and it is the framing check speaking.
            eq(true, str_starts_with($e->getMessage(), 'precompiled: '));
            contains($e->getMessage(), 'not a Wasmtime precompiled module');
        }
    }
    // Nothing was cached for any of them: the genuine artifact still loads.
    eq(2, (new Terrarium($plain, precompiled: true))->eval('1 + 1'));
});
check('an artifact whose identity blob is unreadable is refused by deserialize', function () use ($tmp, $plain) {
    // The other side of that boundary: a container Wasmtime does recognise,
    // whose *identity* it cannot accept. Only the metadata sections are
    // blanked -- never `.text` -- so the failure is Wasmtime's own
    // deterministic version/settings check and no compiled code is ever
    // deserialized, let alone run.
    $sections = function (string $b): array {
        if (substr($b, 0, 5) !== "\x7fELF\x02") {
            return [];   // not ELF64: nothing to poke at
        }
        $shoff = unpack('P', substr($b, 0x28, 8))[1];
        $shentsize = unpack('v', substr($b, 0x3A, 2))[1];
        $shnum = unpack('v', substr($b, 0x3C, 2))[1];
        $shstrndx = unpack('v', substr($b, 0x3E, 2))[1];
        $entry = fn (int $i): string => substr($b, $shoff + $i * $shentsize, $shentsize);
        $names = unpack('P', substr($entry($shstrndx), 0x18, 8))[1];
        $out = [];
        for ($i = 0; $i < $shnum; $i++) {
            $e = $entry($i);
            $at = $names + unpack('V', substr($e, 0, 4))[1];
            $name = substr($b, $at, strpos($b, "\0", $at) - $at);
            $out[$name] = [unpack('P', substr($e, 0x18, 8))[1], unpack('P', substr($e, 0x20, 8))[1]];
        }
        return $out;
    };
    $real = file_get_contents($plain);
    $found = $sections($real);
    // Wasmtime's own section names; skip rather than guess if the container
    // ever changes shape.
    $targets = array_intersect(['.wasmtime.engine', '.wasmtime.info'], array_keys($found));
    if ($targets === []) {
        printf("  skip: no Wasmtime metadata sections in the artifact container\n");
        return;
    }
    foreach ($targets as $target) {
        [$offset, $size] = $found[$target];
        $blanked = substr_replace($real, str_repeat("\0", $size), $offset, $size);
        eq(strlen($real), strlen($blanked));
        $path = artifact($tmp, 'blanked' . $target, $blanked);
        try {
            new Terrarium($path, precompiled: true);
            throw new RuntimeException("expected a blanked $target to be refused");
        } catch (TerrariumException $e) {
            // Past the framing check and refused by `Module::deserialize`,
            // wrapped with the hint that names the one supported provenance.
            eq(true, str_starts_with($e->getMessage(), 'precompiled: '));
            contains($e->getMessage(), 'only loadable by the extension build that produced it');
        }
    }
    eq(2, (new Terrarium($plain, precompiled: true))->eval('1 + 1'));
});
check('a wasm module with precompiled: true is refused', function () use ($wasm) {
    try {
        new Terrarium($wasm, precompiled: true);
        throw new RuntimeException('expected a refusal');
    } catch (TerrariumException $e) {
        contains($e->getMessage(), 'not a Wasmtime precompiled module');
        contains($e->getMessage(), 'drop precompiled: true');
    }
});
check('an artifact without the flag is refused, with the hint', function () use ($plain) {
    try {
        new Terrarium($plain);
        throw new RuntimeException('expected a refusal');
    } catch (TerrariumException $e) {
        contains($e->getMessage(), 'precompiled Wasmtime artifact');
        contains($e->getMessage(), 'pass precompiled: true');
    }
});
check('a refused load leaves the cache and later loads intact', function () use ($plain, $wasm) {
    try {
        new Terrarium($plain);
    } catch (TerrariumException $e) {
    }
    eq(2, (new Terrarium($plain, precompiled: true))->eval('1 + 1'));
    eq(2, (new Terrarium($wasm))->eval('1 + 1'));
});

// The point of the feature: a process with no usable Wasmtime disk cache — a
// Lambda cold start — pays Cranelift for the whole guest. Measured in
// subprocesses whose XDG_CACHE_HOME is an empty directory, so neither run can
// reach this machine's warm cache. Only the artifact side is asserted; the
// wasm side is a printed comparison and is capped, since how long a cold
// compile takes says more about the box than about this change.
echo "\ncold start without a module cache (typescript guest)\n";
$ts = __DIR__ . '/../wasm/typescript_guest.wasm';
$ext = extension_path();
if (!is_file($ts) || $ext === null) {
    printf("  skip: %s\n", is_file($ts) ? 'cannot locate the loaded extension binary' : 'typescript_guest.wasm not built');
} else {
    /** Run one construct in a subprocess with a cold cache; ms, or null if capped. */
    $cold_construct = function (string $path, bool $precompiled, float $capSeconds) use ($tmp, $ext): ?float {
        $cache = "$tmp/cold-cache";
        @mkdir($cache);
        $cmd = [PHP_BINARY, '-d', "extension=$ext", __DIR__ . '/_precompiled_cold.php', $path, $precompiled ? '1' : '0'];
        $cmd = implode(' ', array_map('escapeshellarg', $cmd));
        $proc = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, ['XDG_CACHE_HOME' => $cache, 'PATH' => getenv('PATH')]);
        if (!is_resource($proc)) {
            throw new RuntimeException('cannot start a subprocess');
        }
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);
        $out = '';
        $deadline = microtime(true) + $capSeconds;
        while (microtime(true) < $deadline) {
            $out .= stream_get_contents($pipes[1]);
            $status = proc_get_status($proc);
            if (!$status['running']) {
                $out .= stream_get_contents($pipes[1]);
                break;
            }
            usleep(20_000);
        }
        $running = proc_get_status($proc)['running'];
        if ($running) {
            proc_terminate($proc, defined('SIGKILL') ? SIGKILL : 9);
        }
        foreach ($pipes as $pipe) {
            fclose($pipe);
        }
        proc_close($proc);
        if ($running) {
            return null;
        }
        if (!preg_match('/^([0-9.]+) ok$/m', $out, $m)) {
            throw new RuntimeException("subprocess failed: $out");
        }
        return (float) $m[1];
    };

    $t0 = -hrtime(true);
    $tsArtifact = artifact($tmp, 'typescript', Terrarium::precompile($ts));
    $t0 += hrtime(true);
    printf("  precompile %.2f s -> %d bytes (wasm %d)\n", $t0 / 1e9, filesize($tsArtifact), filesize($ts));

    $fromWasm = $cold_construct($ts, false, 180.0);
    printf("  cold construct from wasm     %s\n", $fromWasm === null ? '> 180 s (capped)' : sprintf('%.0f ms', $fromWasm));
    check('a cold process constructs the typescript guest from its artifact', function () use ($cold_construct, $tsArtifact) {
        $fromArtifact = $cold_construct($tsArtifact, true, 180.0);
        if ($fromArtifact === null) {
            throw new RuntimeException('the artifact construct did not finish within 180 s');
        }
        printf("  cold construct from artifact %.0f ms\n", $fromArtifact);
    });
}

summary();

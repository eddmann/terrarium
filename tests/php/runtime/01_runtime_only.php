<?php
// The runtime-only build — the extension without the `compiler` Cargo feature.
//
// This is the deployment shape: a process that never compiles WebAssembly,
// loads `.cwasm` artifacts a full build produced in the release pipeline, and
// is correspondingly smaller. The suite pins down both halves of that claim:
//
//   1. the two entry points that would need Cranelift refuse, clearly, and the
//      PHP surface is otherwise the same class with the same methods, so a
//      caller probing it (`class_exists`, `method_exists`) sees one shape;
//   2. everything else is unchanged — an artifact behaves exactly as it does
//      under the full build, limits and isolation and the capability bridge
//      included. In particular the artifacts here were produced by the FULL
//      build of this same source tree, so loading them at all is the proof
//      that trimming the compiler out did not disturb the engine `Config` an
//      artifact is bound to.
//
// It lives outside the `[0-9]*.php` glob on purpose: it needs a second build
// and a directory of artifacts, which `make test-runtime` arranges.
//
//   php -d extension=target/runtime/<profile>/libterrarium.so \
//       tests/php/runtime/01_runtime_only.php <artifact-dir>
//
// (or TERRARIUM_ARTIFACTS=<artifact-dir>). The directory is what
// `tools/precompile-guests.php` writes: one `.cwasm` per guest.

declare(strict_types=1);

require __DIR__ . '/../_harness.php';

use Terrarium\Runtime;
use Terrarium\Exception as TerrariumException;
use Terrarium\GuestException;
use Terrarium\MemoryException;
use Terrarium\TimeoutException;

$dir = getenv('TERRARIUM_ARTIFACTS') ?: ($argv[1] ?? '');
if ($dir === '' || !is_dir($dir)) {
    fwrite(STDERR, "usage: 01_runtime_only.php <artifact-dir>  (or TERRARIUM_ARTIFACTS=<dir>)\n");
    exit(2);
}

/**
 * The artifact bytes for a guest. A missing one is a broken pipeline, not a
 * skip: the guest fixtures are committed, and `make test-runtime` precompiles
 * both before running this — a green exit that asserted nothing would hide
 * exactly the failure this suite exists to catch.
 */
function artifact(string $dir, string $guest): string
{
    $path = "$dir/{$guest}_guest.cwasm";
    if (!is_file($path)) {
        fwrite(STDERR, "FAIL: $path not found (precompile it with the full build first)\n");
        exit(1);
    }
    return (string) file_get_contents($path);
}

$quickjs = artifact($dir, 'quickjs');
$typescript = artifact($dir, 'typescript');
$quickjsFuel = artifact("$dir/fuel", 'quickjs');
$wasm = (string) file_get_contents(require_guest(__DIR__ . '/../../wasm/quickjs_guest.wasm'));

echo "the build announces itself\n";
check('hasCompiler() is false, on the class and through the facade', function () {
    eq(false, Runtime::hasCompiler());
    eq(false, \Terrarium\Terrarium::hasCompiler());
});
check('the class surface is the full build\'s, method for method', function () {
    // A caller that probes the extension must not have to know which build it
    // got: every method still exists, `precompile` included.
    $rt = new ReflectionClass(Runtime::class);
    foreach ([
        '__construct', 'precompile', 'hasCompiler', 'register', 'manifest',
        'setTypes', 'setCompileOptions', 'grant', 'resolve', 'revoke',
        'eval', 'check', 'analyze', 'output', 'reset',
    ] as $method) {
        eq(true, $rt->hasMethod($method));
    }
});
check('every exception class is still registered', function () {
    foreach ([
        TerrariumException::class, GuestException::class, MemoryException::class,
        TimeoutException::class, \Terrarium\TrapException::class,
    ] as $class) {
        eq(true, class_exists($class));
    }
});

echo "\nwhat needs a compiler refuses, and says which build it is\n";
check('constructing from WebAssembly is refused', function () use ($wasm) {
    try {
        new Runtime($wasm);
        throw new RuntimeException('expected a refusal');
    } catch (TerrariumException $e) {
        contains($e->getMessage(), 'runtime-only');
        contains($e->getMessage(), 'precompiled: true');
    }
});
check('so is WebAssembly with precompiled: false spelled out', function () use ($wasm) {
    try {
        new Runtime($wasm, precompiled: false);
        throw new RuntimeException('expected a refusal');
    } catch (TerrariumException $e) {
        contains($e->getMessage(), 'runtime-only');
    }
});
check('precompile() is refused', function () use ($wasm) {
    try {
        Runtime::precompile($wasm);
        throw new RuntimeException('expected a refusal');
    } catch (TerrariumException $e) {
        contains($e->getMessage(), 'runtime-only');
        contains($e->getMessage(), '`compiler` feature');
    }
});
check('a refusal leaves the process able to load an artifact', function () use ($wasm, $quickjs) {
    try {
        new Runtime($wasm);
    } catch (TerrariumException $e) {
    }
    eq(2, (new Runtime($quickjs, precompiled: true))->eval('1 + 1'));
});
check('a fuel-metered artifact loads, meters, and refuses an unmetered Runtime', function () use ($quickjsFuel) {
    // The one artifact-bound option a deployment toggles, exercised positively:
    // the runtime-only build must run a `--fuel` artifact, not just refuse the
    // mismatched pairing below.
    $rt = new Runtime($quickjsFuel, precompiled: true, fuel: 100_000_000);
    eq(2, $rt->eval('1 + 1'));
    $starved = new Runtime($quickjsFuel, precompiled: true, fuel: 100_000);
    throws(TimeoutException::class, fn () => $starved->eval('1 + 1'));
    throws(TerrariumException::class, fn () => new Runtime($quickjsFuel, precompiled: true));
    $capped = new Runtime($quickjsFuel, precompiled: true, fuel: 100_000_000, maxStack: 2 << 20);
    eq(3, $capped->eval('1 + 2'));
});
check('an artifact still binds to the fuel setting it was built with', function () use ($quickjs) {
    // Load-side half of 14_precompiled.php (whose precompile-side half cannot
    // run here at all): fuel metering is compiled into the artifact, so a
    // metered Runtime over an unmetered one is refused by `Module::deserialize`
    // -- the same deterministic identity check, in the build without a compiler.
    try {
        new Runtime($quickjs, precompiled: true, fuel: 100_000_000);
        throw new RuntimeException('expected a refusal');
    } catch (TerrariumException $e) {
        contains($e->getMessage(), 'fuel support');
        contains($e->getMessage(), 'only loadable by the extension build that produced it');
    }
});
check('the artifact-shaped mistakes still get their own messages', function () use ($quickjs) {
    // The runtime-only refusal is not a catch-all: an artifact loaded without
    // the flag, and junk loaded with it, are told what is actually wrong.
    try {
        new Runtime($quickjs);
        throw new RuntimeException('expected a refusal');
    } catch (TerrariumException $e) {
        contains($e->getMessage(), 'pass precompiled: true');
    }
    try {
        new Runtime('not a module at all', precompiled: true);
        throw new RuntimeException('expected a refusal');
    } catch (TerrariumException $e) {
        contains($e->getMessage(), 'not a Wasmtime precompiled module');
    }
});

echo "\na full build's artifact runs here unchanged (quickjs)\n";
check('eval marshals values back', function () use ($quickjs) {
    $rt = new Runtime($quickjs, precompiled: true);
    eq(2, $rt->eval('1 + 1'));
    eq('ab', $rt->eval('"a" + "b"'));
    eq([2, 4, 6], $rt->eval('[1, 2, 3].map(n => n * 2)'));
    eq(null, $rt->eval('null'));
});
check('a registered capability dispatches to its own PHP closure', function () use ($quickjs) {
    $a = new Runtime($quickjs, precompiled: true);
    $b = new Runtime($quickjs, precompiled: true);
    $seen = [];
    $a->register('svc.add', function (int $x, int $y) use (&$seen): int {
        $seen[] = 'a';
        return $x + $y;
    });
    $b->register('svc.add', function (int $x, int $y) use (&$seen): int {
        $seen[] = 'b';
        return $x * $y;
    });
    eq(7, $a->eval('svc.add(3, 4)'));
    eq(12, $b->eval('svc.add(3, 4)'));
    eq(['a', 'b'], $seen);
    eq(['svc.add'], $a->manifest());
});
check('output is captured per eval, and survives a guest error', function () use ($quickjs) {
    $rt = new Runtime($quickjs, precompiled: true);
    $rt->eval('console.log("one"); console.log("two")');
    eq("one\ntwo", $rt->output());
    try {
        $rt->eval('console.log("before"); throw new TypeError("nope")');
        throw new RuntimeException('expected a guest error');
    } catch (GuestException $e) {
        contains($e->getMessage(), 'nope');
    }
    eq('before', $rt->output());
});
check('a timeout trips as TimeoutException and the Runtime recovers', function () use ($quickjs) {
    $rt = new Runtime($quickjs, precompiled: true, timeoutMs: 200);
    throws(TimeoutException::class, fn () => $rt->eval('while (true) {}'));
    eq(4, $rt->eval('2 + 2'));
});
check('a per-call timeout overrides the unbounded default', function () use ($quickjs) {
    $rt = new Runtime($quickjs, precompiled: true);
    throws(TimeoutException::class, fn () => $rt->eval('for (;;) {}', timeoutMs: 200));
    eq(4, $rt->eval('2 + 2'));
});
check('a memory limit below the guest\'s own minimum is a MemoryException', function () use ($quickjs) {
    // The host bound, refused before the guest ever starts -- and a subclass
    // of Terrarium\Exception, so a caller that only knows the base still sees it.
    $rt = new Runtime($quickjs, precompiled: true, memoryLimit: 64 * 1024);
    throws(MemoryException::class, fn () => $rt->eval('1 + 1'));
    throws(TerrariumException::class, fn () => $rt->eval('1 + 1'));
});
check('a growth ceiling contains the guest and the Runtime recovers', function () use ($quickjs) {
    $rt = new Runtime($quickjs, precompiled: true, memoryLimit: 24 << 20);
    throws(TerrariumException::class, fn () => $rt->eval('new Uint8Array(48 * 1024 * 1024).length'));
    eq(2, $rt->eval('1 + 1'));
});
check('shared (the default) keeps one instance; reset() drops it', function () use ($quickjs) {
    $rt = new Runtime($quickjs, precompiled: true);
    eq(2, $rt->eval('1 + 1'));
    eq(true, $rt->reset());      // an instance existed
    eq(false, $rt->reset());     // nothing left to drop
    eq(4, $rt->eval('2 + 2'));   // lazily rebuilt
});
check('isolated: true runs every eval in a fresh instance', function () use ($quickjs) {
    $rt = new Runtime($quickjs, precompiled: true, isolated: true);
    eq(2, $rt->eval('1 + 1'));
    eq(4, $rt->eval('2 + 2'));
    eq(false, $rt->reset());     // nothing persistent to reset
});
check('handles cross the boundary as opaque integers', function () use ($quickjs) {
    $rt = new Runtime($quickjs, precompiled: true);
    $object = new ArrayObject(['a' => 1]);
    $handle = $rt->grant($object);
    $rt->register('res.get', fn (int $h): array => (array) $rt->resolve($h));
    eq(['a' => 1], $rt->eval("res.get($handle)"));
    eq(true, $rt->revoke($handle));
    eq(false, $rt->revoke($handle));
});
check('two Runtimes over one artifact share the compilation, not the state', function () use ($quickjs) {
    $a = new Runtime($quickjs, precompiled: true);
    $b = new Runtime($quickjs, precompiled: true);
    $a->register('whoami', fn (): string => 'A');
    $b->register('whoami', fn (): string => 'B');
    eq('A', $a->eval('whoami()'));
    eq('B', $b->eval('whoami()'));
    eq('A', $a->eval('whoami()'));
});

echo "\nWASI preview1 is still linked (the guest imports it)\n";
check('a guest that needs a clock and fd_write runs', function () use ($quickjs) {
    $rt = new Runtime($quickjs, precompiled: true);
    // `Date.now()` reaches wasi clock_time_get; console.log reaches fd_write.
    eq(true, $rt->eval('typeof Date.now() === "number" && Date.now() > 0'));
    $rt->eval('console.log("wasi")');
    eq('wasi', $rt->output());
});

echo "\nthe typescript guest: setTypes, check, analyze, eval\n";
const SDK = 'declare const ctx: { agent<T>(input: unknown): T; emit(value: unknown): void };
declare function user_fetch(id: number): { name: string; roles: string[] };';

check('typed source checks clean and runs', function () use ($typescript) {
    $rt = new Runtime($typescript, precompiled: true);
    $rt->setTypes(SDK);
    eq([], $rt->check('const x: number = 1 + 2 * 3; x'));
    eq(7, $rt->eval('const x: number = 1 + 2 * 3; x'));
});
check('check() rejects against the registered SDK without executing', function () use ($typescript) {
    $rt = new Runtime($typescript, precompiled: true);
    $called = false;
    $rt->register('user.fetch', function (int $id) use (&$called): array {
        $called = true;
        return ['name' => 'Ada'];
    });
    $rt->setTypes('declare const user: { fetch(id: number): { name: string } };');
    $diagnostics = $rt->check('user.fetch("42")');
    eq(1, count($diagnostics));
    eq('TS2345', $diagnostics[0]['type']);
    contains($diagnostics[0]['message'], "type 'string' is not assignable");
    eq(1, $diagnostics[0]['line']);
    eq(false, $called);
    // And the same rejection through eval(), which checks before it runs.
    throws(GuestException::class, fn () => $rt->eval('user.fetch("42")'));
    eq(false, $called);
});
check('a correct call type-checks and reaches the PHP closure', function () use ($typescript) {
    $rt = new Runtime($typescript, precompiled: true);
    $rt->register('user.fetch', fn (int $id): array => ['name' => 'Ada', 'roles' => ['admin', 'dev']]);
    $rt->setTypes('declare const user: { fetch(id: number): { name: string; roles: string[] } };');
    eq('Ada has 2 roles', $rt->eval(<<<'TS'
        const u = user.fetch(42);
        `${u.name} has ${u.roles.length} roles`
        TS));
});
check('analyze() returns the diagnostics check() does, plus schemas', function () use ($typescript) {
    $rt = new Runtime($typescript, precompiled: true);
    $rt->setTypes(SDK);
    $rt->setCompileOptions(['type_argument_schemas' => ['ctx.agent']]);
    $source = "interface Verdict { id: string; score: number }\nconst r = ctx.agent<Verdict>({});\nr;\n";
    $analysis = $rt->analyze($source);
    eq([], $analysis['diagnostics']);
    eq($rt->check($source), $analysis['diagnostics']);
    eq(1, count($analysis['schemas']));
    eq(0, $analysis['schemas'][0]['ordinal']);
    eq('ctx.agent', $analysis['schemas'][0]['callee']);
    eq(2, $analysis['schemas'][0]['line']);
    eq(
        '{"type":"object","properties":{"id":{"type":"string"},"score":{"type":"number"}},"required":["id","score"],"additionalProperties":false}',
        $analysis['schemas'][0]['schema']
    );
});
check('the deployment\'s compile options: sync_only plus schemas for two callees', function () use ($typescript) {
    $rt = new Runtime($typescript, precompiled: true);
    $rt->setTypes('declare const ctx: { model<T>(input: unknown): T; agent<T>(input: unknown): T };');
    $rt->setCompileOptions(['sync_only' => true, 'type_argument_schemas' => ['ctx.model', 'ctx.agent']]);
    $analysis = $rt->analyze(
        "interface A { id: string }\ninterface B { n: number }\n"
        . "const a = ctx.model<A>({});\nconst b = ctx.agent<B>({});\n[a, b];\n"
    );
    eq([], $analysis['diagnostics']);
    eq(['ctx.model', 'ctx.agent'], array_column($analysis['schemas'], 'callee'));
    eq([3, 4], array_column($analysis['schemas'], 'line'));
    // sync_only is enforced by the guest at compile time, before anything runs.
    $diagnostics = $rt->check("async function f() { return 1 }\nf();\n");
    eq(['TSSyncOnly'], array_unique(array_column($diagnostics, 'type')));
    throws(GuestException::class, fn () => $rt->eval('await Promise.resolve(1)'));
    eq(3, $rt->eval('1 + 2'));
});
check('the typescript guest captures output and honours the memory limit', function () use ($typescript) {
    $rt = new Runtime($typescript, precompiled: true);
    $rt->setTypes(SDK);
    $rt->eval('console.log("hello from ts")');
    contains($rt->output(), 'hello from ts');
    $rt = new Runtime($typescript, precompiled: true, memoryLimit: 1024 * 1024);
    throws(MemoryException::class, fn () => $rt->eval('1 + 1'));
});
check('the typescript guest honours the containment knobs too', function () use ($typescript) {
    $rt = new Runtime($typescript, precompiled: true, timeoutMs: 2000);
    $rt->setTypes(SDK);
    throws(TimeoutException::class, fn () => $rt->eval('while (true) {}'));
    eq(4, $rt->eval('2 + 2'));
});
check('isolated: true works for the typescript guest', function () use ($typescript) {
    $rt = new Runtime($typescript, precompiled: true, isolated: true);
    $rt->register('emit', fn (string $s): string => "e:$s");
    $rt->setTypes('declare function emit(s: string): string;');
    eq('e:x', $rt->eval('emit("x")'));
    eq('e:y', $rt->eval('emit("y")'));
    eq(false, $rt->reset());
});

summary();

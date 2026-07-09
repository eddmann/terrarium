<?php
// Multi-level capability namespaces + typed declarations, end to end.
// A dotted name like `math.add` is reached as `math.add(...)` (nested member
// access, no synthetic root) in every guest, and `types('dts')` renders the
// registered surface as a TypeScript declaration with nested namespaces
// (`types('pyi')` for Python).

declare(strict_types=1);

require __DIR__ . '/_harness.php';
use Terrarium\Terrarium;
use Terrarium\Exception as TerrariumException;
use Terrarium\TrapException;
use Terrarium\TimeoutException;
use Terrarium\MemoryException;
use Terrarium\GuestException;

echo "multi-level facade (each guest)\n";
foreach ([
    'Boa'        => 'boa_guest.wasm',
    'RustPython' => 'rustpython_guest.wasm',
    'QuickJS-ng' => 'quickjs_guest.wasm',
    'TypeScript' => 'typescript_guest.wasm',   // the shared sources are valid (typed) TS
] as $engine => $file) {
    $path = __DIR__ . "/../wasm/$file";
    if (!is_file($path)) {
        fwrite(STDERR, "SKIP $engine: $file not built\n");
        continue;
    }
    check("$engine: math.add(2, 3) == 5", function () use ($path) {
        $g = new Terrarium($path, timeoutMs: 5000);
        $g->register('math.add', fn (int $a, int $b) => $a + $b);
        eq(5, $g->eval('math.add(2, 3)'));
    });
    check("$engine: deep api.v1.hello('Ada')", function () use ($path) {
        $g = new Terrarium($path, timeoutMs: 5000);
        $g->register('api.v1.hello', fn (string $n) => "hi $n");
        eq('hi Ada', $g->eval('api.v1.hello("Ada")'));
    });
    check("$engine: flat names are top-level globals", function () use ($path) {
        $g = new Terrarium($path, timeoutMs: 5000);
        $g->register('ping', fn () => 'pong');
        eq('pong', $g->eval('ping()'));
    });
}

echo "\nregister() validates capability names\n";
check('a bridge-reserved $-name is rejected', function () {
    $g = new Terrarium(__DIR__ . '/../wasm/boa_guest.wasm');
    throws(InvalidArgumentException::class, fn () => $g->register('$out', fn () => null));
});
check('a non-identifier segment is rejected', function () {
    $g = new Terrarium(__DIR__ . '/../wasm/boa_guest.wasm');
    foreach (['a..b', 'has-dash', '9bad', 'trailing.', ''] as $bad) {
        throws(InvalidArgumentException::class, fn () => $g->register($bad, fn () => null));
    }
});
check('a prelude-machinery name is rejected', function () {
    $g = new Terrarium(__DIR__ . '/../wasm/boa_guest.wasm');
    throws(InvalidArgumentException::class, fn () => $g->register('__host', fn () => null));
});
check('shadowing a guest builtin warns (but registers)', function () {
    $g = new Terrarium(__DIR__ . '/../wasm/boa_guest.wasm');
    $warned = null;
    set_error_handler(function ($no, $msg) use (&$warned) { $warned = $msg; return true; }, E_USER_WARNING);
    try {
        $g->register('console.log', fn (string $m) => null);
    } finally {
        restore_error_handler();
    }
    contains((string)$warned, "shadows the guest builtin 'console'");
    eq(['console.log'], $g->manifest());   // warned, not rejected
});

echo "\nmanifest is the audit surface\n";
check('manifest() lists registered names, sorted', function () {
    $g = new Terrarium(__DIR__ . '/../wasm/boa_guest.wasm');
    $g->register('greet', fn ($n) => $n);
    $g->register('math.add', fn ($a, $b) => $a + $b);
    eq(['greet', 'math.add'], $g->manifest());
});

echo "\ntyped declarations inferred from the registered SDK\n";
check("types('dts') nests dotted names and uses inferred signatures", function () {
    $g = new Terrarium(__DIR__ . '/../wasm/boa_guest.wasm');
    $g->register('math.add', fn (int $a, int $b): int => $a + $b);
    $g->register('fetchUser', /** @return array{name: string} */ fn (int $id): array => []);
    $g->register('api.v1.hello', fn ($n) => $n); // untyped -> any
    $dts = $g->types('dts');

    foreach ([
        'declare const math: {',
        'readonly add: (a: number, b: number) => number;',
        'declare const fetchUser: (id: number) => { name: string };',
        'declare const api: {',
        'readonly v1: {',
        'readonly hello: (n: any) => any;',
    ] as $needle) {
        contains($dts, $needle);
    }
});
check("types('pyi') emits a Python stub with TypedDicts", function () {
    $g = new Terrarium(__DIR__ . '/../wasm/boa_guest.wasm');
    $g->register('user.fetch', /** @return array{name: string, score?: float} */ fn (int $id): array => []);
    $pyi = $g->types('pyi');
    contains($pyi, 'from typing import Any, Callable, Optional, NotRequired, TypedDict');
    contains($pyi, '(TypedDict):');
    contains($pyi, 'name: str');
    contains($pyi, 'score: NotRequired[float]');
});
check("an unknown types format is rejected", function () {
    $g = new Terrarium(__DIR__ . '/../wasm/boa_guest.wasm');
    throws(InvalidArgumentException::class, fn () => $g->types('xml'));
});

summary();

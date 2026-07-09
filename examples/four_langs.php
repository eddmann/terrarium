<?php
// One PHP host SDK (namespaced + typed), exercised from every bundled guest
// engine: QuickJS-ng and Boa (JavaScript), RustPython (Python), and real
// php-src (PHP-in-PHP). The same uniform `Terrarium` facade serves all four —
// only the loaded `.wasm` differs.
//
//   php -d extension=target/release/libterrarium.so examples/four_langs.php

declare(strict_types=1);

require __DIR__ . '/../lib/Terrarium.php';
use Terrarium\Terrarium;
use Terrarium\Exception as TerrariumException;
use Terrarium\TrapException;
use Terrarium\TimeoutException;
use Terrarium\MemoryException;
use Terrarium\GuestException;

$wasm = fn (string $f) => __DIR__ . "/../tests/wasm/$f";

// ---- HOST (PHP): register the SDK once. Dotted names nest; types are inferred. ----
$log = [];
function wire(Terrarium $g, array &$log): void
{
    // Types are inferred from the PHP signatures (+ PHPDoc) — no type strings.
    $g->register('math.add', fn (int $a, int $b): int => $a + $b);
    $g->register('user.fetch',
        /** @return array{name: string, roles: string[]} */
        fn (int $id): array => ['name' => 'Ada', 'roles' => ['admin', 'dev']]);
    $g->register('log.info', function (string $m) use (&$log): void { $log[] = $m; });
}

// ---- GUEST source: the SAME program in each language, reaching the SDK by name ----
$jsSrc = <<<'JS'
    const u = user.fetch(42);
    log.info(`fetched ${u.name}`);
    `${u.name} / roles=${u.roles.length} / add=${math.add(2, 3)}`
    JS;

// Python is indentation-sensitive: the indented closing marker makes PHP's
// flexible heredoc strip the leading indentation from each line.
$pySrc = <<<'PY'
    u = user.fetch(42)
    log.info("fetched " + u["name"])
    "%s / roles=%d / add=%d" % (u["name"], len(u["roles"]), math.add(2, 3))
    PY;

// PHP reaches the SDK as proxy objects; the top-level `return` is the result.
$phpSrc = <<<'GUEST'
    $u = $user->fetch(42);
    $log->info("fetched " . $u["name"]);
    return sprintf("%s / roles=%d / add=%d", $u["name"], count($u["roles"]), $math->add(2, 3));
    GUEST;

// ---- Run the same program in all four engines ----
foreach ([
    'QuickJS-ng (JavaScript)' => [$wasm('quickjs_guest.wasm'), $jsSrc, 2000],
    'Boa (JavaScript)'        => [$wasm('boa_guest.wasm'), $jsSrc, 2000],
    'RustPython (Python)'     => [$wasm('rustpython_guest.wasm'), $pySrc, 5000],
    'php-src (PHP-in-PHP)'    => [$wasm('php_guest.wasm'), $phpSrc, 5000],
] as $engine => [$path, $src, $budget]) {
    echo "=== $engine ===\n";
    $g = new Terrarium($path, timeoutMs: $budget);
    wire($g, $log);
    echo $g->eval($src), "\n\n";
}

// ---- The typed SDK, emitted for each guest language's author ----
$g = new Terrarium($wasm('boa_guest.wasm'));
wire($g, $log);
echo "--- .d.ts (for a JS/TS guest author) ---\n", $g->types('dts');
echo "\n--- .pyi (for a Python guest author) ---\n", $g->types('pyi');
echo "\n--- .php stub (for a PHP guest author) ---\n", $g->types('php');

// ---- HOST sees the side effects the guests caused ----
echo "\n=== host (PHP) observed log.info calls ===\n";
print_r($log);

<?php
/*
 * ES-surface audit: run every probe in probes.json against the committed
 * TypeScript guest fixture, and record what the CURRENT compiler pin
 * (target ES2020 / lib es2020, see guests/typescript/driver.js) says about the
 * same code.
 *
 * Two independent passes per probe:
 *
 *   engine  eval() with a leading `// @ts-nocheck`, so the ES2020 checker never
 *           gates the code and the result is the ENGINE's verdict alone.
 *           (`kind: "raw"` probes are evaluated verbatim -- a hashbang has to be
 *           the first byte of the file, so it cannot carry the pragma.)
 *   gate    check() on the probe's TypeScript, so the diagnostics are the
 *           CHECKER's verdict at the current pin: exactly what the raise unlocks.
 *
 *   php -d extension=target/debug/libterrarium.so \
 *       guests/typescript/tools/lib-audit/audit.php [--out results.json]
 *
 * Nothing here is rebuilt: it reads tests/wasm/typescript_guest.wasm as
 * committed. Output ordering follows probes.json, so results.json is a stable
 * diff target.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../../../lib/Terrarium.php';

use Terrarium\Terrarium;

$root = dirname(__DIR__, 4);
$wasm = $root . '/tests/wasm/typescript_guest.wasm';
if (!is_file($wasm)) {
    fwrite(STDERR, "typescript_guest.wasm not built (see `make typescript-guest`)\n");
    exit(1);
}

$out = __DIR__ . '/results.json';
$argvOut = array_search('--out', $argv, true);
if ($argvOut !== false && isset($argv[$argvOut + 1])) {
    $out = $argv[$argvOut + 1];
}

$corpus = json_decode(file_get_contents(__DIR__ . '/probes.json'), true, flags: JSON_THROW_ON_ERROR);
$ts = new Terrarium($wasm, timeoutMs: 10000);

$results = [];
$counts = ['pass' => 0, 'fail' => 0, 'absent' => 0];

foreach ($corpus['probes'] as $p) {
    $source = $p['kind'] === 'raw' ? $p['source'] : "// @ts-nocheck\n" . $p['source'];

    $observed = null;
    $threw = null;
    try {
        $observed = $ts->eval($source);
    } catch (Throwable $e) {
        $threw = get_class($e) . ': ' . trim(preg_replace('/\s+/', ' ', $e->getMessage()));
    }

    $matched = $threw === null && $observed === $p['expect'];
    $verdict = match (true) {
        $matched && $p['kind'] === 'absent' => 'absent-as-expected',
        $matched                            => 'implemented',
        default                             => 'MISMATCH',
    };
    $counts[$p['kind'] === 'absent' ? 'absent' : ($matched ? 'pass' : 'fail')]++;

    // What the checker says about the same feature at the pin in driver.js.
    $diags = [];
    foreach ($ts->check($p['check'] ?? $p['source']) as $d) {
        $diags[] = [
            'code' => $d['type'],
            'line' => $d['line'] ?? null,
            'message' => $d['message'],
        ];
    }

    $row = [
        'id' => $p['id'],
        'es' => $p['es'],
        'lib' => $p['lib'],
        'feature' => $p['feature'],
        'kind' => $p['kind'],
        'engine' => [
            'verdict' => $verdict,
            'expected' => $p['expect'],
            'observed' => $threw === null ? $observed : null,
            'threw' => $threw,
        ],
        'gateAtCurrentPin' => [
            'clean' => $diags === [],
            'diagnostics' => $diags,
        ],
    ];
    if (isset($p['note'])) {
        $row['note'] = $p['note'];
    }
    $results[] = $row;

    printf(
        "%-38s engine=%-19s gate=%s\n",
        $p['id'],
        $verdict,
        $diags === [] ? 'clean' : implode(',', array_column($diags, 'code'))
    );
    if ($verdict === 'MISMATCH') {
        printf("    expected %s, got %s\n", var_export($p['expect'], true), $threw ?? var_export($observed, true));
    }
}

$doc = [
    'fixture' => 'tests/wasm/typescript_guest.wasm',
    'fixtureSha256' => hash_file('sha256', $wasm),
    'engine' => 'quickjs-ng v0.16.2',
    'typescript' => '6.0.3',
    'currentPin' => ['target' => 'ES2020', 'lib' => ['lib.es2020.d.ts']],
    'counts' => $counts,
    'probes' => $results,
];
file_put_contents($out, json_encode($doc, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");

printf(
    "\n%d implemented, %d absent-as-expected, %d MISMATCH -> %s\n",
    $counts['pass'],
    $counts['absent'],
    $counts['fail'],
    $out
);
exit($counts['fail'] === 0 ? 0 : 1);

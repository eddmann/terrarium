<?php
/*
 * ES-surface audit: run every probe in probes.json against the committed
 * TypeScript guest fixture, and record what the CURRENT compiler pin says about
 * the same code.
 *
 * The pin is READ OUT OF driver.js rather than restated here. A hard-coded
 * "ES2020" in this file survived the raise to ES2024 and quietly turned the
 * audit's own metadata into a lie — which is exactly the class of drift the
 * audit exists to catch, so it must not be possible to reintroduce by hand.
 *
 * Two independent passes per probe:
 *
 *   engine  eval() with a leading `// @ts-nocheck`, so the checker never gates
 *           the code and the result is the ENGINE's verdict alone.
 *           (`kind: "raw"` probes are evaluated verbatim -- a hashbang has to be
 *           the first byte of the file, so it cannot carry the pragma.)
 *   gate    check() on the probe's TypeScript, so the diagnostics are the
 *           CHECKER's verdict at the current pin: exactly what a raise unlocks.
 *
 *   php -d extension=target/debug/libterrarium.so \
 *       guests/typescript/tools/lib-audit/audit.php [--out results.json] [--parity]
 *
 * --parity turns the cross-product of those two passes into a failure
 * condition. The rule the whole audit serves is that the type environment must
 * equal the execution environment, and the dangerous half of that is
 * one-directional:
 *
 *   check() clean + eval() throws   the checker promised something the engine
 *                                   cannot do, so the code publishes clean and
 *                                   dies on its first run. FAILS the audit.
 *   check() errors + eval() works   the checker is merely conservative. The pin
 *                                   is deliberately narrower than the engine,
 *                                   so this is expected, and reported as
 *                                   information rather than as a failure.
 *
 * `new Intl.NumberFormat()` and `class C { accessor x = 1 }` were both in the
 * first category before the fixes that added this mode; --parity is what would
 * have caught them.
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
$parity = in_array('--parity', $argv, true);

/**
 * The checker pin, read from driver.js — the single place it is declared.
 *
 * @return array{target: string, lib: list<string>}
 */
function currentPin(string $driverPath): array
{
    $driver = file_get_contents($driverPath);
    if ($driver === false) {
        fwrite(STDERR, "cannot read $driverPath\n");
        exit(1);
    }
    if (!preg_match('/var TARGET = ts\.ScriptTarget\.(\w+);/', $driver, $t)
        || !preg_match('/var LIB_ROOT = "([^"]+)";/', $driver, $l)) {
        fwrite(STDERR, "cannot find TARGET / LIB_ROOT in $driverPath — did the declaration move?\n");
        exit(1);
    }
    return ['target' => $t[1], 'lib' => [$l[1]]];
}

$pin = currentPin(dirname(__DIR__, 2) . '/driver.js');
printf("checker pin (from driver.js): target %s, lib %s\n\n", $pin['target'], implode(', ', $pin['lib']));

$corpus = json_decode(file_get_contents(__DIR__ . '/probes.json'), true, flags: JSON_THROW_ON_ERROR);
$ts = new Terrarium($wasm, timeoutMs: 10000);

$results = [];
$counts = ['pass' => 0, 'fail' => 0, 'absent' => 0];
$parityBreaches = [];        // check() clean, eval() threw — the dangerous direction
$parityConservative = [];    // check() errored, eval() worked — the intended direction

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

    // PARITY. Run the probe's checked TypeScript through eval() too — without
    // the pragma this time, so the checker really does gate it. The pair
    // (gate clean?, eval throws?) is the whole question the audit asks.
    $gateClean = $diags === [];
    $parityThrew = null;
    try {
        $ts->eval($p['check'] ?? $p['source']);
    } catch (Throwable $e) {
        $parityThrew = get_class($e) . ': ' . trim(preg_replace('/\s+/', ' ', $e->getMessage()));
    }
    // A probe the checker gates never reaches the engine, so its "throw" is the
    // gate speaking, not the engine. Only an ungated probe can breach parity.
    //
    // `AsyncIncomplete` is excluded, and that is not a loophole. It is not the
    // engine failing to implement something the lib declared: `Promise.any`
    // exists and works. It is the sandbox refusing, at run time, a program that
    // cannot finish because there is no event loop — a refusal `check()` is
    // documented NOT to make on its own (see docs/errors.md), and which
    // `syncOnly` exists to move to compile time. Counting it as a lib-parity
    // breach would bury the real ones.
    //
    // The "conservative" direction is read off the ENGINE pass rather than off
    // this one: an ungated eval cannot show it, because eval applies the very
    // gate in question and would just re-raise the type error. The engine pass
    // already answered the question — it ran the same code under `@ts-nocheck`.
    $asyncGuard = $parityThrew !== null && str_contains($parityThrew, 'AsyncIncomplete');
    $parityVerdict = match (true) {
        $gateClean && $asyncGuard => 'async-guard',
        $gateClean && $parityThrew !== null => 'BREACH',
        !$gateClean && $verdict === 'implemented' => 'conservative',
        default => 'agree',
    };
    if ($parityVerdict === 'BREACH') {
        $parityBreaches[] = ['id' => $p['id'], 'threw' => $parityThrew];
    } elseif ($parityVerdict === 'conservative') {
        $parityConservative[] = $p['id'];
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
            'clean' => $gateClean,
            'diagnostics' => $diags,
        ],
        'parity' => [
            'verdict' => $parityVerdict,
            'evalThrew' => $parityThrew,
        ],
    ];
    if (isset($p['note'])) {
        $row['note'] = $p['note'];
    }
    $results[] = $row;

    printf(
        "%-38s engine=%-19s gate=%-28s parity=%s\n",
        $p['id'],
        $verdict,
        $gateClean ? 'clean' : implode(',', array_column($diags, 'code')),
        $parityVerdict
    );
    if ($verdict === 'MISMATCH') {
        printf("    expected %s, got %s\n", var_export($p['expect'], true), $threw ?? var_export($observed, true));
    }
    if ($parityVerdict === 'BREACH') {
        printf("    PARITY BREACH: check() was clean and eval() threw -- %s\n", $parityThrew);
    }
}

$doc = [
    'fixture' => 'tests/wasm/typescript_guest.wasm',
    'fixtureSha256' => hash_file('sha256', $wasm),
    'engine' => 'quickjs-ng v0.16.2',
    'typescript' => '6.0.3',
    'currentPin' => $pin,
    'counts' => $counts,
    'parity' => [
        'breaches' => $parityBreaches,
        'conservative' => $parityConservative,
    ],
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
printf(
    "parity: %d breach(es), %d conservative (checker narrower than the engine, by design)\n",
    count($parityBreaches),
    count($parityConservative)
);
foreach ($parityBreaches as $b) {
    printf("  BREACH %s: %s\n", $b['id'], $b['threw']);
}

$failed = $counts['fail'] > 0 || ($parity && $parityBreaches !== []);
if (!$parity && $parityBreaches !== []) {
    fwrite(STDERR, "note: parity breaches recorded but not enforced; re-run with --parity to fail on them\n");
}
exit($failed ? 1 : 0);

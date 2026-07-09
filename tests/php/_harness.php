<?php

declare(strict_types=1);

// A tiny shared test harness. Not matched by the Makefile's `[0-9]*.php` glob,
// so it is only ever pulled in via require by the numbered suites.

require_once __DIR__ . '/../../lib/Terrarium.php';

$GLOBALS['pass'] = 0;
$GLOBALS['fail'] = 0;

function check(string $label, callable $fn): void
{
    try {
        $fn();
        printf("  ok   %s\n", $label);
        $GLOBALS['pass']++;
    } catch (Throwable $e) {
        printf("  FAIL %s\n         %s: %s\n", $label, get_class($e), $e->getMessage());
        $GLOBALS['fail']++;
    }
}

function eq($expected, $actual): void
{
    if ($expected !== $actual) {
        throw new RuntimeException(sprintf(
            'expected %s, got %s',
            var_export($expected, true),
            var_export($actual, true)
        ));
    }
}

function throws(string $class, callable $fn): void
{
    try {
        $fn();
    } catch (Throwable $e) {
        if (!($e instanceof $class)) {
            throw new RuntimeException(sprintf(
                'expected %s, got %s (%s)',
                $class,
                get_class($e),
                $e->getMessage()
            ));
        }
        return;
    }
    throw new RuntimeException("expected $class, nothing thrown");
}

function contains(string $haystack, string $needle): void
{
    if (!str_contains($haystack, $needle)) {
        throw new RuntimeException("missing:\n  $needle\n--- in ---\n$haystack");
    }
}

/** Skip the suite cleanly if a required guest wasm hasn't been built. */
function require_guest(string $path): string
{
    if (!is_file($path)) {
        fwrite(STDERR, 'SKIP: ' . basename($path) . " not built (see `make boa-guest|rustpython-guest|quickjs-guest|php-guest|typescript-guest`)\n");
        exit(0);
    }
    return $path;
}

function summary(): void
{
    printf("\n%d passed, %d failed\n", $GLOBALS['pass'], $GLOBALS['fail']);
    exit($GLOBALS['fail'] === 0 ? 0 : 1);
}

<?php
// Guest I/O for the agent loop: captured output (console.log / print) and
// structured error messages. The same `output()` accessor and GuestException
// message shape work across every engine; only the printed/thrown syntax differs.

declare(strict_types=1);

require __DIR__ . '/_harness.php';
use Terrarium\Terrarium;
use Terrarium\Exception as TerrariumException;
use Terrarium\TrapException;
use Terrarium\TimeoutException;
use Terrarium\MemoryException;
use Terrarium\GuestException;

// Per-engine source snippets + expectations. JS (Boa) cannot surface a reliable
// source line; QuickJS and Python can.
$engines = [
    'Boa' => [
        'wasm'       => 'boa_guest.wasm',
        'print'      => "console.log('hello', 42)",
        'object'     => "console.log({a: 1})",
        'object_out' => '{"a":1}',
        'partial'    => "console.log('before'); null.field",
        'error'      => 'null.field',
        'error_type' => 'TypeError',
        'has_line'   => false,
    ],
    'QuickJS-ng' => [
        'wasm'       => 'quickjs_guest.wasm',
        'print'      => "console.log('hello', 42)",
        'object'     => "console.log({a: 1})",
        'object_out' => '{"a":1}',
        'partial'    => "console.log('before'); null.field",
        'error'      => 'null.field',
        'error_type' => 'TypeError',
        'has_line'   => true,
    ],
    'RustPython' => [
        'wasm'       => 'rustpython_guest.wasm',
        'print'      => "print('hello', 42)",
        'object'     => "print({'a': 1})",
        'object_out' => "{'a': 1}",
        'partial'    => "print('before')\n1/0",
        'error'      => '1/0',
        'error_type' => 'ZeroDivisionError',
        'has_line'   => true,
    ],
];

foreach ($engines as $name => $c) {
    $path = __DIR__ . '/../wasm/' . $c['wasm'];
    if (!is_file($path)) {
        fwrite(STDERR, "SKIP $name: {$c['wasm']} not built\n");
        continue;
    }

    echo "$name: captured output\n";
    check("$name: console.log/print is captured", function () use ($path, $c) {
        $w = new Terrarium($path, timeoutMs: 5000);
        $w->eval($c['print']);
        eq('hello 42', $w->output());
    });
    check("$name: objects are formatted", function () use ($path, $c) {
        $w = new Terrarium($path, timeoutMs: 5000);
        $w->eval($c['object']);
        eq($c['object_out'], $w->output());
    });
    check("$name: output is empty when nothing is printed", function () use ($path) {
        $w = new Terrarium($path, timeoutMs: 5000);
        $w->eval('1 + 1');
        eq('', $w->output());
    });
    check("$name: output resets per eval", function () use ($path, $c) {
        $w = new Terrarium($path, timeoutMs: 5000);
        $w->eval($c['print']);
        $w->eval('1 + 1');
        eq('', $w->output());          // the previous run's output does not carry over
    });
    check("$name: output before a crash is preserved", function () use ($path, $c) {
        $w = new Terrarium($path, timeoutMs: 5000);
        try { $w->eval($c['partial']); } catch (GuestException $e) {}
        eq('before', $w->output());
    });

    echo "$name: structured errors\n";
    check("$name: error message carries the type", function () use ($path, $c) {
        $w = new Terrarium($path, timeoutMs: 5000);
        try {
            $w->eval($c['error']);
            throw new RuntimeException('expected a guest error');
        } catch (GuestException $e) {
            contains($e->getMessage(), $c['error_type']);
        }
    });
    if ($c['has_line']) {
        check("$name: error message carries the source line", function () use ($path, $c) {
            $w = new Terrarium($path, timeoutMs: 5000);
            try {
                $w->eval($c['error']);
                throw new RuntimeException('expected a guest error');
            } catch (GuestException $e) {
                contains($e->getMessage(), '(line 1)');
            }
        });
    }
}

summary();

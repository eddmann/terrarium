<?php
// Real PHP (php-src) compiled to wasm32-wasi, running as a guest. Proves the
// host extension is language-agnostic beyond the JS/Python engines: the same
// `Terrarium` facade, host_call bridge, and limits run PHP too.
//
// PHP reaches the SDK by name the PHP-idiomatic way -- proxy objects, no
// synthetic root: `$math->add(2, 3)`, `$api->v1->hello('Ada')`, `$ping()`. The
// guest's result is its top-level `return`; `echo`/`print` is captured output.
//
// Skips cleanly until php_guest.wasm is built (see `make php-guest`).

declare(strict_types=1);

require __DIR__ . '/_harness.php';
use Terrarium\Terrarium;
use Terrarium\Exception as TerrariumException;
use Terrarium\TrapException;
use Terrarium\TimeoutException;
use Terrarium\MemoryException;
use Terrarium\GuestException;

$wasm = require_guest(__DIR__ . '/../wasm/php_guest.wasm');

echo "real PHP, evaluated inside wasm\n";
check('arithmetic (top-level return is the result)', function () use ($wasm) {
    $php = new Terrarium($wasm);
    eq(7, $php->eval('return 1 + 2 * 3;'));
});
check('array result becomes a PHP list', function () use ($wasm) {
    $php = new Terrarium($wasm);
    eq([1, 4, 9], $php->eval('return array_map(fn ($n) => $n * $n, [1, 2, 3]);'));
});
check('assoc array round-trips as a map', function () use ($wasm) {
    $php = new Terrarium($wasm);
    eq(['a' => 1, 'b' => true], $php->eval('return ["a" => 1, "b" => true];'));
});

echo "\nguest PHP reaching the SDK (by name)\n";
check('$user->fetch(42) re-enters PHP host', function () use ($wasm) {
    $php = new Terrarium($wasm);
    $php->register('user.fetch', fn (int $id) => ['name' => 'Ada', 'roles' => ['admin', 'dev']]);
    eq('Ada has 2 roles', $php->eval(
        'return sprintf("%s has %d roles", $user->fetch(42)["name"], count($user->fetch(42)["roles"]));'
    ));
});
check('nested namespace $api->v1->hello(...)', function () use ($wasm) {
    $php = new Terrarium($wasm);
    $php->register('api.v1.hello', fn (string $n) => "hi $n");
    eq('hi Ada', $php->eval('return $api->v1->hello("Ada");'));
});
check('flat capability is invokable: $ping()', function () use ($wasm) {
    $php = new Terrarium($wasm);
    $php->register('ping', fn () => 'pong');
    eq('pong', $php->eval('return $ping();'));
});
check('values marshal both ways', function () use ($wasm) {
    $php = new Terrarium($wasm);
    $php->register('sum', fn (array $xs) => array_sum($xs));
    eq(15, $php->eval('return $sum([1, 2, 3, 4, 5]);'));
});

check('capability handle used from inside guest PHP', function () use ($wasm) {
    $php = new Terrarium($wasm);
    $counter = new ArrayObject(['n' => 0]);
    $h = $php->grant($counter);
    $php->register('bump', function (int $handle) use ($php) {
        $o = $php->resolve($handle);
        return ++$o['n'];
    });
    eq([1, 2], $php->eval("return [\$bump($h), \$bump($h)];"));
    eq(2, $counter['n']);
});

echo "\noutput and errors\n";
check('echo is captured as output()', function () use ($wasm) {
    $php = new Terrarium($wasm);
    $php->eval('echo "hello ", 42;');
    eq('hello 42', $php->output());
});
check('a PHP exception surfaces as GuestException', function () use ($wasm) {
    $php = new Terrarium($wasm);
    throws(GuestException::class, fn () => $php->eval('throw new RuntimeException("boom");'));
});
check('output before a crash is preserved', function () use ($wasm) {
    $php = new Terrarium($wasm);
    try { $php->eval('echo "before"; throw new RuntimeException("x");'); } catch (GuestException $e) {}
    eq('before', $php->output());
});

echo "\ncheck(): compile-only (php -l), nothing runs\n";
check('valid source -> no diagnostics; capabilities do NOT fire', function () use ($wasm) {
    $php = new Terrarium($wasm);
    $fired = false;
    $php->register('spy', function () use (&$fired) { $fired = true; });
    eq([], $php->check('return $spy();'));   // compiles — and never executed
    eq(false, $fired);
});
check('a parse error carries type and the source line', function () use ($wasm) {
    $php = new Terrarium($wasm);
    $diags = $php->check("\$a = 1;\n\$b = (2 +;\n");
    eq(1, count($diags));
    eq('ParseError', $diags[0]['type']);
    eq(2, $diags[0]['line']);
});

echo "\nlimits (the epoch trap must unwind through the guest's EH frames)\n";
check('infinite loop contained by the time budget', function () use ($wasm) {
    $php = new Terrarium($wasm, timeoutMs: 1000);
    throws(TimeoutException::class, fn () => $php->eval('while (true) {}'));
});
check('engine recovers after a timeout', function () use ($wasm) {
    $php = new Terrarium($wasm, timeoutMs: 1000);
    try { $php->eval('while (true) {}'); } catch (TimeoutException $e) {}
    eq(4, $php->eval('return 2 + 2;'));
});
check('a tiny memory limit fails cleanly as a TerrariumException', function () use ($wasm) {
    throws(TerrariumException::class, function () use ($wasm) {
        $php = new Terrarium($wasm, memoryLimit: 1 << 20); // 1 MiB: below PHP's baseline
        $php->eval('return 1 + 1;');
    });
});
check('a generous memory limit runs fine', function () use ($wasm) {
    $php = new Terrarium($wasm, memoryLimit: 512 << 20);
    eq(3, $php->eval('return 1 + 2;'));
});

echo "\nexecution modes\n";
check('isolated: every eval() runs in a fresh instance', function () use ($wasm) {
    $php = new Terrarium($wasm, isolated: true);
    eq(2, $php->eval('return 1 + 1;'));
    eq(4, $php->eval('return 2 + 2;'));
    eq(false, $php->reset());              // nothing persistent to reset
});

summary();

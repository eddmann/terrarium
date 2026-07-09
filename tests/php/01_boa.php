<?php
// A real JavaScript engine (Boa, pure Rust) compiled to wasm runs as the guest.
// PHP exposes a typed SDK by name; untrusted JS runs sandboxed and calls it.
// Because the engine runs *inside* the wasm sandbox, an engine memory-corruption
// bug cannot reach the host.

declare(strict_types=1);

require __DIR__ . '/_harness.php';
use Terrarium\Terrarium;
use Terrarium\Exception as TerrariumException;
use Terrarium\TrapException;
use Terrarium\TimeoutException;
use Terrarium\MemoryException;
use Terrarium\GuestException;

$wasm = require_guest(__DIR__ . '/../wasm/boa_guest.wasm');

echo "real Boa (JavaScript), evaluated inside wasm\n";
check('arithmetic', function () use ($wasm) {
    $js = new Terrarium($wasm);
    eq(7, $js->eval('1 + 2 * 3'));
});
check('array methods (closures run inside the engine)', function () use ($wasm) {
    $js = new Terrarium($wasm);
    eq([1, 4, 9], $js->eval('[1, 2, 3].map(n => n * n)'));
});
check('string + template literals', function () use ($wasm) {
    $js = new Terrarium($wasm);
    eq('HELLO', $js->eval('"hello".toUpperCase()'));
});
check('object result becomes a PHP assoc array', function () use ($wasm) {
    $js = new Terrarium($wasm);
    eq(['a' => 1, 'b' => true], $js->eval('({ a: 1, b: true })'));
});
check('JSON works (engine builtins)', function () use ($wasm) {
    $js = new Terrarium($wasm);
    eq([1, 2, 3], $js->eval('JSON.parse("[1,2,3]")'));
});

echo "\nguest JS reaching the PHP SDK (by name)\n";
check('fetchUser(42) re-enters PHP and returns data', function () use ($wasm) {
    $js = new Terrarium($wasm);
    $js->register('fetchUser', fn (int $id) => ['name' => 'Ada', 'roles' => ['admin', 'dev']]);
    eq('Ada has 2 roles', $js->eval(<<<'JS'
        const u = fetchUser(42);
        `${u.name} has ${u.roles.length} roles`
    JS));
});
check('values marshal both ways through the SDK', function () use ($wasm) {
    $js = new Terrarium($wasm);
    $js->register('sum', fn (array $xs) => array_sum($xs));
    eq(15, $js->eval('sum([1, 2, 3, 4, 5])'));
});
check('capability handle used from inside JS', function () use ($wasm) {
    $js = new Terrarium($wasm);
    $counter = new ArrayObject(['n' => 0]);
    $h = $js->grant($counter);
    $js->register('bump', function (int $handle) use ($js) {
        $o = $js->resolve($handle);
        return ++$o['n'];
    });
    eq([1, 2, 3], $js->eval("[bump($h), bump($h), bump($h)]"));
    eq(3, $counter['n']);
});

echo "\ncheck(): compile-only, nothing runs\n";
check('valid source -> no diagnostics', function () use ($wasm) {
    $js = new Terrarium($wasm);
    eq([], $js->check('const x = [1, 2].map(n => n * n);'));
});
check('a syntax error is reported without executing', function () use ($wasm) {
    $js = new Terrarium($wasm);
    $diags = $js->check('const x = (1 +;');
    eq(1, count($diags));
    contains($diags[0]['message'], '');
    eq('SyntaxError', $diags[0]['type']);
});

echo "\nerrors are contained\n";
check('a JS error surfaces as a GuestException', function () use ($wasm) {
    $js = new Terrarium($wasm);
    throws(GuestException::class, fn () => $js->eval('null.field'));
});
check('a single catch (TerrariumException) covers guest errors too', function () use ($wasm) {
    $js = new Terrarium($wasm);
    throws(TerrariumException::class, fn () => $js->eval('null.field'));
});

summary();

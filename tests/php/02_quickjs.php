<?php
// Real QuickJS-ng, compiled to wasm (via the WASI SDK), running as a guest.
// The engine runs *inside* the wasm sandbox, so an engine memory-corruption bug
// cannot reach the host — with no container or microVM. Same host ABI and the
// same `Terrarium` facade as every other guest: only the loaded `.wasm` differs.

declare(strict_types=1);

require __DIR__ . '/_harness.php';
use Terrarium\Terrarium;
use Terrarium\Exception as TerrariumException;
use Terrarium\TrapException;
use Terrarium\TimeoutException;
use Terrarium\MemoryException;
use Terrarium\GuestException;

$wasm = require_guest(__DIR__ . '/../wasm/quickjs_guest.wasm');

// One shared engine for the stateless checks (the module compiles once).
$js = new Terrarium($wasm, timeoutMs: 2000);
$js->register('fetchUser', fn (int $id) => ['name' => 'Ada', 'roles' => ['admin', 'dev']]);
$js->register('sum', fn (array $xs) => array_sum($xs));

echo "real QuickJS-ng (JavaScript), evaluated inside wasm\n";
check('arithmetic', fn () => eq(7, $js->eval('1 + 2 * 3')));
check('array closures', fn () => eq([1, 4, 9], $js->eval('[1, 2, 3].map(n => n * n)')));
check('template literals + methods', fn () => eq('ADA', $js->eval('`ada`.toUpperCase()')));
check('object -> PHP assoc array', fn () => eq(['a' => 1, 'b' => true], $js->eval('({ a: 1, b: true })')));
check('JSON builtin', fn () => eq(['x' => [1, 2]], $js->eval('JSON.parse(\'{"x":[1,2]}\')')));
check('modern syntax (let/const/arrow/spread)', fn () => eq(6, $js->eval('const xs=[1,2,3]; xs.reduce((a,b)=>a+b,0)')));

echo "\nguest QuickJS reaching the PHP SDK (by name)\n";
check('fetchUser(42) re-enters PHP', fn () => eq(
    'Ada has 2 roles',
    $js->eval('const u = fetchUser(42); `${u.name} has ${u.roles.length} roles`')
));
check('values marshal both ways', fn () => eq(15, $js->eval('sum([1,2,3,4,5])')));
check('capability handle from inside JS', function () use ($wasm) {
    $j = new Terrarium($wasm);
    $counter = new ArrayObject(['n' => 0]);
    $h = $j->grant($counter);
    $j->register('bump', function (int $hd) use ($j) {
        $o = $j->resolve($hd);
        return ++$o['n'];
    });
    eq([1, 2], $j->eval("[bump($h), bump($h)]"));
    eq(2, $counter['n']);
});

echo "\ncheck(): compile-only, nothing runs\n";
check('valid source -> no diagnostics; capabilities do NOT fire', function () use ($wasm) {
    $j = new Terrarium($wasm);
    $fired = false;
    $j->register('spy', function () use (&$fired) { $fired = true; });
    eq([], $j->check('spy();'));   // syntactically fine — and never executed
    eq(false, $fired);
});
check('a syntax error carries type and line', function () use ($wasm) {
    $j = new Terrarium($wasm);
    $diags = $j->check("const a = 1;\nconst b = (2 +;\n");
    eq(1, count($diags));
    eq('SyntaxError', $diags[0]['type']);
    eq(2, $diags[0]['line']);
});

echo "\nerrors & limits\n";
check('JS error -> GuestException', fn () => throws(GuestException::class, fn () => $js->eval('null.field')));
check('infinite loop contained by the time budget', function () use ($wasm) {
    $j = new Terrarium($wasm, timeoutMs: 200);
    throws(TimeoutException::class, fn () => $j->eval('while (true) {}'));
});
check('engine recovers after a timeout', function () use ($wasm) {
    $j = new Terrarium($wasm, timeoutMs: 200);
    try { $j->eval('while (true) {}'); } catch (TimeoutException $e) {}
    eq(4, $j->eval('2 + 2'));
});

summary();

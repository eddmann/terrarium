<?php
// Real Python (RustPython, pure Rust) compiled to wasm, running as a guest.
// Proves the host extension is language-agnostic: the *same* `Terrarium` facade,
// host_call bridge, and limits that run Boa and QuickJS also run Python —
// eval(source) returns a value, and guest Python reaches the SDK as <name>().

declare(strict_types=1);

require __DIR__ . '/_harness.php';
use Terrarium\Terrarium;
use Terrarium\Exception as TerrariumException;
use Terrarium\TrapException;
use Terrarium\TimeoutException;
use Terrarium\MemoryException;
use Terrarium\GuestException;

$wasm = require_guest(__DIR__ . '/../wasm/rustpython_guest.wasm');

// One shared interpreter for the stateless checks (module compiles once).
$py = new Terrarium($wasm, timeoutMs: 5000);
$py->register('fetchUser', fn (int $id) => ['name' => 'Ada', 'roles' => ['admin', 'dev']]);
$py->register('sum', fn (array $xs) => array_sum($xs));

echo "real RustPython (Python), evaluated inside wasm\n";
check('arithmetic', fn () => eq(7, $py->eval('1 + 2 * 3')));
check('list comprehension', fn () => eq([0, 1, 4, 9], $py->eval('[x * x for x in range(4)]')));
check('str methods', fn () => eq('ADA', $py->eval('"ada".upper()')));
check('dict -> PHP assoc array', fn () => eq(['a' => 1, 'b' => true], $py->eval('{"a": 1, "b": True}')));
check('builtins (sorted)', fn () => eq([1, 2, 3], $py->eval('sorted([3, 1, 2])')));
check('None -> null', fn () => eq(null, $py->eval('None')));

echo "\nguest Python reaching the PHP SDK (by name)\n";
check('fetchUser(42) re-enters PHP', fn () => eq(
    'Ada has 2 roles',
    $py->eval('"%s has %d roles" % (fetchUser(42)["name"], len(fetchUser(42)["roles"]))')
));
check('values marshal both ways', fn () => eq(15, $py->eval('sum([1, 2, 3, 4, 5])')));
check('capability handle from inside Python', function () use ($wasm) {
    $p = new Terrarium($wasm);
    $counter = new ArrayObject(['n' => 0]);
    $h = $p->grant($counter);
    $p->register('bump', function (int $hd) use ($p) {
        $o = $p->resolve($hd);
        return ++$o['n'];
    });
    eq([1, 2], $p->eval("[bump($h), bump($h)]"));
    eq(2, $counter['n']);
});

echo "\ncheck(): compile-only, nothing runs\n";
check('valid source -> no diagnostics', function () use ($wasm) {
    $p = new Terrarium($wasm);
    eq([], $p->check("def f(x):\n    return x * 2\n"));
});
check('a syntax error is reported without executing', function () use ($wasm) {
    $p = new Terrarium($wasm);
    $diags = $p->check("def f(:\n    pass\n");
    eq(1, count($diags));
    eq('SyntaxError', $diags[0]['type']);
});

echo "\nerrors & limits\n";
check('Python error -> GuestException', fn () => throws(GuestException::class, fn () => $py->eval('1 / 0')));
check('infinite loop contained by the time budget', function () use ($wasm) {
    $p = new Terrarium($wasm, timeoutMs: 300);
    throws(TimeoutException::class, fn () => $p->eval("while True:\n    pass"));
});
check('interpreter recovers after a timeout', function () use ($wasm) {
    $p = new Terrarium($wasm, timeoutMs: 300);
    try { $p->eval("while True:\n    pass"); } catch (TimeoutException $e) {}
    eq(4, $p->eval('2 + 2'));
});

summary();

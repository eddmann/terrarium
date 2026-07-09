<?php
// The same PHP host SDK — the same capabilities and the same live object —
// exposed to two different guest languages (JS via Boa, Python via RustPython),
// both running sandboxed in wasm. The only difference is which `.wasm` is loaded.
//
//   php -d extension=target/release/libterrarium.so examples/js_and_python.php

declare(strict_types=1);

require __DIR__ . '/../lib/Terrarium.php';
use Terrarium\Terrarium;
use Terrarium\Exception as TerrariumException;
use Terrarium\TrapException;
use Terrarium\TimeoutException;
use Terrarium\MemoryException;
use Terrarium\GuestException;

$jsWasm = __DIR__ . '/../tests/wasm/boa_guest.wasm';
$pyWasm = __DIR__ . '/../tests/wasm/rustpython_guest.wasm';

// --- The PHP host's SDK: capabilities + a live object, defined once. ---------
function wire(Terrarium $guest): void
{
    // A plain capability: typed PHP data the guest can pull in.
    $guest->register('fetchUser', fn (int $id) => [
        'name'  => 'Ada',
        'roles' => ['admin', 'dev'],
    ]);

    // A capability that does real PHP work.
    $guest->register('sum', fn (array $xs) => array_sum($xs));

    // A live, stateful PHP object exposed only as an opaque handle.
    $counter = new ArrayObject(['n' => 0]);
    $h = $guest->grant($counter);
    $guest->register('bump', function (int $handle) use ($guest) {
        $o = $guest->resolve($handle);
        return ++$o['n'];
    });
    // Stash the handle so the scripts below can pass it back.
    $GLOBALS['handle'] = $h;
}

// ================================ Boa (JavaScript) ===========================
echo "=== Boa guest (JavaScript, wasm) ===\n";
$js = new Terrarium($jsWasm, timeoutMs: 2000);
wire($js);
$h = $GLOBALS['handle'];

echo $js->eval(<<<'JS'
    const u = fetchUser(42);                     // re-enters PHP, returns a typed-ish object
    `${u.name} has ${u.roles.length} roles`
JS), "\n";

print_r($js->eval('[1, 2, 3, 4].map(n => n * n)'));          // closures run in the engine
echo "sum via PHP: ", $js->eval('sum([10, 20, 30])'), "\n";
echo "bump x3: ", json_encode($js->eval("[bump($h), bump($h), bump($h)]")), "\n";

// ============================= RustPython (Python) ===========================
echo "\n=== RustPython guest (Python, wasm) ===\n";
$py = new Terrarium($pyWasm, timeoutMs: 5000);
wire($py);
$h = $GLOBALS['handle'];

echo $py->eval('"%s has %d roles" % (fetchUser(42)["name"], len(fetchUser(42)["roles"]))'), "\n";
print_r($py->eval('[n * n for n in range(1, 5)]'));         // list comprehension
echo "sum via PHP: ", $py->eval('sum([10, 20, 30])'), "\n";
echo "bump x3: ", json_encode($py->eval("[bump($h), bump($h), bump($h)]")), "\n";

// =============================== Errors & limits =============================
echo "\n=== contained failures ===\n";
try { $js->eval('null.field'); } catch (TerrariumException $e) { echo "JS error caught: ", $e->getMessage(), "\n"; }
try { $py->eval('1 / 0'); }     catch (TerrariumException $e) { echo "PY error caught: ", $e->getMessage(), "\n"; }

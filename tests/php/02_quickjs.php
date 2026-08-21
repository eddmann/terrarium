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

// There is no event loop in the sandbox: nothing drains the job queue, so a
// suspended program never resumes. That must FAIL LOUDLY rather than half-run —
// an async IIFE that silently returns a pending promise is the worst outcome
// for generated code. On by default; no option to set.
echo "\nasynchronous code cannot complete (the job queue is never drained)\n";
check('a pending promise -> AsyncIncomplete', function () use ($wasm) {
    $j = new Terrarium($wasm);
    try {
        $j->eval('new Promise(() => {})');
        throw new RuntimeException('expected an AsyncIncomplete rejection');
    } catch (GuestException $e) {
        contains($e->getMessage(), 'AsyncIncomplete');
        contains($e->getMessage(), 'job queue is never drained');
    }
});
check('an ALREADY-RESOLVED promise is also AsyncIncomplete (continuations never ran)', function () use ($wasm) {
    $j = new Terrarium($wasm);
    // `.then` callbacks are queued, not called: "resolved" is not "finished".
    throws(GuestException::class, fn () => $j->eval('Promise.resolve(1)'));
    try {
        $j->eval('Promise.resolve(1)');
    } catch (GuestException $e) {
        contains($e->getMessage(), 'AsyncIncomplete');
    }
});
check('an async IIFE fails instead of silently half-running', function () use ($wasm) {
    $j = new Terrarium($wasm);
    $reached = false;
    $j->register('mark', function () use (&$reached) { $reached = true; });
    try {
        $j->eval('(async () => { console.log("before"); await 1; mark(); })()');
        throw new RuntimeException('expected an AsyncIncomplete rejection');
    } catch (GuestException $e) {
        contains($e->getMessage(), 'AsyncIncomplete');
    }
    eq(false, $reached);                 // the continuation never ran — that IS the bug
    eq('before', $j->output());          // output before the suspension survives
});
check('a plain value left with queued jobs -> AsyncIncomplete', function () use ($wasm) {
    $j = new Terrarium($wasm);
    throws(GuestException::class, fn () => $j->eval('Promise.resolve().then(() => 1); 42'));
});
// The reviewer's reproduction, and the reason the two checks above were not
// enough on their own. A reaction registered on a PENDING promise is stored on
// the promise and only becomes a job when it settles — which never happens
// here — so the result is a plain 42 and JS_IsJobPending is false. Both guards
// passed, eval returned 42, and the callback was abandoned in silence: the
// exact failure the guards exist to eliminate, in the one shape they missed.
echo "\na reaction on a promise that never settles (no job is ever queued)\n";
check('the pending-reaction reproduction is rejected, not silently abandoned', function () use ($wasm) {
    $j = new Terrarium($wasm);
    $reached = false;
    $j->register('mark', function () use (&$reached) { $reached = true; });
    try {
        $j->eval("const p = new Promise(() => {});\np.then(() => mark());\n42");
        throw new RuntimeException('expected an AsyncIncomplete rejection (this used to return 42)');
    } catch (GuestException $e) {
        contains($e->getMessage(), 'AsyncIncomplete');
        contains($e->getMessage(), 'registered a promise reaction');
    }
    eq(false, $reached);                 // it never ran — that IS the bug being reported
});
check('.catch and .finally on a pending promise are caught too', function () use ($wasm) {
    // Both are specified in terms of `then`, and the engine implements them
    // that way, so one instrument covers all three.
    $j = new Terrarium($wasm);
    throws(GuestException::class, fn () => $j->eval('new Promise(() => {}).catch(() => {}); 1'));
    throws(GuestException::class, fn () => $j->eval('new Promise(() => {}).finally(() => {}); 1'));
});
check('Promise.all/race/any over a pending promise is caught too', function () use ($wasm) {
    $j = new Terrarium($wasm);
    foreach (['all', 'race', 'any', 'allSettled'] as $combinator) {
        throws(GuestException::class, fn () => $j->eval("Promise.$combinator([new Promise(() => {})]); 1"));
    }
});
check('synchronous code is untouched', function () use ($wasm) {
    $j = new Terrarium($wasm);
    eq(42, $j->eval('const p = { then: 1 }; 42'));       // not a real promise
    eq([1, 2], $j->eval('[1, 2]'));
    // An object with a `then` METHOD of its own is not a promise and is not
    // instrumented: calling it runs the callback immediately, as it always did.
    eq(7, $j->eval('const o = { then: (f) => f(7) }; let v = 0; o.then((x) => { v = x; }); v'));
});
check('the counter cannot be talked out of a detection', function () use ($wasm) {
    // The accessor is non-writable and non-configurable, so a program cannot
    // replace it with one that lies. (Restoring the original `then` still
    // works — the wrapper is deliberately writable so nothing legitimate
    // breaks — but that can only ever LOSE a detection, never invent one, and
    // there is no legitimate program it helps.)
    $j = new Terrarium($wasm);
    throws(GuestException::class, fn () => $j->eval(
        'try { globalThis.__terrariumReactions = () => 0 } catch (e) {}
         new Promise(() => {}).then(() => {});
         1'
    ));
});
check('syncOnly is accepted here but not implemented (no compiler to enforce it)', function () use ($wasm) {
    // The compile option is a general host->guest channel; only the TypeScript
    // guest has an AST to enforce it against. This guest catches the same
    // hazard at run time instead, which is on regardless.
    $j = new Terrarium($wasm, syncOnly: true);
    eq([], $j->check('async function f() { await 1; }'));   // still just a parse check
    eq(2, $j->eval('1 + 1'));
    throws(GuestException::class, fn () => $j->eval('(async () => { await 1; })()'));
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

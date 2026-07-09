<?php
// TypeScript — checked, stripped, and run inside the sandbox. QuickJS-ng with
// the real TypeScript compiler embedded as QuickJS bytecode: each eval is
// type-checked against the .d.ts generated from the registered SDK (the type
// environment IS the capability environment), erased whitespace-preserving
// (runtime error lines match the TS source exactly), then run.
//
// Skips cleanly until typescript_guest.wasm is built (see `make typescript-guest`).

declare(strict_types=1);

require __DIR__ . '/_harness.php';
use Terrarium\Terrarium;
use Terrarium\Exception as TerrariumException;
use Terrarium\TrapException;
use Terrarium\TimeoutException;
use Terrarium\MemoryException;
use Terrarium\GuestException;

$wasm = require_guest(__DIR__ . '/../wasm/typescript_guest.wasm');

echo "TypeScript, checked and evaluated inside wasm\n";
check('typed code checks and runs', function () use ($wasm) {
    $ts = new Terrarium($wasm);
    eq(7, $ts->eval('const x: number = 1 + 2 * 3; x'));
});
check('interfaces and generics are fine', function () use ($wasm) {
    $ts = new Terrarium($wasm);
    eq([2, 4, 6], $ts->eval(<<<'TS'
        interface Point { x: number }
        function double<T extends Point>(ps: T[]): number[] { return ps.map(p => p.x * 2); }
        double([{ x: 1 }, { x: 2 }, { x: 3 }])
        TS));
});
check('plain JavaScript is valid TypeScript', function () use ($wasm) {
    $ts = new Terrarium($wasm);
    eq('HELLO', $ts->eval('"hello".toUpperCase()'));
});

echo "\nthe check runs against the registered SDK\n";
check('a wrong argument type is rejected BEFORE execution', function () use ($wasm) {
    $ts = new Terrarium($wasm);
    $called = false;
    $ts->register('user.fetch', function (int $id) use (&$called) {
        $called = true;
        return ['name' => 'Ada'];
    });
    try {
        $ts->eval('user.fetch("42")');
        throw new RuntimeException('expected a type-check rejection');
    } catch (GuestException $e) {
        contains($e->getMessage(), 'TS2345');
        contains($e->getMessage(), "type 'string' is not assignable");
        contains($e->getMessage(), '(line 1)');
    }
    eq(false, $called);   // the capability never ran
});
check('a correct call checks and executes', function () use ($wasm) {
    $ts = new Terrarium($wasm);
    $ts->register('user.fetch',
        /** @return array{name: string, roles: string[]} */
        fn (int $id): array => ['name' => 'Ada', 'roles' => ['admin', 'dev']]);
    eq('Ada has 2 roles', $ts->eval(<<<'TS'
        const u = user.fetch(42);
        `${u.name} has ${u.roles.length} roles`
        TS));
});
check('the checker knows the inferred return shape', function () use ($wasm) {
    $ts = new Terrarium($wasm);
    $ts->register('user.fetch',
        /** @return array{name: string} */
        fn (int $id): array => ['name' => 'Ada']);
    // `.missing` is not in the declared shape -> TS2339 before execution.
    throws(GuestException::class, fn () => $ts->eval('user.fetch(1).missing'));
});
check('an unknown global is rejected (the SDK is the whole world)', function () use ($wasm) {
    $ts = new Terrarium($wasm);
    throws(GuestException::class, fn () => $ts->eval('fetch("https://example.com")'));
});

echo "\n@ts-nocheck and error line numbers\n";
check('@ts-nocheck skips the check (still stripped and run)', function () use ($wasm) {
    $ts = new Terrarium($wasm);
    // Deliberately mistyped annotation: would fail the checker.
    eq(3, $ts->eval("// @ts-nocheck\nconst n: string = 1 + 2; n"));
});
check('runtime error lines match the TS source (types erased in place)', function () use ($wasm) {
    $ts = new Terrarium($wasm);
    try {
        $ts->eval(<<<'TS'
            interface Cfg { deep: { value: number } }
            const cfg: Cfg | null = null as Cfg | null;
            cfg!.deep.value
            TS);
        throw new RuntimeException('expected a runtime error');
    } catch (GuestException $e) {
        contains($e->getMessage(), '(line 3)');   // the TS line, not a transformed one
    }
});
check('non-erasable syntax (enum) is a clear error', function () use ($wasm) {
    $ts = new Terrarium($wasm);
    try {
        $ts->eval("enum Color { Red, Green }\nColor.Red");
        throw new RuntimeException('expected an unsupported-syntax error');
    } catch (GuestException $e) {
        contains($e->getMessage(), 'not erasable');
    }
});

echo "\ncheck(): every diagnostic as data, nothing runs\n";
check('well-typed source -> no diagnostics', function () use ($wasm) {
    $ts = new Terrarium($wasm);
    $ts->register('math.add', fn (int $a, int $b): int => $a + $b);
    eq([], $ts->check('const n: number = math.add(1, 2);'));
});
check('ALL errors are returned, with types and lines', function () use ($wasm) {
    $ts = new Terrarium($wasm);
    $ts->register('user.fetch', /** @return array{name: string} */ fn (int $id): array => []);
    $diags = $ts->check("const u = user.fetch(\"42\");\nconst x: number = u.name;\n");
    eq(2, count($diags));
    eq('TS2345', $diags[0]['type']);
    eq(1, $diags[0]['line']);
    eq('TS2322', $diags[1]['type']);
    eq(2, $diags[1]['line']);
});
check('check() ignores @ts-nocheck (an explicit check asks for diagnostics)', function () use ($wasm) {
    $ts = new Terrarium($wasm);
    $diags = $ts->check("// @ts-nocheck\nconst n: string = 1;\n");
    eq(1, count($diags));
    eq('TS2322', $diags[0]['type']);
});
check('nothing executes and output() is untouched', function () use ($wasm) {
    $ts = new Terrarium($wasm);
    $fired = false;
    $ts->register('spy', function () use (&$fired) { $fired = true; });
    $ts->eval('console.log("from eval")');
    eq([], $ts->check('spy(); console.log("from check");'));
    eq(false, $fired);
    eq('from eval', $ts->output());   // check didn't clear or add output
});

echo "\nchannels\n";
check('console.log is captured as output()', function () use ($wasm) {
    $ts = new Terrarium($wasm);
    $ts->eval('const n: number = 42; console.log("hello", n);');
    eq('hello 42', $ts->output());
});
check('values marshal both ways through the SDK', function () use ($wasm) {
    $ts = new Terrarium($wasm);
    $ts->register('sum', /** @param int[] $xs */ fn (array $xs): int => array_sum($xs));
    eq(15, $ts->eval('sum([1, 2, 3, 4, 5])'));
});

summary();

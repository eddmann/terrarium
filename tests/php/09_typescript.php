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

// Nothing drains the job queue, so a suspended program never resumes. Two
// independent defences: `syncOnly` refuses the syntax at compile time, and the
// runtime AsyncIncomplete check (always on) catches whatever still gets there.
echo "\nasync without sync-only: it compiles, then fails loudly at run time\n";
check('async source still compiles (no option set)', function () use ($wasm) {
    $ts = new Terrarium($wasm);
    eq([], $ts->check('const f = async (): Promise<number> => 1;'));
});
check('...but the eval is AsyncIncomplete, not a silent half-run', function () use ($wasm) {
    $ts = new Terrarium($wasm);
    $reached = false;
    $ts->register('mark', function () use (&$reached) { $reached = true; });
    try {
        $ts->eval('(async () => { console.log("before"); await 1; mark(); })()');
        throw new RuntimeException('expected an AsyncIncomplete rejection');
    } catch (GuestException $e) {
        contains($e->getMessage(), 'AsyncIncomplete');
        contains($e->getMessage(), 'job queue is never drained');
    }
    eq(false, $reached);              // the continuation never ran
    eq('before', $ts->output());      // what it printed first survives
});
check('a returned promise is AsyncIncomplete even when resolved', function () use ($wasm) {
    $ts = new Terrarium($wasm);
    throws(GuestException::class, fn () => $ts->eval('Promise.resolve(1)'));
});

echo "\nsyncOnly: async and generator syntax is rejected at compile time\n";
$rejects = function (string $label, string $source, int $line) use ($wasm) {
    check($label, function () use ($wasm, $source, $line) {
        $ts = new Terrarium($wasm, syncOnly: true);
        try {
            $ts->eval($source);
            throw new RuntimeException('expected a TSSyncOnly rejection');
        } catch (GuestException $e) {
            contains($e->getMessage(), 'TSSyncOnly');
            contains($e->getMessage(), "(line $line)");
        }
    });
};
$rejects('async function declaration', "const a = 1;\nasync function f() { return a; }\nf();", 2);
$rejects('async function expression', "const f = async function () { return 1; };\nf();", 1);
$rejects('async arrow', "const f = async () => 1;\nf();", 1);
$rejects('async class method', "class C {\n  async m() { return 1; }\n}\nnew C();", 2);
$rejects('async object-literal method', "const o = {\n  async m() { return 1; },\n};\no;", 2);
$rejects('top-level await', "const p = Promise.resolve(1);\nconst v = await p;\nv;", 2);
$rejects('for await', "async function f(xs: number[]) {\n  for await (const x of xs) { console.log(x); }\n}\nf([]);", 1);
$rejects('generator declaration', "function* g() { return 1; }\ng();", 1);
$rejects('generator expression', "const g = function* () { return 1; };\ng();", 1);
$rejects('generator method', "class C {\n  *m() { return 1; }\n}\nnew C();", 2);
$rejects('yield', "function* g() {\n  yield 1;\n}\ng();", 1);
check('await inside an async function is reported in its own right', function () use ($wasm) {
    // eval gates on the first (the `async` modifier); check() shows both.
    $ts = new Terrarium($wasm, syncOnly: true);
    $diags = $ts->check("async function f() {\n  return await Promise.resolve(1);\n}\n");
    eq(2, count($diags));
    eq(2, $diags[1]['line']);
    contains($diags[1]['message'], '`await` is not supported');
});

echo "\nsyncOnly is a compiler check, not a regex over the text\n";
check("the prose 'we await your reply' in a string is NOT rejected", function () use ($wasm) {
    // The false positive this exists to kill: a source-text `\b(async|await)\b`
    // ban rejects ordinary English. The AST does not.
    $ts = new Terrarium($wasm, syncOnly: true);
    eq('we await your reply', $ts->eval('const reply: string = "we await your reply";
reply'));
});
check('identifiers and properties merely NAMED async/await are fine', function () use ($wasm) {
    $ts = new Terrarium($wasm, syncOnly: true);
    eq(3, $ts->eval("// an async pipeline is discussed, never used\nconst awaited: number = 1;\nconst o = { async: 2 };\nawaited + o.async"));
});
check('ordinary synchronous code is unaffected', function () use ($wasm) {
    $ts = new Terrarium($wasm, syncOnly: true);
    $ts->register('user.fetch', /** @return array{name: string} */ fn (int $id): array => ['name' => 'Ada']);
    eq('Ada', $ts->eval('user.fetch(1).name'));
    eq([], $ts->check('const n: number = user.fetch(1).name.length;'));
});
check('@ts-nocheck does NOT skip sync-only (a host constraint, not a preference)', function () use ($wasm) {
    $ts = new Terrarium($wasm, syncOnly: true);
    // The pragma disables the type check; the sync-only walk still runs.
    try {
        $ts->eval("// @ts-nocheck\nconst n: string = 1;\nasync function f() { return n; }\nf();");
        throw new RuntimeException('expected a TSSyncOnly rejection');
    } catch (GuestException $e) {
        contains($e->getMessage(), 'TSSyncOnly');
        contains($e->getMessage(), '(line 3)');
    }
});
check('the message teaches the synchronous shape', function () use ($wasm) {
    $ts = new Terrarium($wasm, syncOnly: true);
    try {
        $ts->eval("const p = Promise.resolve(1);\nawait p;");
    } catch (GuestException $e) {
        contains($e->getMessage(), 'this environment is synchronous');
        contains($e->getMessage(), 'return values directly');
    }
});
check('check() lists EVERY occurrence, with lines', function () use ($wasm) {
    $ts = new Terrarium($wasm, syncOnly: true);
    $diags = $ts->check("async function a() { return 1; }\nasync function b() { return 2; }\nfunction* c() { return 3; }\n");
    eq(3, count($diags));
    foreach ($diags as $i => $d) {
        eq('TSSyncOnly', $d['type']);
        eq($i + 1, $d['line']);
    }
});
check('eval reports the first and counts the rest', function () use ($wasm) {
    $ts = new Terrarium($wasm, syncOnly: true);
    try {
        $ts->eval("async function a() { return 1; }\nasync function b() { return 2; }\na(); b();");
    } catch (GuestException $e) {
        contains($e->getMessage(), '(line 1)');
        contains($e->getMessage(), '[+1 more error]');
    }
});
check('sync-only diagnostics come before the type diagnostics', function () use ($wasm) {
    $ts = new Terrarium($wasm, syncOnly: true);
    $diags = $ts->check("async function a() { return 1; }\nconst n: string = 1;\n");
    eq('TSSyncOnly', $diags[0]['type']);
    eq('TS2322', $diags[count($diags) - 1]['type']);
});
check('the option is per-instance: the default guest still accepts async', function () use ($wasm) {
    $plain = new Terrarium($wasm);
    eq([], $plain->check('async function f() { return 1; }'));
    $strict = new Terrarium($wasm, syncOnly: true);
    eq(1, count($strict->check('async function f() { return 1; }')));
});
check('the raw engine takes it as an open option map (setCompileOptions)', function () use ($wasm) {
    // The facade's `syncOnly:` is sugar over this; the option map itself is
    // general — a guest reads the keys it knows and ignores the rest.
    $rt = new \Terrarium\Runtime(file_get_contents($wasm));
    eq([], $rt->check('async function f() { return 1; }'));
    $rt->setCompileOptions(['sync_only' => true, 'not_an_option_here' => 'ignored']);
    eq(1, count($rt->check('async function f() { return 1; }')));
    $rt->setCompileOptions([]);          // cleared again
    eq([], $rt->check('async function f() { return 1; }'));
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

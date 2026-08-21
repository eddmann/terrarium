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
$rejects('top-level await', "const n = 1;\nconst v = await n;\nv;", 2);
$rejects('for await', "async function f(xs: number[]) {\n  for await (const x of xs) { console.log(x); }\n}\nf([]);", 1);
$rejects('generator declaration', "function* g() { return 1; }\ng();", 1);
$rejects('generator expression', "const g = function* () { return 1; };\ng();", 1);
$rejects('generator method', "class C {\n  *m() { return 1; }\n}\nnew C();", 2);
$rejects('yield', "function* g() {\n  yield 1;\n}\ng();", 1);
check('await inside an async function is reported in its own right', function () use ($wasm) {
    // eval gates on the first (the `async` modifier); check() shows them all,
    // in source order: the `async` on line 1, then the `await` and the
    // `Promise.resolve` it suspends on, both on line 2.
    $ts = new Terrarium($wasm, syncOnly: true);
    $diags = $ts->check("async function f() {\n  return await Promise.resolve(1);\n}\n");
    eq(3, count($diags));
    eq([1, 2, 2], array_column($diags, 'line'));
    contains($diags[1]['message'], '`await` is not supported');
    contains($diags[2]['message'], '`Promise` cannot be used here');
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
        $ts->eval("const n = 1;\nawait n;");
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
        $ts->eval("async function a() { return 1; }\nasync function b() { return 2; }\n[a, b];");
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

// A promise is not settled by an `await` alone: it needs a job queue, and there
// isn't one. So under `syncOnly` the ban covers the whole family — constructing
// a promise, naming the global, consuming one with `.then`, or calling anything
// that returns one — not just the `async`/`await` keywords. The gap this closes
// is a program whose result is a plain value and whose queue is empty, which
// every run-time guard passes.
echo "\nsyncOnly: promises are rejected at compile time, keywords or not\n";
check('THE REPRODUCTION: an unresolved promise with a reaction fails at check()', function () use ($wasm) {
    // No `async`, no `await`, result 42, nothing queued. check() used to return
    // [] and eval() used to return 42 with the reaction silently abandoned.
    $ts = new Terrarium($wasm, syncOnly: true);
    $diags = $ts->check("const p = new Promise(() => {});\np.then(() => 1);\n42\n");
    eq(2, count($diags));
    eq(['TSSyncOnly', 'TSSyncOnly'], array_column($diags, 'type'));
    eq([1, 2], array_column($diags, 'line'));
    contains($diags[0]['message'], 'promises cannot settle in a synchronous guest');
    contains($diags[1]['message'], '`.then` / `.catch` / `.finally` cannot run here');
});
check('...and eval() refuses it rather than returning 42', function () use ($wasm) {
    $ts = new Terrarium($wasm, syncOnly: true);
    $reached = false;
    $ts->register('mark', function () use (&$reached) { $reached = true; });
    try {
        $ts->eval("const p = new Promise(() => {});\np.then(() => mark());\n42");
        throw new RuntimeException('expected a TSSyncOnly rejection (this used to return 42)');
    } catch (GuestException $e) {
        contains($e->getMessage(), 'TSSyncOnly');
        contains($e->getMessage(), '(line 1)');
    }
    eq(false, $reached);
});
check('...and the @ts-nocheck path refuses it too, by shape', function () use ($wasm) {
    // No Program is built there, so the rule degrades to syntax matching:
    // `new Promise`, the identifier `Promise`, and a call through
    // then/catch/finally. Conservative on purpose — see below.
    $ts = new Terrarium($wasm, syncOnly: true);
    try {
        $ts->eval("// @ts-nocheck\nconst p = new Promise(() => {});\np.then(() => 1);\n42");
        throw new RuntimeException('expected a TSSyncOnly rejection');
    } catch (GuestException $e) {
        contains($e->getMessage(), 'TSSyncOnly');
        contains($e->getMessage(), '(line 2)');
    }
});
check('a capability that returns a promise is refused at its call site', function () use ($wasm) {
    // The type is the evidence: no keyword appears anywhere in the source.
    $ts = new Terrarium($wasm, syncOnly: true);
    $diags = $ts->check("declare const db: { query(q: string): Promise<string[]> };\nconst rows = db.query(\"select 1\");\nrows;\n");
    // Every occurrence, as always: the `Promise<…>` in the declaration, the
    // call that returns one, and the reference that carries it onwards.
    eq(['TSSyncOnly', 'TSSyncOnly', 'TSSyncOnly'], array_column($diags, 'type'));
    eq([1, 2, 3], array_column($diags, 'line'));
    contains($diags[1]['message'], 'promises cannot settle in a synchronous guest');
});
check('naming the global Promise at all is refused', function () use ($wasm) {
    $ts = new Terrarium($wasm, syncOnly: true);
    foreach (['Promise.resolve(1);', 'const f = Promise.all;\nf;', 'let p: Promise<number>;\np;'] as $source) {
        $diags = $ts->check($source);
        eq(true, count($diags) > 0);
        eq('TSSyncOnly', $diags[0]['type']);
    }
});
check('one diagnostic per promise expression, at its outermost point', function () use ($wasm) {
    // `p.then(f)` is itself promise-typed, and so is `p`: reporting each would
    // bury the author in three copies of one problem.
    $ts = new Terrarium($wasm, syncOnly: true);
    $diags = $ts->check("declare const p: Promise<number>;\np.then((v) => v + 1);\n");
    eq(2, count($diags));   // the `Promise<number>` in the declaration, and the `.then` call
    eq([1, 2], array_column($diags, 'line'));
});
check('a LOCAL class named Promise is not the global one', function () use ($wasm) {
    // Shadowing is the checker's job, and the rule resolves by DECLARATION
    // rather than by spelling — so a user type merely called Promise is fine.
    // (At the top level `class Promise` collides with the global declaration,
    // which is TypeScript's own complaint; inside a function it shadows it.)
    $ts = new Terrarium($wasm, syncOnly: true);
    $source =
        "function build(): number {\n" .
        "  class Promise {\n" .
        "    v: number;\n" .
        "    constructor(v: number) { this.v = v; }\n" .
        "  }\n" .
        "  return new Promise(1).v;\n" .
        "}\n" .
        "build()";
    eq([], $ts->check($source . ";\n"));
    eq(1, $ts->eval($source));
});
check("an object with a plain `then` METHOD is not a promise", function () use ($wasm) {
    // The false positive worth killing on the checked path: `then` alone does
    // not make a thenable — PromiseLike's `then` hands back another PromiseLike,
    // and this one returns void, so calling it just runs the callback.
    $ts = new Terrarium($wasm, syncOnly: true);
    eq([], $ts->check("const job = { then(cb: (n: number) => void): void { cb(1); } };\nlet v = 0;\njob.then((n) => { v = n; });\nv;\n"));
    eq(1, $ts->eval("const job = { then(cb: (n: number) => void): void { cb(1); } };\nlet v = 0;\njob.then((n) => { v = n; });\nv"));
});
check('the @ts-nocheck path IS conservative about that, and says so', function () use ($wasm) {
    // Documented trade: with no Program there is nothing to ask, so a call
    // through a member named `then` is refused on shape. Opting out of the type
    // information is what costs the precision; dropping the pragma restores it.
    $ts = new Terrarium($wasm, syncOnly: true);
    $job = "const job = { then(cb: (n: number) => void): void { cb(1); } };\njob.then((n) => { void n; });\n";
    // check() always builds a Program (it ignores the pragma by design), so it
    // stays precise even with the pragma present.
    eq([], $ts->check("// @ts-nocheck\n" . $job));
    try {
        $ts->eval("// @ts-nocheck\n" . $job . "1");
        throw new RuntimeException('expected the conservative syntax rule to fire');
    } catch (GuestException $e) {
        contains($e->getMessage(), 'TSSyncOnly');
        contains($e->getMessage(), '`.then` / `.catch` / `.finally`');
    }
});
check('without syncOnly, none of this fires', function () use ($wasm) {
    $plain = new Terrarium($wasm);
    eq([], $plain->check("const p = new Promise(() => {});\np.then(() => 1);\n42\n"));
});

// A checker that accepts code the engine cannot even parse is worse than no
// checker: it moves the failure from publish time to run time. Always on — this
// is a fact about the engine, not a host preference, so no option gates it and
// `@ts-nocheck` does not skip it.
echo "\nengine-unsupported syntax is refused whatever the checker thinks\n";
check('`accessor` class members are a check error, not a runtime SyntaxError', function () use ($wasm) {
    $ts = new Terrarium($wasm);
    $diags = $ts->check("class C {\n  accessor x = 1;\n}\nnew C();\n");
    eq(1, count($diags));
    eq('TSEngineUnsupported', $diags[0]['type']);
    eq(2, $diags[0]['line']);
    contains($diags[0]['message'], 'quickjs-ng v0.16.2');
    contains($diags[0]['message'], 'get`/`set');
});
check('eval refuses it too, instead of failing to parse inside the sandbox', function () use ($wasm) {
    $ts = new Terrarium($wasm);
    try {
        $ts->eval("class C {\n  accessor x = 1;\n}\nnew C().x");
        throw new RuntimeException('expected a TSEngineUnsupported rejection');
    } catch (GuestException $e) {
        contains($e->getMessage(), 'TSEngineUnsupported');
        contains($e->getMessage(), '(line 2)');
    }
});
check('@ts-nocheck does not skip it (an engine truth, not a preference)', function () use ($wasm) {
    $ts = new Terrarium($wasm);
    throws(GuestException::class, fn () => $ts->eval("// @ts-nocheck\nclass C { accessor x = 1 }\nnew C().x"));
});
check('a static or private accessor member is caught as well', function () use ($wasm) {
    $ts = new Terrarium($wasm);
    eq(1, count($ts->check('class C { static accessor x = 1 }')));
    eq(1, count($ts->check('class C { accessor #x = 1; get x() { return this.#x } }')));
});
check('an ordinary get/set pair is untouched', function () use ($wasm) {
    $ts = new Terrarium($wasm);
    eq([], $ts->check("class C {\n  #x = 1;\n  get x(): number { return this.#x; }\n  set x(v: number) { this.#x = v; }\n}\nnew C();\n"));
    eq(2, $ts->eval("class C {\n  #x = 1;\n  get x(): number { return this.#x; }\n  set x(v: number) { this.#x = v; }\n}\nconst c = new C();\nc.x = 2;\nc.x"));
});
check('a property merely NAMED accessor is fine', function () use ($wasm) {
    $ts = new Terrarium($wasm);
    eq(1, $ts->eval("const o = { accessor: 1 };\no.accessor"));
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

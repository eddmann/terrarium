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
    $source = "const u = user.fetch(\"42\");\nconst x: number = u.name;\n";
    $diags = $ts->check($source);
    eq(2, count($diags));
    eq('TS2345', $diags[0]['type']);
    eq(1, $diags[0]['line']);
    eq('TS2322', $diags[1]['type']);
    eq(2, $diags[1]['line']);
    eq($diags, $ts->check($source));
    eq($diags, $ts->analyze($source)['diagnostics']);
    try {
        $ts->eval($source);
        throw new RuntimeException('expected a type-check rejection');
    } catch (GuestException $e) {
        contains($e->getMessage(), 'TS2345');
        contains($e->getMessage(), '(line 1)');
        contains($e->getMessage(), '[+1 more error]');
    }
});
check('check() ignores @ts-nocheck (an explicit check asks for diagnostics)', function () use ($wasm) {
    $ts = new Terrarium($wasm);
    $source = "// @ts-nocheck\nconst n: string = 1; n;\n";
    $diags = $ts->check($source);
    eq(1, count($diags));
    eq('TS2322', $diags[0]['type']);
    eq(2, $diags[0]['line']);
    eq(1, $ts->eval($source));
    eq($diags, $ts->check($source));
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
check('a reaction on a promise that never settles is AsyncIncomplete too', function () use ($wasm) {
    // The shape that queues no job and returns a plain value: without the
    // prelude's reaction counter this returned 42 and dropped the callback.
    $ts = new Terrarium($wasm);
    $reached = false;
    $ts->register('mark', function () use (&$reached) { $reached = true; });
    try {
        $ts->eval("const p = new Promise(() => {});\np.then(() => mark());\n42");
        throw new RuntimeException('expected an AsyncIncomplete rejection');
    } catch (GuestException $e) {
        contains($e->getMessage(), 'AsyncIncomplete');
        contains($e->getMessage(), 'registered a promise reaction');
    }
    eq(false, $reached);
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
    throws(GuestException::class, fn () => $rt->eval('async function f() { return 1; }'));
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

// The checker's `lib` must not declare what the engine does not implement.
// `Intl` is the last hole: lib.es5.d.ts carries its own `declare namespace Intl`
// that the per-file exclusion cannot reach, so the build strips the namespace's
// value declarations instead (see build.sh step 4a).
echo "\nIntl is declared as types only, because the engine has none\n";
check('new Intl.NumberFormat() is a check error, not a runtime ReferenceError', function () use ($wasm) {
    $ts = new Terrarium($wasm);
    $diags = $ts->check('const s: string = new Intl.NumberFormat("en").format(1);');
    eq(1, count($diags));
    eq(1, $diags[0]['line']);
    contains($diags[0]['message'], 'Intl');
});
check('every Intl constructor is out of reach', function () use ($wasm) {
    $ts = new Terrarium($wasm);
    foreach (['Collator', 'NumberFormat', 'DateTimeFormat'] as $ctor) {
        eq(1, count($ts->check("const x = new Intl.$ctor();\nx;")));
    }
});
check('...but the locale-blind formatters that DO exist still type-check', function () use ($wasm) {
    // Deleting the namespace outright would have broken these: their `options`
    // parameters are typed `Intl.NumberFormatOptions` and friends, so the
    // interfaces have to survive even though the constructors must not.
    $ts = new Terrarium($wasm);
    eq([], $ts->check(
        "const a: string = (1234.5).toLocaleString(\"en\", { minimumFractionDigits: 2 });\n" .
        "const b: number = \"a\".localeCompare(\"b\", \"en\", { sensitivity: \"base\" });\n" .
        "const c: string = new Date(0).toLocaleDateString(\"en\", { year: \"numeric\" });\n" .
        "[a, b, c];\n"
    ));
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

echo "\nwarm reuse with per-call timeouts\n";
check('the same checked source executes afresh with current callbacks and output', function () use ($wasm) {
    $rt = new \Terrarium\Runtime(file_get_contents($wasm));
    $rt->register('value', static fn (): int => 7);
    $rt->setTypes('declare function value(): number;');
    $source = <<<'TS'
        const state = globalThis as typeof globalThis & { count?: number };
        state.count = (state.count || 0) + 1;
        console.log(value());
        state.count;
        TS;
    eq([], $rt->check($source));
    eq('', $rt->output());
    eq(1, $rt->eval($source));
    eq('7', $rt->output());
    $rt->register('value', static fn (): int => 9);
    eq([], $rt->check($source));
    eq('7', $rt->output());
    eq(1, $rt->eval($source));
    eq('9', $rt->output());
});

check('changing source or SDK invalidates the checked program', function () use ($wasm) {
    $rt = new \Terrarium\Runtime(file_get_contents($wasm));
    $called = false;
    $rt->register('value', static function () use (&$called): int { $called = true; return 7; });
    $rt->setTypes('declare function value(): number;');
    $source = 'const n: number = value(); n;';
    eq([], $rt->check($source));
    $rt->setTypes('declare function value(): string;');
    throws(GuestException::class, fn () => $rt->eval($source));
    eq(false, $called);
    $rt->setTypes('declare function value(): number;');
    eq(7, $rt->eval($source));
    eq('TS2322', $rt->check('const n: string = value(); n;')[0]['type']);
    eq([], $rt->check($source));
});

// The parsed SDK declarations are cached inside the compiler context so that a
// new source does not re-read them (the cost was linear in the .d.ts). Nothing
// below is a timing assertion: the contract is that the cache is invisible —
// every check sees exactly the declarations registered at the time it runs, and
// never anything from another check's source.
echo "\nthe parsed SDK declarations are reused, never observed stale\n";
check('distinct sources check independently against one large SDK', function () use ($wasm) {
    $rt = new \Terrarium\Runtime(file_get_contents($wasm));
    $dts = '';
    for ($i = 0; $i < 200; $i++) {
        $dts .= sprintf(
            "interface Rec%1\$d { id: string; count: number; tags?: string[] }\n"
            . "declare function op%1\$d(input: Rec%1\$d, limit?: number): Rec%1\$d[];\n",
            $i
        );
    }
    eq(true, strlen($dts) > 20000);
    $rt->setTypes($dts);
    for ($i = 0; $i < 20; $i++) {
        eq([], $rt->check(sprintf('const r%1$d: Rec%1$d[] = op%1$d({ id: "a", count: %1$d }); r%1$d;', $i)));
        $bad = $rt->check(sprintf('const r%1$d: number = op%1$d({ id: "a", count: %1$d }); r%1$d;', $i));
        eq(1, count($bad));
        eq('TS2322', $bad[0]['type']);
        eq(1, $bad[0]['line']);
    }
    // A declaration the SDK never had is still unknown, however many checks ran.
    eq('TS2552', $rt->check('op999({ id: "a", count: 1 });')[0]['type']);
    // ... and one source's declarations never reach the next.
    eq([], $rt->check('const leaked: Rec0 = { id: "a", count: 1 }; leaked;'));
    eq('TS2304', $rt->check('const n: number = leaked.count; n;')[0]['type']);
});

check('replacing the SDK between checks is seen at once', function () use ($wasm) {
    $rt = new \Terrarium\Runtime(file_get_contents($wasm));
    $rt->setTypes('declare function op(input: string): string[];');
    $source = 'const r: string[] = op("a"); r;';
    eq([], $rt->check($source));
    // The declaration is gone: a call that checked clean must stop checking.
    $rt->setTypes('declare const other: number;');
    eq('TS2304', $rt->check($source)[0]['type']);
    // ... and the replacement is live in the same breath.
    eq([], $rt->check('const n: number = other; n;'));
    // Restored, then narrowed in place -- same name, different type.
    $rt->setTypes('declare function op(input: string): string[];');
    eq([], $rt->check($source));
    eq('TS2304', $rt->check('const n: number = other; n;')[0]['type']);
    $rt->setTypes('declare function op(input: string): number[];');
    eq('TS2322', $rt->check($source)[0]['type']);
    eq([], $rt->check('const r: number[] = op("a"); r;'));
});

check('a malformed declaration is reported against /sdk.d.ts', function () use ($wasm) {
    $rt = new \Terrarium\Runtime(file_get_contents($wasm));
    $rt->setTypes('declare function broken(: string;');
    $diags = $rt->check('const n: number = 1; n;');
    eq(true, count($diags) > 0);
    contains($diags[0]['message'], '(in /sdk.d.ts)');
    eq(false, isset($diags[0]['line']));   // not a line in the submitted source
    eq($diags, $rt->check('const s: string = "x"; s;'));
    // Repairing it clears the diagnostic without a new Runtime.
    $rt->setTypes('declare function broken(input: string): void;');
    eq([], $rt->check('const n: number = 1; n;'));
    eq([], $rt->check('broken("x");'));
});

check('a stack-exhausting source traps the guest without poisoning the next check', function () use ($wasm) {
    $rt = new \Terrarium\Runtime(file_get_contents($wasm));
    $rt->setTypes('declare function op(input: string): string[];');
    $source = 'const r: string[] = op("a"); r;';
    eq([], $rt->check($source));
    // Nesting deep enough to exhaust the *wasm* stack while the compiler
    // recurses over it. Which failure this is, is measured rather than assumed:
    // it is a sandbox-level trap, so the driver's own JS catch (the one that
    // drops its cached program and parsed SDK file) never runs -- the guest
    // never regains control. Recovery is the host's instead: a trap discards
    // the Store, and the whole guest goes with it -- caches, parsed SDK file,
    // warm checker -- so the next check starts from a fresh instance. Pinning
    // the concrete class is the point; the base class would accept either path
    // and so prove neither.
    $deep = 'const x = ' . str_repeat('(', 20000) . '1' . str_repeat(')', 20000) . '; x;';
    try {
        $rt->check($deep);
        throw new RuntimeException('expected the nesting to be refused');
    } catch (TrapException $e) {
        contains($e->getMessage(), 'call stack exhausted');
    }
    eq([], $rt->check($source));
    eq('TS2322', $rt->check('const r: number = op("a"); r;')[0]['type']);
    $rt->setTypes('declare const other: number;');
    eq('TS2304', $rt->check($source)[0]['type']);
    eq([], $rt->check('const n: number = other; n;'));
});

check('a source cannot merge declarations into the cached SDK file', function () use ($wasm) {
    $rt = new \Terrarium\Runtime(file_get_contents($wasm));
    $rt->setTypes('interface Rec0 { id: string }');
    // Declaration merging is TypeScript's rule and must hold for the source
    // that wrote it: inside this Program, Rec0 has both members.
    eq([], $rt->check('interface Rec0 { extra: string } const r: Rec0 = { id: "a", extra: "b" }; r;'));
    // ... and only inside it. The next check is handed the *same* cached
    // /sdk.d.ts SourceFile, so a merge that had mutated it would leave `extra`
    // declared for a source that never declared it.
    $leaked = $rt->check('const s: string = ({} as Rec0).extra; s;');
    eq(1, count($leaked));
    eq('TS2339', $leaked[0]['type']);
    contains($leaked[0]['message'], "Property 'extra' does not exist");
    eq('TS2339', $rt->check('const t: string = ({} as Rec0).extra; t;')[0]['type']);
    eq([], $rt->check('const u: string = ({} as Rec0).id; u;'));   // the SDK's own member survives

    // The same for a namespace, whose members merge into an existing one.
    $rt->setTypes('declare namespace Cfg { const a: string }');
    eq([], $rt->check('declare namespace Cfg { const b: string } const s: string = Cfg.b; s;'));
    $leaked = $rt->check('const s: string = Cfg.b; s;');
    eq(1, count($leaked));
    eq('TS2339', $leaked[0]['type']);
    eq([], $rt->check('const s: string = Cfg.a; s;'));
});

check('a large SDK costs no more per check than an empty one', function () use ($wasm) {
    // The one deliberate timing assertion in this suite, and it earns its place:
    // defeating the SDK SourceFile cache -- as comparing the per-program options
    // object by identity once did, since `createProgram` then declines to reuse
    // the old program -- changes no result anywhere, only the cost. Every
    // functional check above passes either way; only a measurement sees it.
    // Both medians come from this one process and this one Runtime, and the
    // bound is wide: ~1.2x with the cache in place, ~65x without it.
    $rt = new \Terrarium\Runtime(file_get_contents($wasm));
    $seq = 0;
    $medianCheckMs = function (int $samples) use ($rt, &$seq): float {
        $times = [];
        for ($i = 0; $i < $samples; $i++) {
            // Distinct every time, so the driver's identical-source shortcut
            // never answers instead of the compiler.
            $source = sprintf('const v%1$d: number = %1$d; v%1$d;', $seq++);
            $t = -hrtime(true);
            eq([], $rt->check($source));
            $times[] = ($t + hrtime(true)) / 1e6;
        }
        sort($times);
        return $times[intdiv(count($times), 2)];
    };

    $rt->setTypes('');
    $medianCheckMs(2);            // warm the compiler, the libs and the empty SDK
    $empty = $medianCheckMs(5);

    $dts = '';
    for ($i = 0; $i < 1000; $i++) {
        $dts .= sprintf(
            "interface Big%1\$d { id: string; count: number; tags?: string[] }\n"
            . "declare function big%1\$d(input: Big%1\$d, limit?: number): Big%1\$d[];\n",
            $i
        );
    }
    eq(true, strlen($dts) > 120000);
    $rt->setTypes($dts);
    $medianCheckMs(1);            // the one check that legitimately parses it
    $large = $medianCheckMs(5);

    printf(
        "  median check: empty SDK %.1f ms, %d KB SDK %.1f ms (%.1fx)\n",
        $empty,
        intdiv(strlen($dts), 1024),
        $large,
        $large / $empty
    );
    if ($large > 10 * $empty) {
        throw new RuntimeException(sprintf(
            'the SDK looks re-parsed per check: %.1f ms against %.1f ms for an empty SDK',
            $large,
            $empty
        ));
    }
});

check('complete check then eval reuse one TypeScript Runtime with shrinking budgets', function () use ($wasm) {
    $rt = new \Terrarium\Runtime(file_get_contents($wasm));
    $rt->setCompileOptions(['sync_only' => true]);
    foreach ([10000, 9000, 8000] as $timeout) {
        eq([], $rt->check('const n: number = 21; n * 2;', timeoutMs: $timeout));
        eq(42, $rt->eval('const n: number = 21; n * 2;', timeoutMs: $timeout - 1000));
    }
    throws(TimeoutException::class, fn () => $rt->eval('while (true) {}', timeoutMs: 100));
    eq(false, $rt->reset());
    eq([], $rt->check('const n: number = 42; n;', timeoutMs: 10000));
    eq(42, $rt->eval('const n: number = 42; n;', timeoutMs: 10000));
    eq(true, $rt->reset());
    eq(42, $rt->eval('42', timeoutMs: 10000));
});

check('warm callbacks, declarations and options replace without retaining old context', function () use ($wasm) {
    $rt = new \Terrarium\Runtime(file_get_contents($wasm));
    $context = (object) ['value' => 7];
    $weak = WeakReference::create($context);
    $rt->register('__wire.value', static fn (): int => $context->value);
    $rt->setTypes('declare const __wire: { value(): number };');
    unset($context);
    eq([], $rt->check('const n: number = __wire.value(); n;', timeoutMs: 10000));
    eq(7, $rt->eval('__wire.value()', timeoutMs: 10000));
    eq(true, $weak->get() !== null);

    $rt->register('__wire.value', static fn (): string => 'current');
    $rt->setTypes('declare const __wire: { value(): string };');
    eq(null, $weak->get());
    eq(['__wire.value'], $rt->manifest());
    eq(true, count($rt->check('const n: number = __wire.value(); n;', timeoutMs: 10000)) > 0);
    throws(GuestException::class, fn () => $rt->eval('const n: number = __wire.value(); n;', timeoutMs: 10000));
    eq('current', $rt->eval('__wire.value()', timeoutMs: 10000));

    $rt->setCompileOptions(['sync_only' => true]);
    eq('TSSyncOnly', $rt->check('async function f() { return 1; }', timeoutMs: 10000)[0]['type']);
    $rt->setCompileOptions([]);
    eq([], $rt->check('async function f() { return 1; }', timeoutMs: 10000));

    $rt->setCompileOptions(['sync_only' => true]);
    $resource = new stdClass;
    $handle = $rt->grant($resource);
    eq('current', $rt->eval('console.log("saved"); __wire.value()', timeoutMs: 10000));
    eq(true, $rt->reset());
    eq(false, $rt->reset());
    eq('saved', $rt->output());
    eq($resource, $rt->resolve($handle));
    eq('TSSyncOnly', $rt->check('async function f() { return 1; }', timeoutMs: 10000)[0]['type']);
    eq(true, count($rt->check('const n: number = __wire.value();', timeoutMs: 10000)) > 0);
    eq('current', $rt->eval('__wire.value()', timeoutMs: 10000));
    eq('', $rt->output());
    eq(true, $rt->revoke($handle));
});

check('timed calls preserve output and program error contracts', function () use ($wasm) {
    $rt = new \Terrarium\Runtime(file_get_contents($wasm));
    eq(42, $rt->eval('console.log("saved"); 42', timeoutMs: 10000));
    foreach (['eval', 'check', 'analyze'] as $entry) {
        throws(TerrariumException::class, fn () => $rt->$entry('1', timeoutMs: -1));
        throws(TerrariumException::class, fn () => $rt->$entry('1', timeoutMs: []));
        eq('saved', $rt->output());
    }
    eq([], $rt->check('42', timeoutMs: 10000));
    eq('saved', $rt->output());
    eq(['diagnostics' => [], 'schemas' => []], $rt->analyze('42', timeoutMs: 10000));
    eq('saved', $rt->output());
    throws(GuestException::class, fn () => $rt->eval('console.log("before"); throw new Error("boom");', timeoutMs: 10000));
    eq('before', $rt->output());
    eq(true, $rt->reset()); // an ordinary guest error did not poison the Store

    $rt->register('pause', static function (): int { usleep(1_200_000); return 1; });
    $rt->setTypes('declare function pause(): number;');
    // Warm the compiler, then expire while PHP is blocked. It finishes the
    // callback, but cannot run the next statement or return a late success.
    eq([], $rt->check('console.log("partial"); pause(); console.log("late");', timeoutMs: 10000));
    throws(TimeoutException::class, fn () => $rt->eval('console.log("partial"); pause(); console.log("late");', timeoutMs: 1000));
    eq('partial', $rt->output());
    eq(false, $rt->reset());
    eq(42, $rt->eval('42', timeoutMs: 10000));
    eq('', $rt->output());
});

check('facade forwards named timeouts to check and analyze as well as eval', function () use ($wasm) {
    $ts = new Terrarium($wasm);
    eq([], $ts->check('42', timeoutMs: 10000));
    eq(['diagnostics' => [], 'schemas' => []], $ts->analyze('42', timeoutMs: 10000));
    eq(42, $ts->eval('42', timeoutMs: 10000));
    foreach (['eval', 'check', 'analyze'] as $entry) {
        throws(TerrariumException::class, fn () => $ts->$entry('42', timeoutMs: -1));
    }
});

summary();

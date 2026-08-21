<?php
// The ES surface the TypeScript guest's engine actually has. quickjs-ng v0.16.2
// implements years more of the language than the compiler in front of it is
// pinned to, and the two must not drift apart: the type environment has to equal
// the real execution environment, so every raise of the checker's `lib` needs
// evidence that the engine really implements what the lib declares — and that
// what the engine lacks stays undeclared.
//
// This suite is that evidence, pinned as behaviour. Every probe runs under
// `// @ts-nocheck`, so it asks the ENGINE and never the checker: it is green
// before and after any `target`/`lib` change, and it fails when an engine bump
// silently adds or drops something. The known-absent cases are asserted absent
// for the same reason — they are the exclusions a lib raise has to honour.
//
// Full evidence (per-feature × lib × verdict, and the checker's verdict at the
// current pin) lives in guests/typescript/tools/lib-audit/.
//
// Skips cleanly until typescript_guest.wasm is built (see `make typescript-guest`).

declare(strict_types=1);

require __DIR__ . '/_harness.php';
use Terrarium\Terrarium;
use Terrarium\GuestException;

$wasm = require_guest(__DIR__ . '/../wasm/typescript_guest.wasm');

// One warm guest for the whole suite: these probes are pure, and the compiler
// context is expensive to bring up.
$ts = new Terrarium($wasm, timeoutMs: 10000);

/** Evaluate a probe with the checker deliberately out of the way. */
$engine = function (string $source) use ($ts) {
    return $ts->eval("// @ts-nocheck\n" . $source);
};

echo "ES2021\n";
check('String.prototype.replaceAll', fn () => eq('cb', $engine("'ab'.replaceAll('a', 'c')")));
check('replaceAll with a global RegExp', fn () => eq('bXb', $engine("'aXa'.replaceAll(/a/g, 'b')")));
check('replaceAll rejects a non-global RegExp', fn () => eq('TypeError', $engine(
    "try { 'aa'.replaceAll(/a/, 'b'); 'no throw' } catch (e) { e.constructor.name }"
)));
check('Promise.any exists', fn () => eq('function', $engine('typeof Promise.any')));
check('AggregateError carries .errors', fn () => eq('1:m:true', $engine(
    "const e = new AggregateError([new Error('a')], 'm'); e.errors.length + ':' + e.message + ':' + (e instanceof Error)"
)));
check('WeakRef.deref', fn () => eq(1, $engine('const o = { a: 1 }; const r = new WeakRef(o); r.deref().a')));
check('FinalizationRegistry register/unregister', fn () => eq(true, $engine(
    "const r = new FinalizationRegistry(() => {}); const o = {}; r.register(o, 't', o); r.unregister(o)"
)));
check('numeric separators', fn () => eq(1000000, $engine('1_000_000')));

echo "\nES2022\n";
check('Array.prototype.at, negative and out of range', fn () => eq('b:3:undefined', $engine(
    "[...'ab'].at(-1) + ':' + [1, 2, 3].at(-1) + ':' + String([1, 2].at(5))"
)));
check('String.prototype.at', fn () => eq('c:undefined', $engine("'abc'.at(-1) + ':' + String('ab'.at(9))")));
check('%TypedArray%.prototype.at, every element kind', fn () => eq('2,2,2,2,2,2,2,2,2,2,2', $engine(
    '[new Int8Array([1,2]), new Uint8Array([1,2]), new Uint8ClampedArray([1,2]), new Int16Array([1,2]),
      new Uint16Array([1,2]), new Int32Array([1,2]), new Uint32Array([1,2]), new Float32Array([1,2]),
      new Float64Array([1,2]), new BigInt64Array([1n,2n]), new BigUint64Array([1n,2n])
     ].map(a => String(a.at(-1))).join(",")'
)));
check('Object.hasOwn is own-only', fn () => eq('true:false', $engine(
    "String(Object.hasOwn({ a: 1 }, 'a')) + ':' + String(Object.hasOwn(Object.create({ p: 1 }), 'p'))"
)));
check('Error cause (and no `cause` property without the option)', fn () => eq('true:9:false', $engine(
    "const a = new Error('a');
     const b = new Error('b', { cause: a });
     String(b.cause === a) + ':' + new RangeError('r', { cause: 9 }).cause + ':' + String('cause' in new Error('x'))"
)));
check('RegExp `d` flag: hasIndices and match indices', fn () => eq('[[0,2],[1,2]]:true:0,1:undefined:[0,1]', $engine(
    "JSON.stringify(/a(b)/d.exec('ab').indices)
     + ':' + /a/d.hasIndices
     + ':' + /(?<y>a)/d.exec('a').indices.groups.y.join(',')
     + ':' + String(/(a)|(b)/d.exec('a').indices[2])
     + ':' + JSON.stringify('ab'.match(/a/d).indices[0])"
)));
check('class fields, instance and static', fn () => eq(3, $engine('class C { x = 1; static s = 2 } new C().x + C.s')));
check('class static initialization block', fn () => eq(5, $engine('class C { static v; static { C.v = 5 } } C.v')));
check('#private fields, #private methods, `#x in obj`', fn () => eq('7:3:true', $engine(
    'class C {
       #x = 7;
       #m() { return 3 }
       get() { return this.#x }
       run() { return this.#m() }
       static has(o) { return #x in o }
     }
     const c = new C(); c.get() + ":" + c.run() + ":" + C.has(c)'
)));

echo "\nES2023\n";
check('Array findLast / findLastIndex', fn () => eq('2:1', $engine(
    '[1, 2, 3].findLast(x => x < 3) + ":" + [1, 2, 3, 4].findLastIndex(x => x < 3)'
)));
check('Array change-by-copy leaves the original alone', fn () => eq('[1,2,10]:[2,10,1]:[1,"x",2]:[9,10,2]:[1,10,2]', $engine(
    'const xs = [1, 10, 2];
     JSON.stringify(xs.toSorted((a, b) => a - b))
     + ":" + JSON.stringify(xs.toReversed())
     + ":" + JSON.stringify(xs.toSpliced(1, 1, "x"))
     + ":" + JSON.stringify(xs.with(0, 9))
     + ":" + JSON.stringify(xs)'
)));
check('%TypedArray% findLast / toSorted / toReversed / with', fn () => eq('2:2:1,2,10:2,10,1:9,10,2:1,3', $engine(
    'const t = new Int32Array([1, 10, 2]);
     t.findLast(x => x < 3)
     + ":" + t.findLastIndex(x => x < 3)
     + ":" + Array.from(t.toSorted((a, b) => a - b)).join(",")
     + ":" + Array.from(t.toReversed()).join(",")
     + ":" + Array.from(t.with(0, 9)).join(",")
     + ":" + Array.from(new BigInt64Array([3n, 1n]).toSorted()).map(String).join(",")'
)));
check('symbols as weak keys (registered symbols still rejected)', fn () => eq('1:true:symbol:TypeError', $engine(
    "const s = Symbol('k');
     const m = new WeakMap(); m.set(s, 1);
     const w = new WeakSet(); w.add(s);
     const registered = (() => { try { new WeakMap().set(Symbol.for('g'), 1); return 'accepted' } catch (e) { return e.constructor.name } })();
     m.get(s) + ':' + w.has(s) + ':' + typeof new WeakRef(s).deref() + ':' + registered"
)));
check('hashbang grammar', function () use ($ts) {
    // A hashbang has to be the first byte of the file, so this one probe cannot
    // carry the `@ts-nocheck` pragma. It is checked as well as run — the checker
    // accepts the grammar at every target, so the probe stays pin-independent.
    eq(42, $ts->eval("#!/usr/bin/env node\nconst n: number = 42; n"));
});

echo "\nES2024\n";
check('Object.groupBy returns a null-prototype object', fn () => eq('{"odd":[1,3],"even":[2]}:true', $engine(
    "JSON.stringify(Object.groupBy([1, 2, 3], x => x % 2 ? 'odd' : 'even'))
     + ':' + (Object.getPrototypeOf(Object.groupBy([1], () => 'a')) === null)"
)));
check('Map.groupBy returns a Map', fn () => eq('1,3:true', $engine(
    "const m = Map.groupBy([1, 2, 3], x => x % 2 ? 'odd' : 'even'); m.get('odd').join(',') + ':' + (m instanceof Map)"
)));
check('Promise.withResolvers', fn () => eq('function,function,function', $engine(
    'const w = Promise.withResolvers(); [typeof w.promise.then, typeof w.resolve, typeof w.reject].join(",")'
)));
check('String isWellFormed / toWellFormed', fn () => eq('true:false:fffd', $engine(
    "String('ab'.isWellFormed())
     + ':' + String('a\\uD800'.isWellFormed())
     + ':' + 'a\\uD800'.toWellFormed().charCodeAt(1).toString(16)"
)));
check('resizable ArrayBuffer', fn () => eq('true:16:12:false:8', $engine(
    'const b = new ArrayBuffer(8, { maxByteLength: 16 });
     b.resize(12);
     const c = new ArrayBuffer(8);
     b.resizable + ":" + b.maxByteLength + ":" + b.byteLength + ":" + c.resizable + ":" + c.maxByteLength'
)));
check('ArrayBuffer transfer detaches the source and its views', fn () => eq('true:8:0:4:false', $engine(
    'const b = new ArrayBuffer(8);
     const view = new Uint8Array(b);
     const c = b.transfer();
     const d = new ArrayBuffer(8).transferToFixedLength(4);
     b.detached + ":" + c.byteLength + ":" + view.length + ":" + d.byteLength + ":" + new ArrayBuffer(4).detached'
)));
check('RegExp `v` flag: set difference, intersection, string properties', fn () => eq('true:true:false:true:true', $engine(
    "/a/v.unicodeSets
     + ':' + /[\\p{ASCII}--[a-z]]/v.test('A')
     + ':' + /[\\p{ASCII}--[a-z]]/v.test('a')
     + ':' + /[\\p{ASCII}&&\\p{Letter}]/v.test('a')
     + ':' + /[\\q{abc}]/v.test('abc')"
)));

// What the engine does NOT have. These are the exclusions any lib raise has to
// honour: a checker that accepts this code is lying about the environment.
echo "\nabsent from the engine (assert the holes, so a raise cannot paper over them)\n";
check('no Atomics at all', fn () => eq('ReferenceError:Atomics is not defined', $engine(
    "try { Atomics.add(new Int32Array(4), 0, 1); 'present' } catch (e) { e.constructor.name + ':' + e.message }"
)));
check('SharedArrayBuffer exists but can never be growable', fn () => eq(
    'false:8:TypeError:growable SharedArrayBuffer requires SAB allocator hooks:TypeError:array buffer is not resizable',
    $engine(
        "const plain = new SharedArrayBuffer(8);
         const ctor = (() => { try { new SharedArrayBuffer(8, { maxByteLength: 16 }); return 'ok' } catch (e) { return e.constructor.name + ':' + e.message } })();
         const grow = (() => { try { plain.grow(12); return 'ok' } catch (e) { return e.constructor.name + ':' + e.message } })();
         plain.growable + ':' + plain.maxByteLength + ':' + ctor + ':' + grow"
    )
));
check('no Intl', fn () => eq('ReferenceError:Intl is not defined', $engine(
    "try { new Intl.NumberFormat('en').format(1) } catch (e) { e.constructor.name + ':' + e.message }"
)));
check('...but the locale-blind formatters it shadows do exist', fn () => eq('1234.5:-1:function', $engine(
    "(1234.5).toLocaleString('en') + ':' + 'a'.localeCompare('b') + ':' + typeof new Date(0).toLocaleDateString"
)));
check('no structuredClone (an HTML API, not an ECMAScript one)', fn () => eq('ReferenceError:structuredClone is not defined', $engine(
    "try { structuredClone({ a: 1 }); 'present' } catch (e) { e.constructor.name + ':' + e.message }"
)));
check('`accessor` fields are not parsed by the engine', fn () => eq('SyntaxError', $engine(
    "try { eval('class C { accessor x = 1 }'); 'present' } catch (e) { e.constructor.name }"
)));

// PARITY. The probes above ask the engine. These ask whether the CHECKER agrees
// — which is the property the whole suite exists for, and the one that was
// quietly broken: `new Intl.NumberFormat()` and `accessor` fields both passed
// check() and then died at eval, so a program could publish clean and fail on
// its first run. An engine hole is only honoured if check() refuses it too.
echo "\nparity: what the engine lacks, check() must refuse (not merely eval)\n";
$refuses = function (string $label, string $source, ?string $type = null) use ($ts) {
    check($label, function () use ($ts, $source, $type) {
        $diags = $ts->check($source);
        if ($diags === []) {
            throw new RuntimeException("check() was clean; this dies at run time:\n$source");
        }
        if ($type !== null) {
            eq($type, $diags[0]['type']);
        }
    });
};
$refuses('Intl is not a value: new Intl.NumberFormat()', 'const s: string = new Intl.NumberFormat("en").format(1);');
$refuses('...nor Intl.DateTimeFormat', 'const d = new Intl.DateTimeFormat("en");\nd;');
$refuses('...nor Intl.Collator', 'const c = new Intl.Collator("en");\nc;');
$refuses('Atomics is undeclared', 'Atomics.add(new Int32Array(4), 0, 1);');
$refuses('structuredClone is undeclared', 'structuredClone({ a: 1 });');
$refuses('a growable SharedArrayBuffer is undeclared', 'new SharedArrayBuffer(8, { maxByteLength: 16 });');
$refuses('`accessor` members are refused as TSEngineUnsupported', 'class C { accessor x = 1 }', 'TSEngineUnsupported');
check('...and the locale-blind formatters that DO exist still check clean', function () use ($ts) {
    // The other half of the Intl parity: stripping the namespace's VALUES must
    // not take the option TYPES its surviving signatures reference with it.
    eq([], $ts->check(
        "const a: string = (1234.5).toLocaleString(\"en\", { minimumFractionDigits: 2 });\n" .
        "const b: number = \"a\".localeCompare(\"b\");\n" .
        "const c: string = new Date(0).toLocaleDateString(\"en\");\n" .
        "[a, b, c];\n"
    ));
});
check('...and an explicit get/set pair still checks and runs', function () use ($ts) {
    $source = "class C {\n  #x = 1;\n  get x(): number { return this.#x; }\n  set x(v: number) { this.#x = v; }\n}\nconst c = new C();\nc.x = 2;\nc.x";
    eq([], $ts->check($source . ";\n"));
    eq(2, $ts->eval($source));
});

// The engine runs ahead of any lib we would pin: recorded so a future raise can
// see what is already there, and so a regression here is caught.
echo "\nahead of the pinned lib (present, deliberately undeclared)\n";
check('Array.fromAsync exists', fn () => eq('function', $engine('typeof Array.fromAsync')));
check('`using` declarations and Symbol.dispose', fn () => eq('symbol:true', $engine(
    'let disposed = false;
     { using r = { [Symbol.dispose]() { disposed = true } }; }
     typeof Symbol.dispose + ":" + disposed'
)));

// The surface above is what the engine has; none of it changes the one rule the
// sandbox actually enforces at run time.
echo "\nthe new surface does not open an escape hatch\n";
check('async APIs still cannot complete', function () use ($ts) {
    try {
        $ts->eval("// @ts-nocheck\nPromise.any([Promise.resolve(1)])");
        throw new RuntimeException('expected an AsyncIncomplete rejection');
    } catch (GuestException $e) {
        contains($e->getMessage(), 'AsyncIncomplete');
    }
});
check('no host reached, no globals carried between evals', function () use ($ts, $engine) {
    eq('undefined', $engine('typeof globalThis.__leak'));
    $engine('globalThis.__leak = 1');
    eq('undefined', $engine('typeof globalThis.__leak'));
});

summary();

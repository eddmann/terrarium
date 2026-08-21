<?php
// Type argument -> JSON Schema. With the `typeArgumentSchemas` option the
// TypeScript guest resolves the single type argument of every call to a named
// callee and serialises it to JSON Schema, returned from analyze() alongside
// the usual diagnostics.
//
// The inversion this exists for: instead of writing a schema literal and
// inferring the result type from it, the author writes the TYPE and the host
// derives the schema. That only works if the emitted schema is exactly what the
// other side can read back as the same type — so the accepted subset is small
// and everything outside it is REFUSED, by member path, rather than approximated.
//
// Each entry is {ordinal, callee, line, schema}: the ordinal is the identity
// (it survives a reformat), the line is the runtime bridge back to the call
// site, and the schema is canonical JSON text whose bytes neither of the other
// two may disturb.
//
// Skips cleanly until typescript_guest.wasm is built (see `make typescript-guest`).

declare(strict_types=1);

require __DIR__ . '/_harness.php';
use Terrarium\Terrarium;

$wasm = require_guest(__DIR__ . '/../wasm/typescript_guest.wasm');

// The SDK under test. Generic capability methods cannot be inferred from a PHP
// closure, so the type environment is declared in the source itself — exactly
// how a host that ships a hand-written `.d.ts` would present them.
const SDK = 'declare const ctx: { agent<T>(input: unknown): T; model<T>(input: unknown): T; emit(value: unknown): void };';

const DECLS = <<<'TS'
    interface Verdict { id: string; score: number }
    type Alias = { a: string };
    interface Base { b: string }
    interface Derived extends Base { d: number }
    class Klass { x = 1; }
    enum Color { Red, Green }
    interface Cyclic { name: string; next: Cyclic }
    interface Tree { name: string; kids: Tree[] }
    TS;

/** A one-call program whose type argument is the type under test. */
function program(string $type): string
{
    return SDK . "\n" . DECLS . "\nconst r = ctx.agent<{$type}>({});\nr;\n";
}

/** The 1-based line `program()` puts its single call on: SDK, then DECLS. */
const PROGRAM_LINE = 10;

function guest(string $wasm): Terrarium
{
    return new Terrarium($wasm, typeArgumentSchemas: ['ctx.model', 'ctx.agent']);
}

/** Only the schema diagnostics — a few reject cases legitimately fail tsc too. */
function schemaErrors(array $analysis): array
{
    return array_values(array_filter(
        $analysis['diagnostics'],
        fn (array $d): bool => ($d['type'] ?? '') === 'TSSchemaError'
    ));
}

echo "the accepted subset: exact schema JSON for every expressible type\n";
$accepts = function (string $type, string $expected) use ($wasm) {
    check($type, function () use ($wasm, $type, $expected) {
        $out = guest($wasm)->analyze(program($type));
        eq([], $out['diagnostics']);              // the program itself is clean
        eq(1, count($out['schemas']));
        eq(0, $out['schemas'][0]['ordinal']);
        eq('ctx.agent', $out['schemas'][0]['callee']);
        eq(PROGRAM_LINE, $out['schemas'][0]['line']);
        eq($expected, $out['schemas'][0]['schema']);
        // The whole entry, so a field appearing or vanishing is caught here and
        // not only where it is asserted.
        eq(['ordinal', 'callee', 'line', 'schema'], array_keys($out['schemas'][0]));
    });
};

// Objects: declaration order, `?` members omitted from required, closed.
$accepts('{ a: string; b: number; c: boolean }',
    '{"type":"object","properties":{"a":{"type":"string"},"b":{"type":"number"},"c":{"type":"boolean"}},"required":["a","b","c"],"additionalProperties":false}');
$accepts('{ a: string; b?: number }',
    '{"type":"object","properties":{"a":{"type":"string"},"b":{"type":"number"}},"required":["a"],"additionalProperties":false}');
$accepts('{ readonly a: string }',          // readonly says nothing about JSON
    '{"type":"object","properties":{"a":{"type":"string"}},"required":["a"],"additionalProperties":false}');
$accepts('{}',
    '{"type":"object","properties":{},"required":[],"additionalProperties":false}');
$accepts('{ a: { b: { c: string } } }',
    '{"type":"object","properties":{"a":{"type":"object","properties":{"b":{"type":"object","properties":{"c":{"type":"string"}},"required":["c"],"additionalProperties":false}},"required":["b"],"additionalProperties":false}},"required":["a"],"additionalProperties":false}');
$accepts('Verdict',
    '{"type":"object","properties":{"id":{"type":"string"},"score":{"type":"number"}},"required":["id","score"],"additionalProperties":false}');
$accepts('Alias',
    '{"type":"object","properties":{"a":{"type":"string"}},"required":["a"],"additionalProperties":false}');
$accepts('Derived',                          // own members first, then inherited
    '{"type":"object","properties":{"d":{"type":"number"},"b":{"type":"string"}},"required":["d","b"],"additionalProperties":false}');

// Scalars. `number` stays `number`: JSON Schema's `integer` is a narrower claim
// than TypeScript ever made, and guessing it would reject valid model output.
$accepts('string', '{"type":"string"}');
$accepts('number', '{"type":"number"}');
$accepts('boolean', '{"type":"boolean"}');
$accepts('null', '{"type":"null"}');

// Arrays, readonly arrays and their generic spellings are all just `array`.
$accepts('string[]', '{"type":"array","items":{"type":"string"}}');
$accepts('readonly string[]', '{"type":"array","items":{"type":"string"}}');
$accepts('ReadonlyArray<number>', '{"type":"array","items":{"type":"number"}}');
$accepts('string[][]', '{"type":"array","items":{"type":"array","items":{"type":"string"}}}');
$accepts('Verdict[]',
    '{"type":"array","items":{"type":"object","properties":{"id":{"type":"string"},"score":{"type":"number"}},"required":["id","score"],"additionalProperties":false}}');

// Literals and literal unions.
$accepts('"yes"', '{"const":"yes"}');
$accepts('42', '{"const":42}');
$accepts('true', '{"const":true}');
$accepts('"a" | "b" | "c"', '{"type":"string","enum":["a","b","c"]}');
$accepts('1 | 2 | 3', '{"type":"number","enum":[1,2,3]}');
// A mixed-literal union is still all-JSON, so it enumerates — with no single
// `type` to claim.
$accepts('"a" | 1 | true', '{"enum":[1,"a",true]}');

// Nullability, in the one spelling a type-level reader understands.
$accepts('string | null', '{"type":["string","null"]}');
$accepts('number | null', '{"type":["number","null"]}');
$accepts('boolean | null', '{"type":["boolean","null"]}');
$accepts('"a" | "b" | null', '{"type":["string","null"],"enum":["a","b",null]}');
$accepts('{ a?: string | null }',
    '{"type":"object","properties":{"a":{"type":["string","null"]}},"required":[],"additionalProperties":false}');

// Type-level machinery that still lands on a plain object.
$accepts('{ a: string } & { b: number }',
    '{"type":"object","properties":{"a":{"type":"string"},"b":{"type":"number"}},"required":["a","b"],"additionalProperties":false}');
$accepts('Partial<{ a: string; b: number }>',
    '{"type":"object","properties":{"a":{"type":"string"},"b":{"type":"number"}},"required":[],"additionalProperties":false}');
$accepts('Pick<Verdict, "id">',
    '{"type":"object","properties":{"id":{"type":"string"}},"required":["id"],"additionalProperties":false}');
$accepts('Omit<Verdict, "id">',
    '{"type":"object","properties":{"score":{"type":"number"}},"required":["score"],"additionalProperties":false}');

// The realistic shape, end to end.
$accepts('{ verdicts: { id: string; judgment: "pass" | "fail"; confidence: number; notes?: string }[]; reviewed: boolean }',
    '{"type":"object","properties":{"verdicts":{"type":"array","items":{"type":"object","properties":'
    . '{"id":{"type":"string"},"judgment":{"type":"string","enum":["pass","fail"]},"confidence":{"type":"number"},"notes":{"type":"string"}},'
    . '"required":["id","judgment","confidence"],"additionalProperties":false}},"reviewed":{"type":"boolean"}},'
    . '"required":["verdicts","reviewed"],"additionalProperties":false}');

echo "\nkeys keep declaration order, whatever they are named\n";
check('integer-like keys are not reshuffled', function () use ($wasm) {
    // The reason the JSON is emitted as text rather than JSON.stringify'd: an
    // engine may order integer-like object keys ahead of the rest.
    $out = guest($wasm)->analyze(program('{ "2": string; "10": string; "1": string }'));
    eq('{"type":"object","properties":{"2":{"type":"string"},"10":{"type":"string"},"1":{"type":"string"}},"required":["2","10","1"],"additionalProperties":false}',
        $out['schemas'][0]['schema']);
});

echo "\nthe refusals: a diagnostic naming the offending member path\n";
$rejects = function (string $label, string $type, string $path, string $reason) use ($wasm) {
    check($label, function () use ($wasm, $type, $path, $reason) {
        $out = guest($wasm)->analyze(program($type));
        eq([], $out['schemas']);                   // nothing is approximated
        $errors = schemaErrors($out);
        eq(1, count($errors));
        eq('TSSchemaError', $errors[0]['type']);
        eq(0, $errors[0]['ordinal']);              // keyed by ordinal, in the data
        contains($errors[0]['message'], 'ctx.agent');
        contains($errors[0]['message'], $path . ': ');
        contains($errors[0]['message'], $reason);
    });
};

$rejects('any', 'any', '<type argument>', '`any` cannot be expressed');
$rejects('unknown', 'unknown', '<type argument>', '`unknown` cannot be expressed');
$rejects('never', 'never', '<type argument>', '`never` cannot be expressed');
$rejects('undefined', 'undefined', '<type argument>', 'cannot be expressed');
$rejects('undefined inside a union', '{ a: string | undefined }', 'a', 'mark the property optional with `?`');
$rejects('function property', '{ verdicts: { judgment: () => string }[] }', 'verdicts[].judgment', 'function types cannot be expressed');
$rejects('method member', '{ f(): void }', 'f', 'function types cannot be expressed');
$rejects('bigint', 'bigint', '<type argument>', '`bigint` cannot be expressed');
$rejects('symbol', 'symbol', '<type argument>', 'symbols cannot be expressed');
$rejects('Date', '{ at: Date }', 'at', 'the built-in type `Date`');
$rejects('Map', 'Map<string, string>', '<type argument>', 'the built-in type `Map`');
$rejects('Promise', 'Promise<string>', '<type argument>', '`Promise` cannot be expressed');
$rejects('class instance', 'Klass', '<type argument>', 'class instances (`Klass`)');
$rejects('TS enum', 'Color', '<type argument>', 'TypeScript `enum` types cannot be expressed');
$rejects('index signature', '{ [k: string]: string }', '<type argument>', 'index signatures cannot be expressed');
$rejects('Record', 'Record<string, number>', '<type argument>', 'index signatures cannot be expressed');
$rejects('tuple', '{ pair: [string, number] }', 'pair', 'tuple types cannot be expressed');
$rejects('object | null', '{ a: string } | null', '<type argument>', 'carries primitives only');
$rejects('array | null', 'string[] | null', '<type argument>', 'carries primitives only');
$rejects('union of objects', '{ a: string } | { b: number }', '<type argument>', 'only nullable primitives and unions of literals');
$rejects('union of primitives', 'string | number', '<type argument>', 'only nullable primitives and unions of literals');
$rejects('recursive type', 'Cyclic', 'next', 'recursive types cannot be expressed');
$rejects('recursion through an array', 'Tree', 'kids[]', 'recursive types cannot be expressed');
$rejects('unresolved name', 'Nope', '<type argument>', 'does not resolve');
$rejects('template literal', '`a-${string}`', '<type argument>', 'template literal types cannot be expressed');
$rejects('intersection with a primitive', '{ a: string } & string', '<type argument>', 'does not reduce');
$rejects('the object keyword', 'object', '<type argument>', '`object` cannot be expressed');
$rejects('index signature deep inside', '{ rows: { cells: { [k: string]: string } }[] }', 'rows[].cells', 'index signatures cannot be expressed');

check('an unresolved generic parameter is refused, not guessed', function () use ($wasm) {
    $out = guest($wasm)->analyze(SDK . "\nfunction f<T>(): void { ctx.agent<T>({}); }\nf;\n");
    eq([], $out['schemas']);
    contains(schemaErrors($out)[0]['message'], 'unresolved type parameter `T`');
});
check('the diagnostic carries the source line for the human', function () use ($wasm) {
    $out = guest($wasm)->analyze(SDK . "\nconst a = ctx.agent<{ ok: boolean }>({});\nconst b = ctx.agent<Date>({});\n[a, b];\n");
    $errors = schemaErrors($out);
    eq(1, count($errors));
    eq(3, $errors[0]['line']);
    eq(1, $errors[0]['ordinal']);
});

echo "\nordinals: the identity that survives a reformat\n";
check('calls are numbered in source order across all matched callees', function () use ($wasm) {
    $out = guest($wasm)->analyze(SDK . <<<'TS'

        const a = ctx.model<{ a: string }>({});
        const b = ctx.agent<{ b: number }>({});
        ctx.emit(ctx.agent<{ c: boolean }>({}));
        const d = ctx.model<{ d: null }>({});
        [a, b, d];
        TS);
    eq([], $out['diagnostics']);
    eq([0, 1, 2, 3], array_column($out['schemas'], 'ordinal'));
    eq(['ctx.model', 'ctx.agent', 'ctx.agent', 'ctx.model'], array_column($out['schemas'], 'callee'));
    eq([2, 3, 4, 5], array_column($out['schemas'], 'line'));
});
check('a refused call still consumes its ordinal (no renumbering)', function () use ($wasm) {
    $out = guest($wasm)->analyze(SDK . <<<'TS'

        const a = ctx.agent<{ a: string }>({});
        const b = ctx.agent<Date>({});
        const c = ctx.agent<{ c: string }>({});
        [a, b, c];
        TS);
    eq([0, 2], array_column($out['schemas'], 'ordinal'));
    eq([2, 4], array_column($out['schemas'], 'line'));   // line follows the call, not the ordinal
    eq(1, schemaErrors($out)[0]['ordinal']);
    eq(3, schemaErrors($out)[0]['line']);
});
check('reformatting changes no ordinal and no schema byte', function () use ($wasm) {
    // THE property. Line:column identity would break on every one of these
    // edits; the ordinal breaks on none of them.
    $original = guest($wasm)->analyze(SDK . <<<'TS'

        const a = ctx.model<{ a: string }>({});
        const b = ctx.agent<{ b: number; c?: "x" | "y" }>({});
        [a, b];
        TS);
    $reformatted = guest($wasm)->analyze(SDK . <<<'TS'

        // a comment nobody had written before

        const renamed   =   ctx
            . model  <  {   a  :  string   }  >  ( {} );

        const second =
            ctx.agent<{
                b: number;
                c?: "x" | "y";
            }>(
                {},
            );

        [ renamed , second ] ;
        TS);
    foreach (['ordinal', 'callee', 'schema'] as $field) {
        eq(array_column($original['schemas'], $field), array_column($reformatted['schemas'], $field));
    }
    // `line` is the one field a reformat is allowed to move, which is exactly
    // why it is not the identity: it is where the call sits in THIS text.
    eq([2, 3], array_column($original['schemas'], 'line'));
    eq([4, 8], array_column($reformatted['schemas'], 'line'));
});
check('adding a call ahead of the others DOES renumber them (documented)', function () use ($wasm) {
    // The ordinal is positional by design: the host re-extracts on every
    // publish, so the numbering is only ever compared within one source.
    $out = guest($wasm)->analyze(SDK . "\nconst z = ctx.agent<{ z: string }>({});\nconst a = ctx.agent<{ a: string }>({});\n[z, a];\n");
    eq('{"type":"object","properties":{"z":{"type":"string"}},"required":["z"],"additionalProperties":false}',
        $out['schemas'][0]['schema']);
});

echo "\nline: the runtime bridge back to the call site\n";
// The ordinal is the identity; the line is how a consumer whose compiled
// artifact is immutable per version finds its schema at RUNTIME. Inside the
// running guest a call knows only what line it is on, so the host pairs
// ordinal -> schema once at publish time and keys the baked schemas by line.
check('line is the 1-based line of the call, matching the diagnostic convention', function () use ($wasm) {
    // Same source, one expressible call and one refused, both on line 2: the
    // TSSchemaError and the surviving entry name the same line, because both
    // are computed from the CallExpression's getStart().
    $out = guest($wasm)->analyze(
        SDK . "\nconst ok = ctx.agent<{ a: string }>({}); const bad = ctx.agent<Date>({});\n[ok, bad];\n"
    );
    eq(2, $out['schemas'][0]['line']);
    eq(2, schemaErrors($out)[0]['line']);
});
check('two matched calls on ONE line share it — extraction does not object', function () use ($wasm) {
    // Whether one line may carry two schemas is the CONSUMER's policy. The
    // extractor reports what is there; the ordinals still separate them.
    $out = guest($wasm)->analyze(SDK . <<<'TS'

        const pair = [ctx.agent<{ a: string }>({}), ctx.model<{ b: number }>({})];
        pair;
        TS);
    eq([], $out['diagnostics']);
    eq([0, 1], array_column($out['schemas'], 'ordinal'));
    eq(['ctx.agent', 'ctx.model'], array_column($out['schemas'], 'callee'));
    eq([2, 2], array_column($out['schemas'], 'line'));
});
check('line tracks the call start, not the type argument or the arguments', function () use ($wasm) {
    // Callee, type argument and argument list each land on a different line;
    // the entry reports where the call BEGINS.
    $out = guest($wasm)->analyze(SDK . <<<'TS'

        const wide = ctx
            .agent<
                { a: string }
            >(
                {},
            );
        wide;
        TS);
    eq([], $out['diagnostics']);
    eq(1, count($out['schemas']));
    eq(2, $out['schemas'][0]['line']);
});
check('entries are sorted by start position, so line is non-decreasing', function () use ($wasm) {
    // The ordering guarantee a line-keyed consumer relies on: entries come in
    // source order, never traversal order, so lines only ever repeat or grow.
    $out = guest($wasm)->analyze(SDK . <<<'TS'

        ctx.emit(ctx.agent<{ a: string }>({}));
        const b = ctx.model<{ b: number }>({}), c = ctx.agent<{ c: boolean }>({});

        const d = ctx.agent<{
            d: null;
        }>({});
        [b, c, d];
        TS);
    eq([], $out['diagnostics']);
    eq([0, 1, 2, 3], array_column($out['schemas'], 'ordinal'));
    $lines = array_column($out['schemas'], 'line');
    eq([2, 3, 3, 5], $lines);
    $sorted = $lines;
    sort($sorted);
    eq($sorted, $lines);
});
check('the schema string is byte-identical wherever the call sits', function () use ($wasm) {
    // The reason `line` is a SIBLING of `schema` and never a member of it:
    // downstream hashes the schema text verbatim. These literals are the bytes
    // the extractor produced before `line` existed — moving the call moves the
    // line and not one byte of the schema.
    $type = '{ verdicts: { id: string; judgment: "pass" | "fail" }[]; reviewed: boolean }';
    $pinned = '{"type":"object","properties":{"verdicts":{"type":"array","items":{"type":"object","properties":'
        . '{"id":{"type":"string"},"judgment":{"type":"string","enum":["pass","fail"]}},'
        . '"required":["id","judgment"],"additionalProperties":false}},"reviewed":{"type":"boolean"}},'
        . '"required":["verdicts","reviewed"],"additionalProperties":false}';

    $ts = guest($wasm);
    $near = $ts->analyze(SDK . "\nconst r = ctx.agent<{$type}>({});\nr;\n");
    $far = $ts->analyze(SDK . str_repeat("\n// pushed down\n", 20) . "\nconst r = ctx.agent<{$type}>({});\nr;\n");

    eq(2, $near['schemas'][0]['line']);
    eq(42, $far['schemas'][0]['line']);
    eq($pinned, $near['schemas'][0]['schema']);
    eq($pinned, $far['schemas'][0]['schema']);
    eq(0, strcmp($near['schemas'][0]['schema'], $far['schemas'][0]['schema']));
    // And no `line` leaked inside the JSON text itself.
    eq(false, str_contains($near['schemas'][0]['schema'], 'line'));
});

// A name is not an identity. Textual callee matching agreed with the author
// only when they wrote the call one particular way — and agreed with the WRONG
// declaration when a local shadowed the SDK. Both failures are silent: no
// schema, no diagnostic, a clean publish and a first-run failure. Matching now
// resolves each call through the checker, so these are properties of the
// DECLARATION the call lands on, not of how the callee happens to be spelled.
echo "\nevery spelling of the same call is the same call\n";
$spellings = [
    'parenthesised callee' => '(ctx.model)<{ a: string }>({})',
    'non-null assertion'   => 'ctx!.model<{ a: string }>({})',
    'element access'       => 'ctx["model"]<{ a: string }>({})',
    'optional chaining'    => 'ctx?.model<{ a: string }>({})',
    'aliased to a local'   => 'm<{ a: string }>({})',
];
foreach ($spellings as $label => $call) {
    check($label . ' bakes the same schema', function () use ($wasm, $call) {
        $out = guest($wasm)->analyze(SDK . "\nconst m = ctx.model;\nconst r = {$call};\n[m, r];\n");
        eq([], schemaErrors($out));
        eq(1, count($out['schemas']));
        eq(0, $out['schemas'][0]['ordinal']);
        eq('ctx.model', $out['schemas'][0]['callee']);
        eq(3, $out['schemas'][0]['line']);
        eq('{"type":"object","properties":{"a":{"type":"string"}},"required":["a"],"additionalProperties":false}',
            $out['schemas'][0]['schema']);
    });
}
check('all five spellings in one program, in source order, none missed', function () use ($wasm) {
    $out = guest($wasm)->analyze(SDK . <<<'TS'

        const m = ctx.model;
        const a = (ctx.model)<{ a: string }>({});
        const b = ctx!.model<{ b: string }>({});
        const c = ctx["model"]<{ c: string }>({});
        const d = m<{ d: string }>({});
        const e = ctx.model<{ e: string }>({});
        [a, b, c, d, e];
        TS);
    eq([], $out['diagnostics']);
    eq([0, 1, 2, 3, 4], array_column($out['schemas'], 'ordinal'));
    eq(array_fill(0, 5, 'ctx.model'), array_column($out['schemas'], 'callee'));
    eq([3, 4, 5, 6, 7], array_column($out['schemas'], 'line'));
    // Each one carries its OWN type argument — the match is per call, not a
    // blanket "the first one wins".
    eq(['a', 'b', 'c', 'd', 'e'], array_map(
        fn (array $s): string => array_key_first(json_decode($s['schema'], true)['properties']),
        $out['schemas']
    ));
});
check('a LOCALLY SHADOWED ctx.agent is not extracted, and does not shift ordinals', function () use ($wasm) {
    // The poisoning case: the local object is a different function that merely
    // shares the spelling. Textual matching gave it ordinal 0 and pushed the
    // real call to 1 — so a host keying baked schemas by ordinal wired the
    // wrong schema to the wrong call.
    $out = guest($wasm)->analyze(SDK . <<<'TS'

        function local(): { shadowed: string } {
            const ctx = { agent<T>(i: unknown): T { return i as T; } };
            return ctx.agent<{ shadowed: string }>({});
        }
        const real = ctx.agent<{ real: string }>({});
        [local, real];
        TS);
    eq([], $out['diagnostics']);
    eq(1, count($out['schemas']));
    eq(0, $out['schemas'][0]['ordinal']);          // the REAL call keeps ordinal 0
    eq(6, $out['schemas'][0]['line']);
    eq('{"type":"object","properties":{"real":{"type":"string"}},"required":["real"],"additionalProperties":false}',
        $out['schemas'][0]['schema']);
});
check('a matched call with the wrong arity of type arguments errors loudly', function () use ($wasm) {
    // Defensive: no sane SDK signature takes two, so this should be
    // unreachable — but "we matched your call and could not serialise it" must
    // never be spelled as silence, which is the whole lesson of this section.
    $out = guest($wasm)->analyze(
        "declare const ctx: { model<T, U>(input: unknown): T };\nconst a = ctx.model<{ a: string }, number>({});\na;\n"
    );
    eq([], $out['schemas']);
    $errors = schemaErrors($out);
    eq(1, count($errors));
    eq(0, $errors[0]['ordinal']);
    contains($errors[0]['message'], '2 type arguments');
});

echo "\nnumeric literal types JSON cannot carry\n";
// `1e309` is Infinity by the time the checker sees it, and JSON.stringify turns
// that into the text `null` — so this used to bake `{"const":null}`: a schema
// asserting something the author never wrote.
$rejects('a numeric literal beyond the double range', '1e309', '<type argument>', 'not a finite JSON number');
$rejects('the same, negative', '-1e400', '<type argument>', 'not a finite JSON number');
$rejects('...and inside a literal union', '{ n: 1e309 | 2 }', 'n', 'not a finite JSON number');
check('a finite literal at the edge is still fine', function () use ($wasm) {
    // The refusal is about Infinity, not about magnitude.
    $out = guest($wasm)->analyze(program('1e308'));
    eq([], schemaErrors($out));
    eq('{"const":1e+308}', $out['schemas'][0]['schema']);
});

echo "\nwhat is NOT extracted\n";
check('a matched callee without a type argument is untouched', function () use ($wasm) {
    // Schema-first authoring stays legal: the guest extracts, it does not police
    // which style a call uses.
    $out = guest($wasm)->analyze(SDK . "\nconst a = ctx.model({ outputSchema: { type: 'string' } });\na;\n");
    eq([], $out['diagnostics']);
    eq([], $out['schemas']);
});
check('an unlisted callee is untouched', function () use ($wasm) {
    $out = guest($wasm)->analyze(
        "declare const other: { model<T>(i: unknown): T };\nconst a = other.model<{ a: string }>({});\na;\n"
    );
    eq([], $out['schemas']);
});
check('a same-named local function is not the listed callee', function () use ($wasm) {
    $out = guest($wasm)->analyze(SDK . "\nfunction agent<T>(i: unknown): T { return i as T; }\nconst a = agent<{ a: string }>({});\na;\n");
    eq([], $out['schemas']);
});
check('without the option nothing is extracted at all', function () use ($wasm) {
    $plain = new Terrarium($wasm);
    $out = $plain->analyze(program('{ a: string }'));
    eq([], $out['schemas']);
    eq([], $out['diagnostics']);
});
check('without the option an inexpressible type argument is not a diagnostic', function () use ($wasm) {
    $plain = new Terrarium($wasm);
    eq([], $plain->analyze(program('Date'))['diagnostics']);
});
check('eval is unaffected: extraction is a static concern', function () use ($wasm) {
    $ts = guest($wasm);
    // `Date` has no schema form, but running the program was never in question.
    eq(3, $ts->eval(SDK . "\nconst n: number = 1 + 2;\nn\n"));
});

echo "\nthe legacy check() shape is untouched\n";
check('check() still returns a plain list of diagnostics, option or not', function () use ($wasm) {
    // Characterisation: this is what every existing host reads.
    $ts = guest($wasm);
    eq([], $ts->check(program('{ a: string }')));
    $diags = $ts->check(SDK . "\nconst n: string = 1;\n");
    eq(1, count($diags));
    eq([0], array_keys($diags));                    // a list, not a map
    eq(['message', 'type', 'line'], array_keys($diags[0]));
});
check('check() also reports the schema refusals when the option is on', function () use ($wasm) {
    $diags = guest($wasm)->check(program('Date'));
    eq(1, count($diags));
    eq('TSSchemaError', $diags[0]['type']);
});
check('analyze() diagnostics are exactly check() diagnostics', function () use ($wasm) {
    $ts = guest($wasm);
    $source = SDK . "\nconst n: string = 1;\nconst a = ctx.agent<Date>({});\na;\n";
    eq($ts->check($source), $ts->analyze($source)['diagnostics']);
});
check('sync-only and schema diagnostics coexist, host constraints first', function () use ($wasm) {
    $ts = new Terrarium($wasm, syncOnly: true, typeArgumentSchemas: ['ctx.agent']);
    $out = $ts->analyze(SDK . "\nasync function f() { return 1; }\nconst a = ctx.agent<Date>({});\n[f, a];\n");
    eq('TSSyncOnly', $out['diagnostics'][0]['type']);
    eq('TSSchemaError', $out['diagnostics'][count($out['diagnostics']) - 1]['type']);
});
check('the raw engine takes the option through setCompileOptions', function () use ($wasm) {
    $rt = new \Terrarium\Runtime(file_get_contents($wasm));
    eq([], $rt->analyze(program('{ a: string }'))['schemas']);
    $rt->setCompileOptions(['type_argument_schemas' => ['ctx.agent']]);
    eq(1, count($rt->analyze(program('{ a: string }'))['schemas']));
    $rt->setCompileOptions([]);
    eq([], $rt->analyze(program('{ a: string }'))['schemas']);
});
check('a callee that is not a dotted identifier chain is rejected up front', function () use ($wasm) {
    // Silently extracting nothing is the failure mode worth killing here.
    throws(\InvalidArgumentException::class, fn () => new Terrarium($wasm, typeArgumentSchemas: ['ctx.agent(']));
    throws(\InvalidArgumentException::class, fn () => new Terrarium($wasm, typeArgumentSchemas: ['']));
});
check('a guest without the analyze export says so', function () {
    $qjs = __DIR__ . '/../wasm/quickjs_guest.wasm';
    if (!is_file($qjs)) {
        return;
    }
    $rt = new \Terrarium\Runtime(file_get_contents($qjs));
    throws(\Terrarium\Exception::class, fn () => $rt->analyze('1 + 1'));
});

echo "\ndeterminism: the same source always yields the same bytes\n";
check('two runs on one instance agree', function () use ($wasm) {
    $ts = guest($wasm);
    $source = program('{ a: string; b: ("x" | "y")[]; c?: number | null }');
    eq($ts->analyze($source), $ts->analyze($source));
});
check('two independent instances agree', function () use ($wasm) {
    $source = program('{ a: string; b: ("x" | "y")[]; c?: number | null }');
    eq(guest($wasm)->analyze($source), guest($wasm)->analyze($source));
});
check('a fresh instance after reset() agrees', function () use ($wasm) {
    $ts = guest($wasm);
    $source = program('Derived');
    $first = $ts->analyze($source);
    $ts->reset();
    eq($first, $ts->analyze($source));
});
check('extraction is unaffected by an unrelated earlier program', function () use ($wasm) {
    // The compiler context persists across calls; a schema must not depend on
    // what was compiled before it.
    $ts = guest($wasm);
    $source = program('{ a: string; b: "p" | "q" }');
    $expected = $ts->analyze($source);
    $ts->analyze(SDK . "\ntype Other = { b: \"q\" | \"p\" };\nconst o = ctx.agent<Other>({});\no;\n");
    eq($expected, $ts->analyze($source));
});

summary();

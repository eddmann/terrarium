/*
 * Terrarium TypeScript guest driver. Runs inside the persistent "compiler"
 * QuickJS context, alongside the real TypeScript compiler (`globalThis.ts`,
 * loaded from bytecode), the bundled lib .d.ts map (`globalThis.LIBS`), and
 * ts-blank-space (`globalThis.tsBlankSpace`).
 *
 * Exposes one function, called from C per eval:
 *
 *   __terrariumCompile(source, sdkDts, options) -> { js: string }
 *                                                | { error: { message, type, line? } }
 *
 * `options` is the host's per-instance compile-options object (the reserved
 * "$opts" capability), or undefined for a host that sends none. It is an extra
 * trailing argument, so nothing breaks if it is absent.
 *
 * Behaviour:
 *  - Type-checks `source` against [libs, sdkDts] (strict). The SDK .d.ts is the
 *    host-generated declaration of the registered capabilities, so the type
 *    environment is exactly the capability environment.
 *  - A leading `// @ts-nocheck` comment (TypeScript's own pragma) skips the
 *    check; the source is still stripped and run.
 *  - Syntax the sandbox ENGINE cannot parse (`accessor` fields) is always
 *    rejected as `TSEngineUnsupported` — the checker would otherwise pass code
 *    that dies with a SyntaxError at eval (see CONSTRAINTS below).
 *  - With `options.sync_only`, asynchronous and generator syntax and every use
 *    of a promise are rejected outright — regardless of `@ts-nocheck`.
 *  - Types are erased with ts-blank-space (whitespace-preserving), so the
 *    returned JS is positionally identical to the input — runtime error line
 *    numbers stay exact. Non-erasable syntax (enum, namespace, ...) is a clear
 *    error rather than silent breakage.
 *
 * Lib SourceFiles are cached for the context's life; the previous Program is
 * reused when the SDK is unchanged, so repeat evals only re-parse the source.
 */
(function () {
    "use strict";

    var LIB_DIR = "/libs/";
    var libCache = Object.create(null); // lib filename -> SourceFile
    var lastProgram;
    var lastDts;

    // The compiler pin. The engine is quickjs-ng v0.16.2, which implements every
    // ES2021-ES2024 library addition this sandbox can reach; the audit behind
    // these two lines is guests/typescript/tools/lib-audit/ (probe corpus,
    // per-feature engine verdicts, and the checker's verdict at each pin), and
    // its findings are pinned as behaviour by tests/php/11_es_surface.php.
    var TARGET = ts.ScriptTarget.ES2024;
    var LIB_ROOT = "lib.es2024.d.ts";

    // Lib files the engine does NOT back, served empty rather than declared:
    // the type environment must equal the real execution environment, and a
    // declaration the engine cannot honour is checked-clean code that dies at
    // run time.
    //   *.intl.d.ts          quickjs-ng is built without Intl -- `typeof Intl`
    //                        is "undefined". Only the locale-blind
    //                        toLocaleString/localeCompare declared in lib.es5
    //                        actually exist.
    //   *.sharedmemory.d.ts  there is no Atomics global at all, and a growable
    //                        SharedArrayBuffer cannot be constructed ("growable
    //                        SharedArrayBuffer requires SAB allocator hooks"),
    //                        so ES2024's grow/growable/maxByteLength can never
    //                        succeed. Plain SharedArrayBuffer does exist, but
    //                        with no Atomics and no workers it is an ArrayBuffer
    //                        under a misleading name: dropped with the rest.
    // Emptying a file keeps the /// <reference lib=...> graph intact, so the
    // chain still resolves. lib.es5.d.ts's own `declare namespace Intl` is not
    // reachable at file granularity and stays declared -- a pre-existing gap
    // this pin neither widens nor closes.
    var LIB_EXCLUDED = /\.(intl|sharedmemory)\.d\.ts$/;

    function libText(short) {
        if (LIBS[short] === undefined) return undefined;
        return LIB_EXCLUDED.test(short) ? "" : LIBS[short];
    }

    // What the guest prelude actually provides at runtime beyond the language
    // itself. The type environment must equal the real execution environment.
    var RUNTIME_DTS =
        "declare const console: {\n" +
        "    log(...args: unknown[]): void;\n" +
        "    error(...args: unknown[]): void;\n" +
        "    warn(...args: unknown[]): void;\n" +
        "    info(...args: unknown[]): void;\n" +
        "    debug(...args: unknown[]): void;\n" +
        "};\n";

    function libName(fileName) {
        return fileName.indexOf(LIB_DIR) === 0 ? fileName.slice(LIB_DIR.length) : fileName;
    }

    // Build the Program over [libs, runtime+sdk .d.ts, source]. Shared by the
    // diagnostics pass and by the type-argument schema extraction below, which
    // needs the same Program's TypeChecker.
    function buildProgram(source, sdkDts) {
        var files = Object.create(null);
        files["/main.ts"] = source;
        files["/sdk.d.ts"] = RUNTIME_DTS + (sdkDts || "");

        var options = {
            target: TARGET,
            lib: [LIB_ROOT],
            strict: true,
            noEmit: true,
            types: [],
            skipLibCheck: true,
        };
        var host = {
            getSourceFile: function (name, lang) {
                if (files[name] !== undefined) {
                    return ts.createSourceFile(name, files[name], lang || TARGET, true);
                }
                var short = libName(name);
                var text = libText(short);
                if (text !== undefined) {
                    if (!libCache[short]) {
                        libCache[short] = ts.createSourceFile(name, text, TARGET, true);
                    }
                    return libCache[short];
                }
                return undefined;
            },
            getDefaultLibFileName: function () { return LIB_DIR + LIB_ROOT; },
            getDefaultLibLocation: function () { return LIB_DIR; },
            writeFile: function () {},
            getCurrentDirectory: function () { return "/"; },
            getCanonicalFileName: function (f) { return f; },
            useCaseSensitiveFileNames: function () { return true; },
            getNewLine: function () { return "\n"; },
            fileExists: function (f) { return files[f] !== undefined || LIBS[libName(f)] !== undefined; },
            readFile: function (f) { return files[f] !== undefined ? files[f] : libText(libName(f)); },
        };

        var program = ts.createProgram(
            ["/sdk.d.ts", "/main.ts"],
            options,
            host,
            sdkDts === lastDts ? lastProgram : undefined
        );
        lastProgram = program;
        lastDts = sdkDts;
        return program;
    }

    // Every error diagnostic of `program` as {message, type, line?}. Shared by
    // eval (the first error gates execution) and the check-only entrypoint
    // (the full list is the result).
    function programErrors(program) {
        var diags = ts.getPreEmitDiagnostics(program);
        var errors = [];
        for (var j = 0; j < diags.length; j++) {
            var d = diags[j];
            if (d.category !== ts.DiagnosticCategory.Error) continue;
            var out = {
                message: ts.flattenDiagnosticMessageText(d.messageText, " "),
                type: "TS" + d.code,
            };
            if (d.file && typeof d.start === "number") {
                var lc = d.file.getLineAndCharacterOfPosition(d.start);
                if (d.file.fileName === "/main.ts") {
                    out.line = lc.line + 1;
                } else {
                    out.message += " (in " + d.file.fileName + ")";
                }
            }
            errors.push(out);
        }
        return errors;
    }

    // ---------------------------------------------------------------------
    // CONSTRAINTS: what this environment cannot run, whatever the types say
    // ---------------------------------------------------------------------
    //
    // Two families of diagnostic share one walk, because both answer the same
    // question -- "can this program finish here?" -- and both must be reported
    // ahead of the type diagnostics, in source order:
    //
    //   TSEngineUnsupported  ALWAYS ON. Syntax the sandbox engine refuses at
    //                        parse time. The checker is happy with it and
    //                        ts-blank-space passes it straight through, so
    //                        without this it is checked-clean code that dies
    //                        with a SyntaxError at eval. An engine truth, not
    //                        a host preference: no option gates it.
    //
    //   TSSyncOnly           OPT-IN (`sync_only`). The guest never drains the
    //                        microtask/job queue: whatever an `await` suspends
    //                        on, a generator suspends into, or a promise
    //                        reaction waits for is simply never resumed. Left
    //                        alone that half-runs silently -- an async IIFE
    //                        returns a pending promise and its continuation is
    //                        dead code; `p.then(f)` on a promise that never
    //                        settles abandons `f` without a trace. When the
    //                        host sets `sync_only` that is rejected at compile
    //                        time instead, with a message that teaches the
    //                        synchronous shape rather than just naming the ban.
    //
    // Deliberate asymmetry with `@ts-nocheck`: the pragma opts out of the TYPE
    // check, which is an author's preference about their own annotations.
    // Neither of these is a preference -- one is what the engine can parse, the
    // other a capability the host does not have -- so the walk runs whether or
    // not the pragma is present. A guest that could not finish the program is
    // worse than one that refuses it.
    //
    // The walk takes a TypeChecker when there is one and works without it. On
    // the `@ts-nocheck` compile path no Program is built at all, so the promise
    // rules fall back to conservative SYNTAX matching (see below); everything
    // else needs syntax only either way.
    var SYNC_ONLY_TYPE = "TSSyncOnly";
    var ENGINE_UNSUPPORTED_TYPE = "TSEngineUnsupported";

    var SYNC_ONLY_MESSAGES = {
        async:
            "`async` functions are not supported: this environment is synchronous and never runs " +
            "continuations, so everything after the first `await` would silently never execute. " +
            "Write a plain function -- SDK calls return their values directly.",
        await:
            "`await` is not supported: this environment is synchronous; SDK calls return values " +
            "directly -- remove `await` and use the returned value.",
        forAwait:
            "`for await` is not supported: this environment is synchronous; iterate the returned " +
            "array with a plain `for ... of`.",
        generator:
            "generator functions (`function*`) are not supported: this environment runs a program " +
            "to completion in one go and never resumes a suspended one. Build an array and return it.",
        yield:
            "`yield` is not supported: this environment runs a program to completion in one go and " +
            "never resumes a suspended one. Collect the values into an array and return it.",
        promise:
            "`Promise` cannot be used here: promises cannot settle in a synchronous guest -- the job " +
            "queue is never drained, so a promise stays pending forever and every reaction registered " +
            "on it is abandoned in silence. Use the value directly -- SDK calls return theirs " +
            "synchronously.",
        promiseThen:
            "`.then` / `.catch` / `.finally` cannot run here: promises cannot settle in a synchronous " +
            "guest -- the job queue is never drained, so the callback you register is dead code that " +
            "fails silently. Use the value directly -- SDK calls return theirs synchronously.",
    };

    // The value-carrying expression forms whose TYPE is worth asking about.
    // Deliberately a list rather than "every expression": the walk asks the
    // checker once per node it names, and these are the shapes a promise can
    // actually arrive in.
    var TYPED_EXPRESSION_KINDS = [
        ts.SyntaxKind.CallExpression,
        ts.SyntaxKind.NewExpression,
        ts.SyntaxKind.Identifier,
        ts.SyntaxKind.PropertyAccessExpression,
        ts.SyntaxKind.ElementAccessExpression,
        ts.SyntaxKind.ParenthesizedExpression,
        ts.SyntaxKind.NonNullExpression,
        ts.SyntaxKind.AsExpression,
        ts.SyntaxKind.SatisfiesExpression,
        ts.SyntaxKind.ConditionalExpression,
        ts.SyntaxKind.TaggedTemplateExpression,
    ];

    function isTypedExpressionKind(kind) {
        return TYPED_EXPRESSION_KINDS.indexOf(kind) !== -1;
    }

    // An identifier that NAMES something (a declaration, a property, a member)
    // rather than referring to a value. Skipped so `const p = f()` reports the
    // call once instead of reporting the binding as well.
    function isNamePosition(node) {
        var p = node.parent;
        if (!p) return false;
        return p.name === node || p.propertyName === node;
    }

    // The global `Promise` / `PromiseLike`, identified by DECLARATION and never
    // by name: a user's own `class Promise` shadows the global and is a
    // different thing entirely, which is exactly the distinction the checker
    // exists to make.
    function isGlobalPromiseSymbol(sym) {
        if (!sym) return false;
        var name = sym.getName();
        if (name !== "Promise" && name !== "PromiseLike") return false;
        var decls = sym.getDeclarations() || [];
        if (decls.length === 0) return false;
        for (var i = 0; i < decls.length; i++) {
            if (!isLibFile(decls[i].getSourceFile().fileName)) return false;
        }
        return true;
    }

    // Promise-like: the global `Promise`/`PromiseLike`, or a type whose `then`
    // member RETURNS one of those -- a host SDK's own thenable, which `await`
    // would consume exactly as it consumes a promise.
    //
    // The return type is what makes this precise rather than merely broad. "Has
    // a callable `then`" alone would refuse an ordinary object with a
    // `then(cb)` method of its own, which in a synchronous guest simply calls
    // `cb` and works perfectly; `PromiseLike.then` is defined to hand back
    // another `PromiseLike`, so requiring that keeps the rule on the things that
    // genuinely cannot settle. One level of recursion is all it takes, because
    // the named check terminates it.
    function isPromiseLikeType(checker, type, depth) {
        if (!type) return false;
        if (type.isUnion && type.isUnion()) {
            for (var i = 0; i < type.types.length; i++) {
                if (isPromiseLikeType(checker, type.types[i], depth)) return true;
            }
            return false;
        }
        if (isGlobalPromiseSymbol(type.getSymbol && type.getSymbol())) return true;
        if (depth > 0) return false;
        var then = checker.getPropertyOfType(type, "then");
        if (!then) return false;
        var sigs = checker.getSignaturesOfType(checker.getTypeOfSymbol(then), ts.SignatureKind.Call);
        for (var s = 0; s < sigs.length; s++) {
            if (isPromiseLikeType(checker, checker.getReturnTypeOfSignature(sigs[s]), depth + 1)) return true;
        }
        return false;
    }

    function isPromiseLikeAt(checker, node) {
        return isPromiseLikeType(checker, checker.getTypeAtLocation(node), 0);
    }

    // `x.then(...)` / `.catch(...)` / `.finally(...)`, the three ways a reaction
    // is registered. Returns the member name, or undefined.
    var REACTION_MEMBERS = ["then", "catch", "finally"];

    function reactionCallName(node) {
        if (!ts.isCallExpression(node)) return undefined;
        var callee = node.expression;
        if (!ts.isPropertyAccessExpression(callee) || !ts.isIdentifier(callee.name)) return undefined;
        return REACTION_MEMBERS.indexOf(callee.name.text) !== -1 ? callee.name.text : undefined;
    }

    // Syntax the ENGINE cannot parse, however well-typed it is.
    //
    // TO EXTEND: add a case here and a probe to tests/php/11_es_surface.php
    // proving the engine really rejects it; TO RETIRE one when a quickjs-ng bump
    // implements it, delete the case and flip that probe from "absent" to
    // "implemented". The audit in guests/typescript/tools/lib-audit/ (with
    // `--parity`) is what catches a case that should have been here.
    //
    //   accessor  `class C { accessor x = 1 }` -- an ES2022 decorator-adjacent
    //             field. quickjs-ng v0.16.2 raises SyntaxError on the keyword,
    //             and ts-blank-space emits it verbatim (it is not a type).
    function engineUnsupportedAt(node, report) {
        var mods = ts.canHaveModifiers(node) ? ts.getModifiers(node) : undefined;
        if (!mods) return;
        for (var i = 0; i < mods.length; i++) {
            if (mods[i].kind === ts.SyntaxKind.AccessorKeyword) {
                report(
                    mods[i].getStart(),
                    ENGINE_UNSUPPORTED_TYPE,
                    "`accessor` class members are not supported: the sandbox engine (quickjs-ng " +
                        "v0.16.2) raises a SyntaxError on the `accessor` keyword, so this would " +
                        "type-check and then fail to parse at run time. Declare a private field with " +
                        "an explicit `get`/`set` pair instead."
                );
            }
        }
    }

    // Every constraint violation in `file`, as {message, type, line}, in source
    // order. `checker` may be undefined (the `@ts-nocheck` compile path), in
    // which case the promise rules degrade to the conservative syntax form.
    // `syncOnly` gates the TSSyncOnly family only.
    //
    // ## The conservative syntax fallback, and why it is acceptable
    //
    // Without a Program there is no way to ask what a type is, so the fallback
    // matches on shape alone: `new Promise`, any identifier spelled `Promise`,
    // and any CALL through a member named `then`/`catch`/`finally`. The last
    // one over-matches -- an object of the author's own with a `.then(cb)`
    // method is refused even though nothing asynchronous is happening. That is
    // a deliberate trade in an OPT-IN mode whose whole purpose is to refuse
    // work that cannot complete: `sync_only` + `@ts-nocheck` is a caller who
    // has asked for the strict environment and then declined the type
    // information that would make the check precise. Dropping `@ts-nocheck`
    // (or renaming the method) restores exact, checker-backed matching.
    function constraintErrors(file, checker, syncOnly) {
        var found = [];

        function report(pos, type, message) {
            found.push({ pos: pos, type: type, message: message });
        }

        // The sync-only family is gated here rather than around the walk, so a
        // single traversal serves both families and the output stays in source
        // order however they interleave.
        function sync(pos, kind) {
            if (!syncOnly) return;
            report(pos, SYNC_ONLY_TYPE, SYNC_ONLY_MESSAGES[kind]);
        }

        // Returns true when this node was reported AS a promise, so the subtree
        // below it is not reported again -- one diagnostic per promise
        // expression, at its outermost point.
        function promiseAt(node) {
            if (!syncOnly) return false;
            var reaction = reactionCallName(node);
            if (checker) {
                if (reaction !== undefined && isPromiseLikeAt(checker, node.expression.expression)) {
                    sync(node.getStart(file), "promiseThen");
                    return true;
                }
                if (
                    ts.isNewExpression(node) &&
                    ts.isIdentifier(node.expression) &&
                    isGlobalPromiseSymbol(checker.getSymbolAtLocation(node.expression))
                ) {
                    sync(node.getStart(file), "promise");
                    return true;
                }
                if (isTypedExpressionKind(node.kind) && !isNamePosition(node) && isPromiseLikeAt(checker, node)) {
                    sync(node.getStart(file), "promise");
                    return true;
                }
                if (
                    ts.isIdentifier(node) &&
                    !isNamePosition(node) &&
                    isGlobalPromiseSymbol(checker.getSymbolAtLocation(node))
                ) {
                    sync(node.getStart(file), "promise");
                    return true;
                }
                return false;
            }
            // No Program: shape only.
            if (reaction !== undefined) {
                sync(node.getStart(file), "promiseThen");
                return true;
            }
            if (ts.isIdentifier(node) && node.text === "Promise" && !isNamePosition(node)) {
                sync(node.getStart(file), "promise");
                return true;
            }
            return false;
        }

        // `promiseHushed` suppresses nested promise reports only: the async /
        // generator / engine rules keep running through the whole tree, so a
        // `p.then(async () => …)` still reports the `async` as well.
        function visit(node, promiseHushed) {
            var kind = node.kind;
            if (kind === ts.SyntaxKind.AwaitExpression) {
                sync(node.getStart(file), "await");
            } else if (kind === ts.SyntaxKind.YieldExpression) {
                sync(node.getStart(file), "yield");
            } else if (kind === ts.SyntaxKind.ForOfStatement && node.awaitModifier) {
                sync(node.getStart(file), "forAwait");
            }
            // `function*` / `*method()` in every form that can carry the token.
            if (
                node.asteriskToken &&
                (kind === ts.SyntaxKind.FunctionDeclaration ||
                    kind === ts.SyntaxKind.FunctionExpression ||
                    kind === ts.SyntaxKind.MethodDeclaration)
            ) {
                sync(node.asteriskToken.getStart(file), "generator");
            }
            // The `async` modifier on any function form: declaration,
            // expression, arrow, class method, object-literal method.
            var mods = ts.canHaveModifiers(node) ? ts.getModifiers(node) : undefined;
            if (mods) {
                for (var i = 0; i < mods.length; i++) {
                    if (mods[i].kind === ts.SyntaxKind.AsyncKeyword) {
                        sync(mods[i].getStart(file), "async");
                    }
                }
            }

            engineUnsupportedAt(node, report);

            var hushed = promiseHushed;
            if (!hushed && promiseAt(node)) hushed = true;

            ts.forEachChild(node, function (child) { visit(child, hushed); });
        }

        ts.forEachChild(file, function (child) { visit(child, false); });

        found.sort(function (a, b) { return a.pos - b.pos; });
        var errors = [];
        for (var j = 0; j < found.length; j++) {
            errors.push({
                message: found[j].message,
                type: found[j].type,
                line: file.getLineAndCharacterOfPosition(found[j].pos).line + 1,
            });
        }
        return errors;
    }

    function syncOnlyRequested(options) {
        return !!(options && options.sync_only);
    }
    // ---------------------------------------------------------------------
    // TYPE_ARGUMENT_SCHEMAS: the author writes the type, the host gets the schema
    // ---------------------------------------------------------------------
    //
    // With `type_argument_schemas: ["ctx.model", "ctx.agent"]` the guest walks
    // the source for calls to those callees carrying exactly one type argument,
    // resolves that type argument with the checker, and serialises it to JSON
    // Schema. The host reads the results from `analyze()`; nothing about `eval`
    // changes.
    //
    // The emitted subset is deliberately narrow -- exactly what a type-level
    // schema interpreter on the host side can read back:
    //
    //   object  {"type":"object","properties":{...},"required":[...],
    //            "additionalProperties":false}   -- `?` members omitted from required
    //   array   {"type":"array","items":<schema>}
    //   scalars {"type":"string"|"number"|"boolean"|"null"}
    //   literal {"const":<value>}      union of literals {"type":T,"enum":[...]}
    //   x|null  {"type":["string","null"]}       -- primitives only
    //
    // Everything else is REFUSED with a diagnostic naming the offending member
    // path, because a schema that the host cannot turn back into the author's
    // type is worse than no schema: it silently re-types the result. The
    // refusals are functions, any/unknown/never/undefined, symbols, bigint,
    // enums, Date and other lib/class instances, Promises, index signatures,
    // tuples, unresolved generics, non-plain intersections, object|null, and
    // recursion.
    //
    // Determinism is a hard requirement -- a host may store or hash what comes
    // back, so the same source must always yield the same bytes. The JSON is
    // therefore emitted as text by hand, not JSON.stringify'd: property order
    // then follows declaration order exactly, and integer-like keys cannot be
    // reshuffled by the engine's own property ordering.
    var SCHEMA_ERROR_TYPE = "TSSchemaError";

    // A refusal carrying the member path it happened at. Thrown through the
    // recursion and caught once per call site.
    function SchemaError(path, reason) {
        this.path = path;
        this.reason = reason;
    }

    function fail(path, reason) {
        throw new SchemaError(path, reason);
    }

    // The member path as the author reads it: "" is the type argument itself.
    function pathLabel(path) {
        return path === "" ? "<type argument>" : path;
    }

    function memberPath(path, name) {
        return path === "" ? name : path + "." + name;
    }

    function itemPath(path) {
        return path + "[]";
    }

    // Scalars, verbatim: the only place engine formatting could creep in. All
    // reachable values are JSON literals from the source text.
    function jsonScalar(value) {
        if (value === null) return "null";
        if (typeof value === "boolean") return value ? "true" : "false";
        if (typeof value === "number") return JSON.stringify(value);
        return JSON.stringify(String(value));
    }

    function isLibFile(fileName) {
        if (fileName.indexOf(LIB_DIR) === 0) return true;
        var base = fileName.slice(fileName.lastIndexOf("/") + 1);
        return base.indexOf("lib.") === 0 && base.lastIndexOf(".d.ts") === base.length - 5;
    }

    // The literal *value* of a literal type, or undefined for anything else.
    function literalValue(checker, type) {
        var f = type.flags;
        if (f & ts.TypeFlags.EnumLike) return undefined;      // TS enums are erased, never literals here
        if (f & ts.TypeFlags.StringLiteral) return type.value;
        if (f & ts.TypeFlags.NumberLiteral) return type.value;
        if (f & ts.TypeFlags.BooleanLiteral) return type.intrinsicName === "true";
        return undefined;
    }

    function literalTypeName(value) {
        if (typeof value === "string") return "string";
        if (typeof value === "number") return "number";
        return "boolean";
    }

    function scalarSchema(name) {
        return { kind: name, json: '{"type":"' + name + '"}' };
    }

    // A named result: `kind` drives the nullable spelling (only primitives can
    // become `type: [x, "null"]`), `json` is the canonical text.
    function schemaOf(kind, json) {
        return { kind: kind, json: json };
    }

    // Refuse anything that is not a plain data object. Used for the members of
    // an intersection, which has no symbol of its own to interrogate.
    function assertPlainObject(checker, type, path, what) {
        if (!(type.flags & ts.TypeFlags.Object)) {
            fail(path, what + " is not an object type, so the intersection does not reduce to one");
        }
        if (checker.isTupleType(type) || checker.isArrayType(type)) {
            fail(path, what + " is an array type, so the intersection does not reduce to a plain object");
        }
        if (checker.getSignaturesOfType(type, ts.SignatureKind.Call).length > 0 ||
            checker.getSignaturesOfType(type, ts.SignatureKind.Construct).length > 0) {
            fail(path, what + " is callable, so the intersection does not reduce to a plain object");
        }
        assertNotForeignObject(checker, type, path);
    }

    // Class instances and library types (Date, Map, Promise, ...) carry
    // behaviour, not data: their JSON form is a lossy convention, never the
    // type. Refuse rather than invent one.
    function assertNotForeignObject(checker, type, path) {
        var sym = type.getSymbol();
        if (!sym) return;
        var name = sym.getName();
        if (sym.flags & ts.SymbolFlags.Class) {
            fail(path, "class instances (`" + name + "`) cannot be expressed as JSON Schema: " +
                "use a plain object type or interface describing the data");
        }
        var decls = sym.getDeclarations() || [];
        for (var i = 0; i < decls.length; i++) {
            // Only *named* library types are refused on sight. An anonymous
            // type literal or mapped type that happens to be declared in a lib
            // file is what `Partial<T>`, `Pick<T, K>` and `Record<K, V>` expand
            // to -- those are judged by their structure like any other object,
            // so `Partial<{a: string}>` works and `Record<string, number>` is
            // refused for its index signature, which is the true reason.
            if (!ts.isInterfaceDeclaration(decls[i]) && !ts.isClassDeclaration(decls[i])) continue;
            if (!isLibFile(decls[i].getSourceFile().fileName)) continue;
            if (name === "Promise") {
                fail(path, "`Promise` cannot be expressed as JSON Schema: this environment is " +
                    "synchronous, so describe the resolved value directly");
            }
            fail(path, "the built-in type `" + name + "` cannot be expressed as JSON Schema " +
                "(Date, Map, Set, RegExp and friends have no JSON Schema form): " +
                "use a plain object type, or a string for a serialised value");
        }
    }

    // The object body: declaration order for properties, non-optional keys in
    // `required`, closed to extras.
    function objectSchema(checker, type, path, stack) {
        if (checker.getIndexInfosOfType(type).length > 0) {
            fail(path, "index signatures cannot be expressed as JSON Schema: " +
                "list the known properties, since the schema must name every key");
        }
        var props = checker.getPropertiesOfType(type);
        var fields = [];
        var required = [];
        for (var i = 0; i < props.length; i++) {
            var sym = props[i];
            var name = sym.getName();
            if (name.indexOf("__@") === 0) {
                fail(memberPath(path, name), "symbol-keyed properties cannot be expressed as JSON Schema");
            }
            var optional = !!(sym.flags & ts.SymbolFlags.Optional);
            var child = memberPath(path, name);
            var member = schemaFromType(checker, checker.getTypeOfSymbol(sym), child, optional, stack);
            fields.push(JSON.stringify(name) + ":" + member.json);
            if (!optional) required.push(JSON.stringify(name));
        }
        return schemaOf(
            "object",
            '{"type":"object","properties":{' + fields.join(",") + '},"required":[' +
                required.join(",") + '],"additionalProperties":false}'
        );
    }

    // Unions: nullable primitives and literal enums are expressible; nothing
    // else is. `allowUndefined` is set only for an optional (`?`) member, whose
    // type the checker widens with `undefined`.
    function unionSchema(checker, type, path, allowUndefined, stack) {
        var parts = type.types;
        var hasNull = false;
        var hasUndefined = false;
        var boolLiterals = [];
        var rest = [];
        for (var i = 0; i < parts.length; i++) {
            var p = parts[i];
            if (p.flags & ts.TypeFlags.Null) hasNull = true;
            else if (p.flags & (ts.TypeFlags.Undefined | ts.TypeFlags.Void)) hasUndefined = true;
            else if (p.flags & ts.TypeFlags.BooleanLiteral) boolLiterals.push(p);
            else rest.push(p);
        }
        if (hasUndefined && !allowUndefined) {
            fail(path, "`undefined` in a union cannot be expressed as JSON Schema: " +
                "mark the property optional with `?` instead");
        }

        // `true | false` is the boolean type in disguise; a lone one is a literal.
        var boolAtom = boolLiterals.length === 2;
        if (!boolAtom) rest = rest.concat(boolLiterals);

        if (rest.length === 0 && !boolAtom) {
            if (hasNull) return schemaOf("null", '{"type":"null"}');
            fail(path, "this union has no members that can be expressed as JSON Schema");
        }

        // All-literal (plus optional null) -> const / enum.
        if (!boolAtom) {
            var values = [];
            var names = [];
            var allLiteral = true;
            for (var j = 0; j < rest.length; j++) {
                var v = literalValue(checker, rest[j]);
                if (v === undefined) { allLiteral = false; break; }
                values.push(jsonScalar(v));
                names.push(literalTypeName(v));
            }
            if (allLiteral) {
                var uniform = names[0];
                for (var k = 1; k < names.length; k++) if (names[k] !== uniform) uniform = null;
                if (values.length === 1 && !hasNull) {
                    return schemaOf("const", '{"const":' + values[0] + "}");
                }
                if (hasNull) values.push("null");
                var prefix = "";
                if (uniform) {
                    prefix = hasNull
                        ? '"type":["' + uniform + '","null"],'
                        : '"type":"' + uniform + '",';
                }
                // A mixed-literal union keeps `enum` alone: every value is still
                // a JSON literal, there is simply no single `type` for them.
                return schemaOf("enum", "{" + prefix + '"enum":[' + values.join(",") + "]}");
            }
        }

        var atoms = rest.length + (boolAtom ? 1 : 0);
        if (atoms === 1) {
            var inner = boolAtom ? scalarSchema("boolean") : schemaFromType(checker, rest[0], path, false, stack);
            if (!hasNull) return inner;
            if (inner.kind === "string" || inner.kind === "number" || inner.kind === "boolean") {
                return schemaOf(inner.kind, '{"type":["' + inner.kind + '","null"]}');
            }
            fail(path, "`" + checker.typeToString(type) + "` cannot be expressed as JSON Schema: " +
                "the nullable spelling `type: [..., \"null\"]` carries primitives only, and " +
                "`anyOf` is not part of the readable subset -- make the member optional with `?`, " +
                "or model the empty case explicitly");
        }
        fail(path, "`" + checker.typeToString(type) + "` cannot be expressed as JSON Schema: " +
            "only nullable primitives and unions of literals are expressible");
    }

    // The dispatch. `stack` carries the types currently being serialised (cycle
    // detection) with the path each was entered at, so a recursive type is
    // refused by naming the cycle instead of looping forever.
    function schemaFromType(checker, type, path, allowUndefined, stack) {
        for (var s = 0; s < stack.length; s++) {
            if (stack[s].type === type) {
                fail(path, "recursive types cannot be expressed as JSON Schema: `" +
                    checker.typeToString(type) + "` at " + pathLabel(stack[s].path) +
                    " reappears at " + pathLabel(path));
            }
        }

        var F = ts.TypeFlags;
        var f = type.flags;

        if (f & F.Any) {
            if (type.intrinsicName === "error") {
                fail(path, "the type does not resolve (it is an error type), so no JSON Schema can be derived");
            }
            fail(path, "`any` cannot be expressed as JSON Schema: describe the actual shape");
        }
        if (f & F.Unknown) {
            fail(path, "`unknown` cannot be expressed as JSON Schema: describe the actual shape");
        }
        if (f & F.Never) fail(path, "`never` cannot be expressed as JSON Schema");
        if (f & (F.Undefined | F.Void)) {
            fail(path, "`" + checker.typeToString(type) + "` cannot be expressed as JSON Schema: " +
                "an absent value is spelled by leaving the property out of `required` (mark it `?`)");
        }
        if (f & F.BigIntLike) {
            fail(path, "`bigint` cannot be expressed as JSON Schema: JSON has one number type -- " +
                "use `number`, or `string` when the value must not lose precision");
        }
        if (f & F.ESSymbolLike) fail(path, "symbols cannot be expressed as JSON Schema");
        if (f & F.EnumLike) {
            fail(path, "TypeScript `enum` types cannot be expressed as JSON Schema: they are erased " +
                "here, so nothing survives to name -- use a union of literals instead");
        }
        if (f & F.TypeParameter) {
            fail(path, "the unresolved type parameter `" + checker.typeToString(type) + "` cannot be " +
                "expressed as JSON Schema: pass a concrete type argument");
        }
        if (f & F.NonPrimitive) {
            fail(path, "`object` cannot be expressed as JSON Schema: describe the actual properties");
        }
        if (f & (F.TemplateLiteral | F.StringMapping)) {
            fail(path, "template literal types cannot be expressed as JSON Schema: use `string`, " +
                "or a union of the literal values");
        }

        if (f & F.Null) return schemaOf("null", '{"type":"null"}');
        // The `boolean` type is itself a union of `true | false`; it must be
        // recognised before the union branch or it would surface as an enum.
        if (f & F.Boolean) return scalarSchema("boolean");
        if (f & (F.StringLiteral | F.NumberLiteral | F.BooleanLiteral)) {
            return schemaOf("const", '{"const":' + jsonScalar(literalValue(checker, type)) + "}");
        }
        if (f & F.String) return scalarSchema("string");
        // JSON Schema's `integer` is a *narrower* claim than TypeScript's
        // `number`, so never guess it: `number` stays `number`.
        if (f & F.Number) return scalarSchema("number");

        if (type.isUnion()) return unionSchema(checker, type, path, allowUndefined, stack);

        stack.push({ type: type, path: path });
        var out;
        if (type.isIntersection()) {
            var members = type.types;
            for (var m = 0; m < members.length; m++) {
                assertPlainObject(checker, members[m], path, "`" + checker.typeToString(members[m]) + "`");
            }
            out = objectSchema(checker, type, path, stack);
        } else if (f & F.Object) {
            if (checker.isTupleType(type)) {
                fail(path, "tuple types cannot be expressed as JSON Schema: use an array of a single " +
                    "element type, or an object with named members");
            }
            if (checker.isArrayType(type)) {
                var args = checker.getTypeArguments(type);
                var item = schemaFromType(checker, args[0], itemPath(path), false, stack);
                out = schemaOf("array", '{"type":"array","items":' + item.json + "}");
            } else if (checker.getSignaturesOfType(type, ts.SignatureKind.Call).length > 0) {
                fail(path, "function types cannot be expressed as JSON Schema");
            } else if (checker.getSignaturesOfType(type, ts.SignatureKind.Construct).length > 0) {
                fail(path, "constructor types cannot be expressed as JSON Schema");
            } else {
                assertNotForeignObject(checker, type, path);
                out = objectSchema(checker, type, path, stack);
            }
        } else {
            fail(path, "`" + checker.typeToString(type) + "` cannot be expressed as JSON Schema");
        }
        stack.pop();
        return out;
    }

    // The dotted name of a callee, structurally: `ctx.agent` from
    // `ctx.agent<T>(...)` however it is spaced, wrapped, or commented. Matching
    // on the AST rather than on `getText()` is what keeps a reformat inert.
    // Anything that is not a plain identifier chain (element access, a call in
    // the middle, `this`) has no dotted name and never matches.
    function calleeName(expr) {
        if (ts.isIdentifier(expr)) return expr.text;
        if (ts.isPropertyAccessExpression(expr) && ts.isIdentifier(expr.name)) {
            var left = calleeName(expr.expression);
            return left === undefined ? undefined : left + "." + expr.name.text;
        }
        return undefined;
    }

    // Every matched call in SOURCE ORDER: callee in `callees`, exactly one type
    // argument. Sorted by start position rather than by visit order so the
    // sequence is a property of the text, not of the traversal.
    function matchedCalls(file, callees) {
        var found = [];
        function visit(node) {
            if (ts.isCallExpression(node) && node.typeArguments && node.typeArguments.length === 1) {
                var name = calleeName(node.expression);
                if (name !== undefined && callees.indexOf(name) !== -1) {
                    found.push({ node: node, callee: name, pos: node.getStart(file) });
                }
            }
            ts.forEachChild(node, visit);
        }
        ts.forEachChild(file, visit);
        found.sort(function (a, b) { return a.pos - b.pos; });
        return found;
    }

    // The requested callees, or null when the host asked for no extraction.
    function schemaCallees(options) {
        var list = options && options.type_argument_schemas;
        if (!list || typeof list.length !== "number" || list.length === 0) return null;
        var out = [];
        for (var i = 0; i < list.length; i++) {
            if (typeof list[i] === "string" && list[i] !== "") out.push(list[i]);
        }
        return out.length > 0 ? out : null;
    }

    // Extract every matched call's schema. Returns {schemas, errors}.
    //
    // ORDINAL, not line:col. The identity of a call site has to survive
    // reformatting -- a prettier run, a renamed variable, an added comment must
    // not repoint a baked schema -- so a matched call is identified by its
    // 0-based index among matched calls in source order. An inexpressible type
    // argument still CONSUMES its ordinal (it yields a diagnostic instead of a
    // schema) so that one bad call cannot renumber the ones after it.
    //
    // LINE, carried alongside it, is not a second identity: it is a runtime
    // bridge. A consumer whose compiled artifact is immutable per version keys
    // the baked schemas by line, because the code running inside the guest knows
    // only its own line -- the ordinal->schema pairing is done at publish time,
    // when the source and this extraction are both in hand. It is the 1-based
    // line of the CALL's start (`getStart()`), the same convention diagnostics
    // use, so a TSSchemaError and the entry it displaced name the same line.
    // Entries stay sorted by start position, so `line` is non-decreasing across
    // them; two matched calls on one line simply share it, and what to do about
    // that is the consumer's policy, not the extractor's.
    function extractSchemas(program, callees) {
        var file = program.getSourceFile("/main.ts");
        if (!file) return { schemas: [], errors: [] };
        var checker = program.getTypeChecker();
        var calls = matchedCalls(file, callees);
        var schemas = [];
        var errors = [];
        for (var i = 0; i < calls.length; i++) {
            var call = calls[i];
            var line = file.getLineAndCharacterOfPosition(call.pos).line + 1;
            try {
                var type = checker.getTypeFromTypeNode(call.node.typeArguments[0]);
                var schema = schemaFromType(checker, type, "", false, []);
                // `line` is a SIBLING of `schema`, never a member of it: the
                // schema text is hashed verbatim downstream, so not one of its
                // bytes may move because a call did.
                schemas.push({ ordinal: i, callee: call.callee, line: line, schema: schema.json });
            } catch (e) {
                if (!(e instanceof SchemaError)) throw e;
                errors.push({
                    message: "cannot derive a JSON Schema from the type argument of `" + call.callee +
                        "` (call #" + i + "): " + pathLabel(e.path) + ": " + e.reason,
                    type: SCHEMA_ERROR_TYPE,
                    line: line,
                    ordinal: i,
                });
            }
        }
        return { schemas: schemas, errors: errors };
    }

    // eval reports one error (execution is gated on the first) but says how
    // many more there are; `check()` is where the full list lives.
    function firstOf(errors) {
        var first = errors[0];
        var out = { message: first.message, type: first.type };
        if (typeof first.line === "number") out.line = first.line;
        if (errors.length > 1) {
            out.message += " [+" + (errors.length - 1) + " more error" + (errors.length > 2 ? "s" : "") + "]";
        }
        return out;
    }

    // The static pass behind both check-only entrypoints: every diagnostic, plus
    // whatever the host asked to be extracted. Nothing is executed.
    //
    // An explicit check ignores @ts-nocheck -- you asked for the diagnostics.
    // tsc itself honours the pragma inside the checker, so blank it out of the
    // leading comments first (same-length replacement: positions are preserved,
    // so lines and call ordinals are unaffected).
    function analyzeSource(source, sdkDts, options) {
        var ranges = ts.getLeadingCommentRanges(source, 0) || [];
        for (var i = 0; i < ranges.length; i++) {
            var idx;
            while ((idx = source.indexOf("@ts-nocheck", ranges[i].pos)) !== -1 && idx < ranges[i].end) {
                source = source.slice(0, idx) + "           " + source.slice(idx + 11);
            }
        }

        var program = buildProgram(source, sdkDts);
        // Environment constraints first, and every occurrence of them: they are
        // the reason the program cannot run at all. The static path always has a
        // Program, so the promise rules are always the checker-backed ones.
        var errors = constraintErrors(
            program.getSourceFile("/main.ts"),
            program.getTypeChecker(),
            syncOnlyRequested(options)
        );
        errors = errors.concat(programErrors(program));

        // Extraction diagnostics come last: they are downstream of the type
        // errors, which usually explain them.
        var callees = schemaCallees(options);
        if (!callees) return { diagnostics: errors, schemas: [] };
        var extracted = extractSchemas(program, callees);
        return { diagnostics: errors.concat(extracted.errors), schemas: extracted.schemas };
    }

    // Check-only entrypoint: the diagnostics array, unchanged since the first
    // release. Schemas are the analyze entrypoint's business -- a host that
    // knows nothing of them still gets exactly the shape it always got.
    globalThis.__terrariumCheck = function (source, sdkDts, options) {
        return analyzeSource(source, sdkDts, options).diagnostics;
    };

    // Analyze entrypoint: the same diagnostics plus the extracted type-argument
    // schemas, as {diagnostics, schemas}. With no `type_argument_schemas`
    // option it is `check()` with an empty schema list.
    globalThis.__terrariumAnalyze = function (source, sdkDts, options) {
        var out = analyzeSource(source, sdkDts, options);
        return { diagnostics: out.diagnostics, schemas: out.schemas };
    };

    globalThis.__terrariumCompile = function (source, sdkDts, options) {
        // TypeScript's own opt-out pragma, honoured only in leading comments.
        var noCheck = false;
        var ranges = ts.getLeadingCommentRanges(source, 0) || [];
        for (var i = 0; i < ranges.length; i++) {
            if (source.slice(ranges[i].pos, ranges[i].end).indexOf("@ts-nocheck") !== -1) {
                noCheck = true;
                break;
            }
        }

        // Environment constraints are not author preferences: they are enforced
        // ahead of the type check and *through* @ts-nocheck. With the pragma no
        // Program is built at all, so the walk runs over a bare parse and the
        // promise rules take their conservative syntax form (see
        // constraintErrors); without it the checker backs them exactly.
        var errors;
        if (noCheck) {
            errors = constraintErrors(
                ts.createSourceFile("/main.ts", source, TARGET, true),
                undefined,
                syncOnlyRequested(options)
            );
        } else {
            var program = buildProgram(source, sdkDts);
            errors = constraintErrors(
                program.getSourceFile("/main.ts"),
                program.getTypeChecker(),
                syncOnlyRequested(options)
            ).concat(programErrors(program));
        }
        if (errors.length > 0) return { error: firstOf(errors) };

        // Erase types, whitespace-preserving. Unsupported (non-erasable) syntax
        // is reported instead of being passed through broken.
        var unsupported = null;
        var js = tsBlankSpace(source, function (node) {
            if (!unsupported) unsupported = node;
        });
        if (unsupported) {
            // ts-blank-space parses without parent links, so getSourceFile()
            // is unavailable; derive the line from the source text itself.
            var upto = source.slice(0, Math.max(0, unsupported.pos));
            var line = upto.split("\n").length;
            return {
                error: {
                    message: "unsupported TypeScript syntax (not erasable): " + ts.SyntaxKind[unsupported.kind],
                    type: "TSSyntaxError",
                    line: line,
                },
            };
        }
        return { js: js };
    };
})();

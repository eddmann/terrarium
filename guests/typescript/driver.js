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
 *  - With `options.sync_only`, asynchronous and generator syntax is rejected
 *    outright (see SYNC_ONLY below) — regardless of `@ts-nocheck`.
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

    // Run the checker over [libs, runtime+sdk .d.ts, source]; return every
    // error diagnostic as {message, type, line?}. Shared by eval (first error
    // gates execution) and the check-only entrypoint (full list is the result).
    function runCheck(source, sdkDts) {
        var files = Object.create(null);
        files["/main.ts"] = source;
        files["/sdk.d.ts"] = RUNTIME_DTS + (sdkDts || "");

        var options = {
            target: ts.ScriptTarget.ES2020,
            lib: ["lib.es2020.d.ts"],
            strict: true,
            noEmit: true,
            types: [],
            skipLibCheck: true,
        };
        var host = {
            getSourceFile: function (name, lang) {
                if (files[name] !== undefined) {
                    return ts.createSourceFile(name, files[name], lang || ts.ScriptTarget.ES2020, true);
                }
                var short = libName(name);
                if (LIBS[short] !== undefined) {
                    if (!libCache[short]) {
                        libCache[short] = ts.createSourceFile(name, LIBS[short], ts.ScriptTarget.ES2020, true);
                    }
                    return libCache[short];
                }
                return undefined;
            },
            getDefaultLibFileName: function () { return LIB_DIR + "lib.es2020.d.ts"; },
            getDefaultLibLocation: function () { return LIB_DIR; },
            writeFile: function () {},
            getCurrentDirectory: function () { return "/"; },
            getCanonicalFileName: function (f) { return f; },
            useCaseSensitiveFileNames: function () { return true; },
            getNewLine: function () { return "\n"; },
            fileExists: function (f) { return files[f] !== undefined || LIBS[libName(f)] !== undefined; },
            readFile: function (f) { return files[f] !== undefined ? files[f] : LIBS[libName(f)]; },
        };

        var program = ts.createProgram(
            ["/sdk.d.ts", "/main.ts"],
            options,
            host,
            sdkDts === lastDts ? lastProgram : undefined
        );
        lastProgram = program;
        lastDts = sdkDts;

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
    // SYNC_ONLY: the host has no event loop
    // ---------------------------------------------------------------------
    //
    // The guest never drains the microtask/job queue: whatever an `await`
    // suspends on, or a generator suspends into, is simply never resumed. Left
    // alone that half-runs silently -- an async IIFE returns a pending promise
    // and its continuation is dead code. When the host sets `sync_only`, that
    // syntax is rejected at compile time instead, with a message that teaches
    // the synchronous shape rather than just naming the ban.
    //
    // Deliberate asymmetry with `@ts-nocheck`: the pragma opts out of the TYPE
    // check, which is an author's preference about their own annotations.
    // sync-only is not a preference -- it is a capability the host does not
    // have -- so the walk runs whether or not the pragma is present. A guest
    // that could not finish the program is worse than one that refuses it.
    //
    // A dedicated `forEachChild` walk over the source's own SourceFile, not the
    // checker: this needs syntax only, so it costs one parse instead of a
    // Program, and it stays available on the `@ts-nocheck` path where no
    // Program is built at all.
    var SYNC_ONLY_TYPE = "TSSyncOnly";
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
    };

    // Every async/generator construct in `source`, as {message, type, line},
    // in source order. Empty when the source is already synchronous.
    function syncOnlyErrors(source) {
        var file = ts.createSourceFile("/main.ts", source, ts.ScriptTarget.ES2020, true);
        var found = [];

        function report(kind, pos) {
            found.push({ pos: pos, kind: kind });
        }

        function visit(node) {
            var kind = node.kind;
            if (kind === ts.SyntaxKind.AwaitExpression) {
                report("await", node.getStart(file));
            } else if (kind === ts.SyntaxKind.YieldExpression) {
                report("yield", node.getStart(file));
            } else if (kind === ts.SyntaxKind.ForOfStatement && node.awaitModifier) {
                report("forAwait", node.getStart(file));
            }
            // `function*` / `*method()` in every form that can carry the token.
            if (
                node.asteriskToken &&
                (kind === ts.SyntaxKind.FunctionDeclaration ||
                    kind === ts.SyntaxKind.FunctionExpression ||
                    kind === ts.SyntaxKind.MethodDeclaration)
            ) {
                report("generator", node.asteriskToken.getStart(file));
            }
            // The `async` modifier on any function form: declaration,
            // expression, arrow, class method, object-literal method.
            var mods = ts.canHaveModifiers(node) ? ts.getModifiers(node) : undefined;
            if (mods) {
                for (var i = 0; i < mods.length; i++) {
                    if (mods[i].kind === ts.SyntaxKind.AsyncKeyword) {
                        report("async", mods[i].getStart(file));
                    }
                }
            }
            ts.forEachChild(node, visit);
        }
        ts.forEachChild(file, visit);

        found.sort(function (a, b) { return a.pos - b.pos; });
        var errors = [];
        for (var j = 0; j < found.length; j++) {
            errors.push({
                message: SYNC_ONLY_MESSAGES[found[j].kind],
                type: SYNC_ONLY_TYPE,
                line: file.getLineAndCharacterOfPosition(found[j].pos).line + 1,
            });
        }
        return errors;
    }

    function syncOnlyRequested(options) {
        return !!(options && options.sync_only);
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

    // Check-only entrypoint: every diagnostic, nothing executed. An explicit
    // check ignores @ts-nocheck -- you asked for the diagnostics. tsc itself
    // honours the pragma inside the checker, so blank it out of the leading
    // comments first (same-length replacement: positions are preserved).
    globalThis.__terrariumCheck = function (source, sdkDts, options) {
        // Host constraints first, and every occurrence of them: they are the
        // reason the program cannot run at all.
        var errors = syncOnlyRequested(options) ? syncOnlyErrors(source) : [];

        var ranges = ts.getLeadingCommentRanges(source, 0) || [];
        for (var i = 0; i < ranges.length; i++) {
            var idx;
            while ((idx = source.indexOf("@ts-nocheck", ranges[i].pos)) !== -1 && idx < ranges[i].end) {
                source = source.slice(0, idx) + "           " + source.slice(idx + 11);
            }
        }
        return errors.concat(runCheck(source, sdkDts));
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

        // sync-only is a host capability constraint, not an author preference:
        // it is enforced ahead of the type check and *through* @ts-nocheck.
        if (syncOnlyRequested(options)) {
            var syncErrors = syncOnlyErrors(source);
            if (syncErrors.length > 0) return { error: firstOf(syncErrors) };
        }

        if (!noCheck) {
            var errors = runCheck(source, sdkDts);
            if (errors.length > 0) return { error: firstOf(errors) };
        }

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

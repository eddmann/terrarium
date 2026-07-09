/*
 * Terrarium TypeScript guest driver. Runs inside the persistent "compiler"
 * QuickJS context, alongside the real TypeScript compiler (`globalThis.ts`,
 * loaded from bytecode), the bundled lib .d.ts map (`globalThis.LIBS`), and
 * ts-blank-space (`globalThis.tsBlankSpace`).
 *
 * Exposes one function, called from C per eval:
 *
 *   __terrariumCompile(source, sdkDts) -> { js: string }
 *                                       | { error: { message, type, line? } }
 *
 * Behaviour:
 *  - Type-checks `source` against [libs, sdkDts] (strict). The SDK .d.ts is the
 *    host-generated declaration of the registered capabilities, so the type
 *    environment is exactly the capability environment.
 *  - A leading `// @ts-nocheck` comment (TypeScript's own pragma) skips the
 *    check; the source is still stripped and run.
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

    // Check-only entrypoint: every diagnostic, nothing executed. An explicit
    // check ignores @ts-nocheck -- you asked for the diagnostics. tsc itself
    // honours the pragma inside the checker, so blank it out of the leading
    // comments first (same-length replacement: positions are preserved).
    globalThis.__terrariumCheck = function (source, sdkDts) {
        var ranges = ts.getLeadingCommentRanges(source, 0) || [];
        for (var i = 0; i < ranges.length; i++) {
            var idx;
            while ((idx = source.indexOf("@ts-nocheck", ranges[i].pos)) !== -1 && idx < ranges[i].end) {
                source = source.slice(0, idx) + "           " + source.slice(idx + 11);
            }
        }
        return runCheck(source, sdkDts);
    };

    globalThis.__terrariumCompile = function (source, sdkDts) {
        // TypeScript's own opt-out pragma, honoured only in leading comments.
        var noCheck = false;
        var ranges = ts.getLeadingCommentRanges(source, 0) || [];
        for (var i = 0; i < ranges.length; i++) {
            if (source.slice(ranges[i].pos, ranges[i].end).indexOf("@ts-nocheck") !== -1) {
                noCheck = true;
                break;
            }
        }

        if (!noCheck) {
            var errors = runCheck(source, sdkDts);
            if (errors.length > 0) {
                var first = errors[0];
                var out = { message: first.message, type: first.type };
                if (typeof first.line === "number") out.line = first.line;
                if (errors.length > 1) {
                    out.message += " [+" + (errors.length - 1) + " more error" + (errors.length > 2 ? "s" : "") + "]";
                }
                return { error: out };
            }
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

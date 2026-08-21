#!/usr/bin/env node
/*
 * Phase-2 dry run: type-check the whole probe corpus under the CURRENT compiler
 * pin and under the PROPOSED one, without touching the guest.
 *
 * driver.js is compiled to QuickJS bytecode and baked into the wasm fixture, so
 * a pin change cannot be observed without rebuilding. This script instead
 * reproduces driver.js's `buildProgram` exactly -- same TypeScript 6.0.3, same
 * RUNTIME_DTS, same compiler options, same in-memory CompilerHost over the same
 * lib map that build.sh bundles (everything except dom/webworker/scripthost) --
 * and varies only `target`, `lib`, and the lib overrides.
 *
 * Fidelity is not assumed, it is checked: the replica's diagnostics for the
 * CURRENT pin are compared against the diagnostics the real guest produced in
 * results.json (written by audit.php). If they differ, the replica is wrong and
 * the run fails loudly rather than reporting a conclusion built on it.
 *
 *   node verify-proposal.mjs [--out proposal.json]
 *
 * Requires results.json (run audit.php first).
 */
import fs from "node:fs";
import path from "node:path";
import { fileURLToPath } from "node:url";
import { createRequire } from "node:module";

const HERE = path.dirname(fileURLToPath(import.meta.url));
const LIB_DIR_SRC = path.resolve(HERE, "../../build/typescript-6.0.3/lib");
const require = createRequire(import.meta.url);
const ts = require(path.join(LIB_DIR_SRC, "typescript.js"));

// --- the guest's lib map, built exactly as build.sh builds it ---------------
const LIBS = Object.create(null);
for (const name of fs.readdirSync(LIB_DIR_SRC).sort()) {
    if (!name.startsWith("lib.") || !name.endsWith(".d.ts")) continue;
    if (["dom", "webworker", "scripthost"].some((x) => name.includes(x))) continue;
    LIBS[name] = fs.readFileSync(path.join(LIB_DIR_SRC, name), "utf8");
}

// --- verbatim from driver.js -------------------------------------------------
const LIB_DIR = "/libs/";
const RUNTIME_DTS =
    "declare const console: {\n" +
    "    log(...args: unknown[]): void;\n" +
    "    error(...args: unknown[]): void;\n" +
    "    warn(...args: unknown[]): void;\n" +
    "    info(...args: unknown[]): void;\n" +
    "    debug(...args: unknown[]): void;\n" +
    "};\n";

const libName = (f) => (f.indexOf(LIB_DIR) === 0 ? f.slice(LIB_DIR.length) : f);

/** driver.js's buildProgram + programErrors, parameterised by the pin. */
function check(source, sdkDts, pin) {
    const files = Object.create(null);
    files["/main.ts"] = source;
    files["/sdk.d.ts"] = RUNTIME_DTS + (sdkDts || "");

    const libText = (short) =>
        Object.prototype.hasOwnProperty.call(pin.libOverrides ?? {}, short)
            ? pin.libOverrides[short]
            : LIBS[short];

    const options = {
        target: ts.ScriptTarget[pin.target],
        lib: pin.lib,
        strict: true,
        noEmit: true,
        types: [],
        skipLibCheck: true,
    };
    const host = {
        getSourceFile(name, lang) {
            if (files[name] !== undefined) {
                return ts.createSourceFile(name, files[name], lang || options.target, true);
            }
            const short = libName(name);
            const text = libText(short);
            return text === undefined ? undefined : ts.createSourceFile(name, text, options.target, true);
        },
        getDefaultLibFileName: () => LIB_DIR + pin.defaultLib,
        getDefaultLibLocation: () => LIB_DIR,
        writeFile() {},
        getCurrentDirectory: () => "/",
        getCanonicalFileName: (f) => f,
        useCaseSensitiveFileNames: () => true,
        getNewLine: () => "\n",
        fileExists: (f) => files[f] !== undefined || LIBS[libName(f)] !== undefined,
        readFile: (f) => (files[f] !== undefined ? files[f] : libText(libName(f))),
    };

    const program = ts.createProgram(["/sdk.d.ts", "/main.ts"], options, host);
    const out = [];
    for (const d of ts.getPreEmitDiagnostics(program)) {
        if (d.category !== ts.DiagnosticCategory.Error) continue;
        const row = { code: "TS" + d.code, message: ts.flattenDiagnosticMessageText(d.messageText, " ") };
        if (d.file && typeof d.start === "number") {
            const lc = d.file.getLineAndCharacterOfPosition(d.start);
            if (d.file.fileName === "/main.ts") row.line = lc.line + 1;
            else row.message += " (in " + d.file.fileName + ")";
        }
        out.push(row);
    }
    return out;
}

// --- the pins ----------------------------------------------------------------
// Two families of lib file declare an environment this sandbox does not have,
// and the audit finds both absent from the engine outright:
//
//   *.intl.d.ts          quickjs-ng is built without Intl: `typeof Intl` is
//                        "undefined", so every Intl declaration is a lie.
//   *.sharedmemory.d.ts  there is no Atomics global at all, and a growable
//                        SharedArrayBuffer can never be constructed, so ES2024's
//                        grow/growable/maxByteLength can never succeed.
//
// Serving those files empty leaves the reference graph intact (the chain roots
// still resolve them) while removing declarations the engine cannot honour.
const EXCLUDED = /\.(intl|sharedmemory)\.d\.ts$/;
const exclusionsFor = (root) =>
    Object.fromEntries(
        resolveChain(root)
            .filter((f) => EXCLUDED.test(f))
            .map((f) => [f, ""])
    );

/** The lib files a chain root pulls in, in reference order. */
function resolveChain(root, seen = new Set(), out = []) {
    if (seen.has(root)) return out;
    seen.add(root);
    const text = LIBS[`lib.${root}.d.ts`];
    if (text === undefined) return out;
    for (const m of text.matchAll(/\/\/\/\s*<reference\s+lib="([^"]+)"\s*\/>/g)) resolveChain(m[1], seen, out);
    out.push(`lib.${root}.d.ts`);
    return out;
}

const SANDBOX_EXCLUSIONS = exclusionsFor("es2024");

const PINS = {
    current: {
        target: "ES2020",
        lib: ["lib.es2020.d.ts"],
        defaultLib: "lib.es2020.d.ts",
        libOverrides: {},
    },
    proposed: {
        target: "ES2024",
        lib: ["lib.es2024.d.ts"],
        defaultLib: "lib.es2024.d.ts",
        libOverrides: SANDBOX_EXCLUSIONS,
    },
    // Variants kept for the record: the raise with no exclusions at all, and
    // the raise excluding only what ES2021..ES2024 newly declares (leaving the
    // pre-existing Intl/Atomics mismatches exactly as they are today).
    "proposed-no-exclusions": {
        target: "ES2024",
        lib: ["lib.es2024.d.ts"],
        defaultLib: "lib.es2024.d.ts",
        libOverrides: {},
    },
    "proposed-new-lies-only": {
        target: "ES2024",
        lib: ["lib.es2024.d.ts"],
        defaultLib: "lib.es2024.d.ts",
        libOverrides: Object.fromEntries(
            Object.keys(SANDBOX_EXCLUSIONS)
                .filter((f) => !resolveChain("es2020").includes(f))
                .map((f) => [f, ""])
        ),
    },
};

// --- corpora -----------------------------------------------------------------
const corpus = JSON.parse(fs.readFileSync(path.join(HERE, "probes.json"), "utf8"));

// A control corpus: code that must behave IDENTICALLY before and after the
// raise. Everything the existing suites rely on, plus the sandbox's own walls.
const SDK_DTS = "declare const user: { fetch(id: number): { name: string; roles: string[] } };\n";
const CONTROLS = [
    ["plain typed code", "const x: number = 1 + 2 * 3;\nx;"],
    ["generics + interfaces", "interface P { x: number }\nfunction d<T extends P>(ps: T[]): number[] { return ps.map(p => p.x * 2); }\nd([{ x: 1 }]);"],
    ["SDK call, correct types", "const u = user.fetch(42);\n`${u.name} has ${u.roles.length} roles`;"],
    ["SDK call, wrong argument type -> TS2345", "user.fetch('42');"],
    ["unknown SDK member -> TS2339", "user.fetch(1).missing;"],
    ["unknown global stays unknown -> TS2304", "fetch('https://example.com');"],
    ["structuredClone stays unknown -> TS2304", "structuredClone({ a: 1 });"],
    ["console is declared by RUNTIME_DTS", "console.log('hi', 1);"],
    ["strict mode still on -> TS2322", "const n: string = 1;"],
    ["implicit any still reported -> TS7006", "function f(x) { return x; }\nf(1);"],
    ["async still type-checks clean (sync_only is a separate walk)", "const f = async (): Promise<number> => 1;\nf;"],
    ["DOM stays out of the world -> TS2584", "document.querySelector('div');"],
    ["Node globals stay out of the world -> TS2591", "process.exit(0);"],
    ["Intl core: unchanged by the raise (declared in lib.es5, engine lacks it)", "new Intl.NumberFormat('en').format(1);"],
    ["toLocaleString keeps working (it exists, locale-blind)", "(1).toLocaleString('en');\nnew Date(0).toLocaleDateString('en');"],
    ["localeCompare keeps working", "'a'.localeCompare('b');"],
];

// --- run ---------------------------------------------------------------------
const codes = (ds) => ds.map((d) => d.code).join(",") || "clean";

// 1. fidelity: the replica must reproduce the real guest at the current pin.
const results = JSON.parse(fs.readFileSync(path.join(HERE, "results.json"), "utf8"));
const fidelity = [];
for (const p of results.probes) {
    const probe = corpus.probes.find((q) => q.id === p.id);
    const mine = check(probe.check ?? probe.source, "", PINS.current);
    const theirs = p.gateAtCurrentPin.diagnostics;
    const same =
        mine.length === theirs.length &&
        mine.every((d, i) => d.code === theirs[i].code && d.message === theirs[i].message);
    fidelity.push({ id: p.id, same, replica: codes(mine), guest: codes(theirs) });
}
const drift = fidelity.filter((f) => !f.same);

// 2. the corpus under every pin.
const rows = [];
for (const p of corpus.probes) {
    const src = p.check ?? p.source;
    const row = { id: p.id, es: p.es, lib: p.lib, feature: p.feature, engine: null, pins: {} };
    const observed = results.probes.find((r) => r.id === p.id);
    row.engine = observed ? observed.engine.verdict : "unknown";
    for (const [name, pin] of Object.entries(PINS)) {
        const ds = check(src, "", pin);
        row.pins[name] = { clean: ds.length === 0, codes: codes(ds), diagnostics: ds };
    }
    rows.push(row);
}

// 3. controls: identical diagnostics before and after.
const controls = [];
for (const [label, src] of CONTROLS) {
    const before = check(src, SDK_DTS, PINS.current);
    const after = check(src, SDK_DTS, PINS.proposed);
    controls.push({
        label,
        before: codes(before),
        after: codes(after),
        unchanged: codes(before) === codes(after),
        diagnostics: after,
    });
}

// --- report ------------------------------------------------------------------
console.log(`replica fidelity vs the real guest at the current pin: ${fidelity.length - drift.length}/${fidelity.length} identical`);
for (const d of drift) console.log(`  DRIFT ${d.id}: replica=${d.replica} guest=${d.guest}`);

console.log("\nprobe corpus, checker verdict per pin");
console.log(
    "  " +
        "id".padEnd(38) +
        "engine".padEnd(20) +
        "current".padEnd(22) +
        "proposed".padEnd(22) +
        "no-exclusions"
);
for (const r of rows) {
    console.log(
        "  " +
            r.id.padEnd(38) +
            r.engine.padEnd(20) +
            r.pins.current.codes.padEnd(22) +
            r.pins.proposed.codes.padEnd(22) +
            r.pins["proposed-no-exclusions"].codes
    );
}

console.log("\ncontrols (must be unchanged by the raise)");
for (const c of controls) {
    console.log(`  ${c.unchanged ? "same " : "CHANGED"} ${c.label.padEnd(56)} ${c.before} -> ${c.after}`);
}

const changedControls = controls.filter((c) => !c.unchanged);

// The raise is correct when, for every probe, the checker agrees with the
// engine: implemented features check clean, absent ones do not.
// `ahead.`    the engine has it, the proposal deliberately leaves it undeclared
//             (under-declaring is the safe direction, so a diagnostic is fine)
// `residual.` a known over-declaration this mechanism cannot reach, unchanged
//             by the raise and tracked separately
// `mismatch.` a pre-existing syntax mismatch, orthogonal to lib and target
const EXEMPT = /^(ahead|residual|mismatch)\./;
const disagreements = rows.filter((r) => {
    if (EXEMPT.test(r.id)) return false;
    if (r.engine === "implemented") return !r.pins.proposed.clean;
    if (r.engine === "absent-as-expected") return r.pins.proposed.clean;
    return true;
});

const doc = {
    typescript: ts.version,
    pins: Object.fromEntries(
        Object.entries(PINS).map(([k, v]) => [
            k,
            { target: v.target, lib: v.lib, libOverrides: Object.keys(v.libOverrides) },
        ])
    ),
    replicaFidelity: { total: fidelity.length, identical: fidelity.length - drift.length, drift },
    probes: rows,
    controls,
    disagreementsUnderProposedPin: disagreements.map((d) => ({
        id: d.id,
        engine: d.engine,
        proposed: d.pins.proposed.codes,
    })),
};
const outFlag = process.argv.indexOf("--out");
const outPath = outFlag === -1 ? path.join(HERE, "proposal.json") : process.argv[outFlag + 1];
fs.writeFileSync(outPath, JSON.stringify(doc, null, 2) + "\n");

console.log(`\ndisagreements under the proposed pin: ${disagreements.length}`);
for (const d of disagreements) console.log(`  ${d.id}: engine=${d.engine} checker=${d.pins.proposed.codes}`);
console.log(`changed controls: ${changedControls.length}`);
console.log(`-> ${outPath}`);

process.exit(drift.length === 0 && disagreements.length === 0 && changedControls.length === 0 ? 0 : 1);

#!/usr/bin/env node
/*
 * ES library delta enumerator (Terrarium T4, phase 1).
 *
 * Answers: "if the TypeScript guest raises `lib` from the es2020 chain to the
 * es2024 chain, exactly which declarations enter the type environment?"
 *
 * It resolves each chain the way the compiler does -- following the
 * `/// <reference lib="..." />` graph from the chain root -- parses every file
 * with the *real* TypeScript parser (the same 6.0.3 copy that is compiled into
 * the guest), and diffs the declared surface.
 *
 * Intl is excluded by design: `lib.*.intl.d.ts` describes an environment the
 * sandbox does not have, so those files are never candidates for the raise.
 *
 *   node enumerate-lib-delta.mjs [--out delta.json]
 *
 * Output is deterministic: every list is sorted, objects are emitted in sorted
 * key order.
 */
import fs from "node:fs";
import path from "node:path";
import { fileURLToPath } from "node:url";
import { createRequire } from "node:module";

const HERE = path.dirname(fileURLToPath(import.meta.url));
const LIB_DIR = path.resolve(HERE, "../../build/typescript-6.0.3/lib");
const require = createRequire(import.meta.url);
const ts = require(path.join(LIB_DIR, "typescript.js"));

const BASE_CHAIN = "es2020";
const TARGET_CHAIN = "es2024";
const EXCLUDE = /\.intl\.d\.ts$/; // Intl is out of the sandbox by design

/** `es2022.array` -> `/abs/lib.es2022.array.d.ts` */
function libFile(name) {
    return path.join(LIB_DIR, `lib.${name}.d.ts`);
}

/** Transitively resolve a chain root into its ordered set of lib files. */
function resolveChain(root) {
    const seen = new Set();
    const order = [];
    const visit = (name) => {
        if (seen.has(name)) return;
        seen.add(name);
        const file = libFile(name);
        if (!fs.existsSync(file)) return;
        const text = fs.readFileSync(file, "utf8");
        for (const m of text.matchAll(/\/\/\/\s*<reference\s+lib="([^"]+)"\s*\/>/g)) {
            visit(m[1]);
        }
        order.push(name);
    };
    visit(root);
    return order;
}

/**
 * Every declaration a lib file contributes, as stable keys:
 *   `Array#findLast`            an instance-interface member
 *   `ArrayConstructor#fromAsync` a constructor-interface member
 *   `globalThis:structuredClone` a global function/var
 *   `type:Awaited`               a global type alias
 */
function declarationsOf(file) {
    const text = fs.readFileSync(file, "utf8");
    const sf = ts.createSourceFile(path.basename(file), text, ts.ScriptTarget.ESNext, true);
    const keys = new Set();

    const memberName = (m) => {
        if (!m.name) {
            if (ts.isCallSignatureDeclaration(m)) return "(call)";
            if (ts.isConstructSignatureDeclaration(m)) return "(construct)";
            if (ts.isIndexSignatureDeclaration(m)) return "(index)";
            return null;
        }
        if (ts.isComputedPropertyName(m.name)) return `[${m.name.expression.getText(sf)}]`;
        return m.name.getText(sf);
    };

    const walk = (node) => {
        if (ts.isInterfaceDeclaration(node)) {
            const owner = node.name.text;
            for (const m of node.members) {
                const n = memberName(m);
                if (n) keys.add(`${owner}#${n}`);
            }
            keys.add(`interface:${owner}`);
        } else if (ts.isVariableStatement(node)) {
            for (const d of node.declarationList.declarations) {
                keys.add(`globalThis:${d.name.getText(sf)}`);
            }
        } else if (ts.isFunctionDeclaration(node) && node.name) {
            keys.add(`globalThis:${node.name.text}`);
        } else if (ts.isTypeAliasDeclaration(node)) {
            keys.add(`type:${node.name.text}`);
        } else if (ts.isModuleDeclaration(node)) {
            const ns = node.name.getText(sf).replace(/"/g, "");
            if (node.body && ts.isModuleBlock(node.body)) {
                for (const s of node.body.statements) {
                    if (ts.isInterfaceDeclaration(s)) {
                        for (const m of s.members) {
                            const n = memberName(m);
                            if (n) keys.add(`${ns}.${s.name.text}#${n}`);
                        }
                    } else if (ts.isVariableStatement(s)) {
                        for (const d of s.declarationList.declarations) {
                            keys.add(`${ns}:${d.name.getText(sf)}`);
                        }
                    } else if (ts.isFunctionDeclaration(s) && s.name) {
                        keys.add(`${ns}:${s.name.text}`);
                    } else if (ts.isTypeAliasDeclaration(s)) {
                        keys.add(`type:${ns}.${s.name.text}`);
                    }
                }
            }
        }
        // lib files are flat: no recursion past the top level is needed.
    };

    sf.statements.forEach(walk);
    return keys;
}

function surfaceOf(chainRoot) {
    const files = resolveChain(chainRoot).filter((n) => !EXCLUDE.test(`lib.${n}.d.ts`));
    const perFile = new Map();
    const all = new Set();
    for (const name of files) {
        const file = libFile(name);
        if (!fs.existsSync(file)) continue;
        const keys = declarationsOf(file);
        perFile.set(`lib.${name}.d.ts`, keys);
        for (const k of keys) all.add(k);
    }
    return { files, perFile, all };
}

const base = surfaceOf(BASE_CHAIN);
const target = surfaceOf(TARGET_CHAIN);

const byLib = {};
let total = 0;
for (const [file, keys] of [...target.perFile].sort(([a], [b]) => (a < b ? -1 : 1))) {
    const added = [...keys].filter((k) => !base.all.has(k)).sort();
    if (added.length === 0) continue;
    byLib[file] = added;
    total += added.length;
}

// Intl is outside the sandbox by design, but the chain root still references
// the intl libs, so raising `lib` pulls them in unless they are excluded
// explicitly. Count what they would add, so the exclusion is a measured
// decision rather than an omission.
const intlBase = resolveChain(BASE_CHAIN).filter((n) => EXCLUDE.test(`lib.${n}.d.ts`));
const intlTarget = resolveChain(TARGET_CHAIN).filter((n) => EXCLUDE.test(`lib.${n}.d.ts`));
const intlAdded = {};
for (const name of intlTarget) {
    if (intlBase.includes(name)) continue;
    const file = libFile(name);
    if (!fs.existsSync(file)) continue;
    intlAdded[`lib.${name}.d.ts`] = [...declarationsOf(file)].sort();
}

const out = {
    typescript: ts.version,
    baseChain: BASE_CHAIN,
    targetChain: TARGET_CHAIN,
    excluded: "lib.*.intl.d.ts (Intl is outside the sandbox by design; counted separately below)",
    baseFiles: base.files.map((n) => `lib.${n}.d.ts`).sort(),
    targetFiles: target.files.map((n) => `lib.${n}.d.ts`).sort(),
    addedFiles: target.files
        .filter((n) => !base.files.includes(n))
        .map((n) => `lib.${n}.d.ts`)
        .sort(),
    addedDeclarationCount: total,
    addedByLib: byLib,
    intlChainAdditions: {
        note: "reachable from the target chain root and therefore pulled in unless excluded",
        files: Object.keys(intlAdded).sort(),
        declarationCount: Object.values(intlAdded).reduce((n, ks) => n + ks.length, 0),
        byLib: intlAdded,
    },
};

const outFlag = process.argv.indexOf("--out");
const outPath = outFlag === -1 ? path.join(HERE, "delta.json") : process.argv[outFlag + 1];
fs.writeFileSync(outPath, JSON.stringify(out, null, 2) + "\n");

for (const [file, added] of Object.entries(byLib)) {
    console.log(`${file}  (+${added.length})`);
    for (const k of added) console.log(`    ${k}`);
}
console.log(`\n${total} added declarations across ${Object.keys(byLib).length} lib files -> ${outPath}`);

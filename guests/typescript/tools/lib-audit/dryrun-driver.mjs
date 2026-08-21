#!/usr/bin/env node
/*
 * Run the guest driver's OWN check entrypoint over the probe corpus, outside the
 * wasm, against any copy of driver.js.
 *
 * verify-proposal.mjs reasons about a faithful *replica* of driver.js's
 * buildProgram. This script removes even that indirection: it loads the real
 * driver source, gives it the three globals the guest gives it
 * (`ts`, `LIBS`, `tsBlankSpace`), and calls `__terrariumCheck`. So the phase-2
 * patch can be exercised before the fixture is ever rebuilt:
 *
 *   node dryrun-driver.mjs ../../driver.js                 # the committed pin
 *   cp ../../driver.js /tmp/d.js
 *   patch /tmp/d.js < phase2-lib-raise.patch
 *   node dryrun-driver.mjs /tmp/d.js --expect proposed     # the proposed pin
 *
 * With --expect <pin>, every probe's diagnostics must equal what
 * proposal.json recorded for that pin, and the script exits non-zero if not.
 */
import fs from "node:fs";
import path from "node:path";
import vm from "node:vm";
import { fileURLToPath } from "node:url";
import { createRequire } from "node:module";

const HERE = path.dirname(fileURLToPath(import.meta.url));
const LIB_DIR_SRC = path.resolve(HERE, "../../build/typescript-6.0.3/lib");
const require = createRequire(import.meta.url);
const ts = require(path.join(LIB_DIR_SRC, "typescript.js"));

const driverPath = process.argv[2] ?? path.resolve(HERE, "../../driver.js");
const expectFlag = process.argv.indexOf("--expect");
const expectPin = expectFlag === -1 ? null : process.argv[expectFlag + 1];

// The guest's lib map, built exactly as build.sh builds it.
const LIBS = Object.create(null);
for (const name of fs.readdirSync(LIB_DIR_SRC).sort()) {
    if (!name.startsWith("lib.") || !name.endsWith(".d.ts")) continue;
    if (["dom", "webworker", "scripthost"].some((x) => name.includes(x))) continue;
    LIBS[name] = fs.readFileSync(path.join(LIB_DIR_SRC, name), "utf8");
}

// The three globals ts_guest.c's compiler context provides. tsBlankSpace is
// only reached by the compile entrypoint, which this dry run does not use.
const sandbox = {
    ts,
    LIBS,
    tsBlankSpace: () => {
        throw new Error("tsBlankSpace is not needed for a check-only dry run");
    },
    console,
};
sandbox.globalThis = sandbox;
vm.createContext(sandbox);
vm.runInContext(fs.readFileSync(driverPath, "utf8"), sandbox, { filename: driverPath });

const corpus = JSON.parse(fs.readFileSync(path.join(HERE, "probes.json"), "utf8"));
const expected =
    expectPin === null ? null : JSON.parse(fs.readFileSync(path.join(HERE, "proposal.json"), "utf8"));

let mismatches = 0;
console.log(`driver: ${driverPath}`);
for (const p of corpus.probes) {
    const diags = sandbox.__terrariumCheck(p.check ?? p.source, "");
    const codes = diags.map((d) => d.type).join(",") || "clean";
    let flag = "";
    if (expected) {
        const row = expected.probes.find((r) => r.id === p.id);
        const want = row ? row.pins[expectPin].codes : "(no such probe)";
        if (want !== codes) {
            flag = `  MISMATCH (expected ${want})`;
            mismatches++;
        }
    }
    console.log(`  ${p.id.padEnd(40)} ${codes}${flag}`);
}

if (expected) {
    console.log(`\n${mismatches} mismatch(es) against proposal.json pin "${expectPin}"`);
}
process.exit(mismatches === 0 ? 0 : 1);

// Run the *real* guest driver (driver.js) under plain Node, outside wasm.
//
//   node tools/dev-driver.mjs analyze <file.ts> [callee ...]
//   node tools/dev-driver.mjs check   <file.ts>
//   echo 'const a = ctx.agent<{ n: number }>({});' | node tools/dev-driver.mjs analyze - ctx.agent
//
// Why this exists: iterating on the type→JSON-Schema serializer inside the
// 28 MB wasm fixture means a multi-minute rebuild per edit. driver.js is a
// classic script that only needs three globals (`ts`, `LIBS`, `tsBlankSpace`),
// all of which Node can supply from the same pinned npm package the build uses
// — so the exact file that ships in the fixture can be exercised in
// milliseconds. This is a development aid only: nothing here is compiled into
// the guest, and the authoritative expectations live in the PHP suite
// (tests/php/12_typescript_schemas.php).
//
// Requires `./build.sh` to have fetched the pinned TypeScript package
// (build/typescript-<version>/); it reads the compiler and the lib .d.ts files
// straight out of it.

import { readFileSync, readdirSync } from "node:fs";
import { dirname, join } from "node:path";
import { fileURLToPath } from "node:url";
import { createRequire } from "node:module";
import vm from "node:vm";

const HERE = dirname(fileURLToPath(import.meta.url));
const GUEST = join(HERE, "..");
const BUILD = join(GUEST, "build");

function typescriptDir() {
    const found = readdirSync(BUILD, { withFileTypes: true })
        .filter((e) => e.isDirectory() && e.name.startsWith("typescript-"))
        .map((e) => e.name)
        .sort();
    if (found.length === 0) {
        throw new Error(`no typescript-* package under ${BUILD} — run ./build.sh first`);
    }
    return join(BUILD, found[found.length - 1]);
}

// The same lib set the build bakes in: everything except the environments the
// sandbox does not have.
function loadLibs(tsDir) {
    const libDir = join(tsDir, "lib");
    const libs = Object.create(null);
    for (const name of readdirSync(libDir).sort()) {
        if (!name.startsWith("lib.") || !name.endsWith(".d.ts")) continue;
        if (["dom", "webworker", "scripthost"].some((x) => name.includes(x))) continue;
        libs[name] = readFileSync(join(libDir, name), "utf8");
    }
    return libs;
}

const tsDir = typescriptDir();
const require = createRequire(import.meta.url);
globalThis.ts = require(join(tsDir, "lib", "typescript.js"));
globalThis.LIBS = loadLibs(tsDir);
globalThis.tsBlankSpace = () => {
    throw new Error("tsBlankSpace is not wired up in the dev harness (checking/analysis only)");
};

vm.runInThisContext(readFileSync(join(GUEST, "driver.js"), "utf8"), { filename: "driver.js" });

const [, , command = "analyze", file = "-", ...callees] = process.argv;
const source = file === "-" ? readFileSync(0, "utf8") : readFileSync(file, "utf8");
const options = {};
if (callees.length > 0) options.type_argument_schemas = callees;

const result =
    command === "check"
        ? globalThis.__terrariumCheck(source, "", options)
        : globalThis.__terrariumAnalyze(source, "", options);

console.log(JSON.stringify(result, null, 2));

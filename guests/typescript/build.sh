#!/usr/bin/env bash
# Build the TypeScript guest to wasm with the WASI SDK (reactor mode).
#
#   WASI_SDK=/opt/wasi-sdk ./build.sh
#
# QuickJS-ng plus the real TypeScript compiler embedded as precompiled QuickJS
# bytecode. The pipeline:
#   1. fetch quickjs-ng (same pinned version as quickjs-guest -- bytecode is
#      version-locked, so the native qjsc and the wasm engine must match)
#   2. build a *native* qjsc from that tree
#   3. fetch the pinned `typescript` npm tarball (compiler + lib .d.ts files)
#      and `ts-blank-space` (whitespace-preserving type eraser)
#   4. generate the JS payloads (libs map, shimmed ts-blank-space) and compile
#      each to bytecode with the native qjsc (-s -s: strip source + debug --
#      compiler-internal stack traces don't matter, user code is untouched)
#   5. compile ts_guest.c + bytecode arrays + quickjs into a base wasm
#   6. pre-initialize it with Wizer (wizen/): run the compiler bring-up + a
#      warm-up check once, offline, and snapshot the resulting heap into the
#      module so ensure_compiler() is a no-op at runtime (see step 6 below)
#
# Build-time-only deps: a host C compiler, curl, python3 (JSON-encoding the lib
# files), and cargo (the Wizer step). Nothing is needed at runtime or test time
# (the fixture is committed).
set -euo pipefail

WASI_SDK="${WASI_SDK:-/opt/wasi-sdk}"
QJS_VERSION="${QJS_VERSION:-v0.15.1}"
TS_VERSION="${TS_VERSION:-6.0.3}"
TBS_VERSION="${TBS_VERSION:-0.9.0}"
HOST_CC="${HOST_CC:-cc}"
HERE="$(cd "$(dirname "$0")" && pwd)"
BUILD="$HERE/build"
QJS="$BUILD/quickjs-${QJS_VERSION}"

CLANG="$WASI_SDK/bin/clang"
[ -x "$CLANG" ] || { echo "WASI SDK clang not found at $CLANG (set WASI_SDK)"; exit 1; }

mkdir -p "$BUILD"

# --- 1. quickjs-ng sources (shared pin with quickjs-guest) ------------------
if [ ! -d "$QJS" ]; then
    echo "Fetching quickjs-ng $QJS_VERSION ..."
    mkdir -p "$QJS"
    curl -fsSL "https://github.com/quickjs-ng/quickjs/archive/refs/tags/${QJS_VERSION}.tar.gz" \
        | tar xz -C "$QJS" --strip-components=1
fi

# --- 2. native qjsc (must be the same tree as the wasm engine) --------------
if [ ! -x "$BUILD/qjsc" ]; then
    echo "Building native qjsc ..."
    "$HOST_CC" -O2 -I"$QJS" -o "$BUILD/qjsc" \
        "$QJS/qjsc.c" "$QJS/quickjs.c" "$QJS/libregexp.c" "$QJS/libunicode.c" \
        "$QJS/dtoa.c" "$QJS/quickjs-libc.c" -lm -lpthread
fi

# --- 3. typescript + ts-blank-space from the npm registry -------------------
TSPKG="$BUILD/typescript-$TS_VERSION"
if [ ! -d "$TSPKG" ]; then
    echo "Fetching typescript $TS_VERSION ..."
    mkdir -p "$TSPKG"
    curl -fsSL "https://registry.npmjs.org/typescript/-/typescript-${TS_VERSION}.tgz" \
        | tar xz -C "$TSPKG" --strip-components=1
fi
TBSPKG="$BUILD/ts-blank-space-$TBS_VERSION"
if [ ! -d "$TBSPKG" ]; then
    echo "Fetching ts-blank-space $TBS_VERSION ..."
    mkdir -p "$TBSPKG"
    curl -fsSL "https://registry.npmjs.org/ts-blank-space/-/ts-blank-space-${TBS_VERSION}.tgz" \
        | tar xz -C "$TBSPKG" --strip-components=1
fi

# --- 4a. libs.js: the lib .d.ts chain as a global map ------------------------
# Everything except the environments the sandbox doesn't have (dom, webworker,
# scripthost): the type environment must equal the real execution environment.
echo "Generating libs.js ..."
python3 - "$TSPKG/lib" "$BUILD/libs.js" <<'PY'
import json, os, sys
libdir, out = sys.argv[1], sys.argv[2]
libs = {}
for name in sorted(os.listdir(libdir)):
    if not (name.startswith("lib.") and name.endswith(".d.ts")):
        continue
    if any(x in name for x in ("dom", "webworker", "scripthost")):
        continue
    with open(os.path.join(libdir, name), "r", encoding="utf-8") as f:
        libs[name] = f.read()
with open(out, "w", encoding="utf-8") as f:
    f.write("globalThis.LIBS = ")
    f.write(json.dumps(libs))
    f.write(";\n")
print(f"  {len(libs)} lib files, {os.path.getsize(out)} bytes")
PY

# --- 4b. tsblank.js: ts-blank-space as a classic script ----------------------
# Its ESM dist imports "typescript" and "./blank-string.js"; rewrite both to the
# globals the compiler context provides.
echo "Generating tsblank.js ..."
{
    sed -e 's/^export default class BlankString/class BlankString/' \
        "$TBSPKG/out/blank-string.js"
    sed -e 's/^import tslib from "typescript";/const tslib = globalThis.ts;/' \
        -e 's/^import BlankString from ".\/blank-string.js";//' \
        -e 's/^export default function tsBlankSpace/function tsBlankSpace/' \
        -e 's/^export function blankSourceFile/function blankSourceFile/' \
        "$TBSPKG/out/index.js"
    echo 'globalThis.tsBlankSpace = tsBlankSpace;'
} > "$BUILD/tsblank.js"

# --- 4c. bytecode ------------------------------------------------------------
echo "Compiling payloads to QuickJS bytecode ..."
"$BUILD/qjsc" -s -s -C -N qjsc_typescript -o "$BUILD/typescript_bc.c" "$TSPKG/lib/typescript.js"
"$BUILD/qjsc" -s -s -C -N qjsc_libs       -o "$BUILD/libs_bc.c"       "$BUILD/libs.js"
"$BUILD/qjsc" -s -s -C -N qjsc_tsblank    -o "$BUILD/tsblank_bc.c"    "$BUILD/tsblank.js"
"$BUILD/qjsc" -s -s -C -N qjsc_driver     -o "$BUILD/driver_bc.c"     "$HERE/driver.js"

# --- 5. the base guest wasm ---------------------------------------------------
# The checker recurses deeply: 12 MiB of linker stack covers the compiler
# runtime's 4 MiB JS stack budget with headroom (vs 1 MiB for the plain guest).
# `wizer.initialize` is the pre-init entrypoint consumed in step 6 (it calls the
# guest's ensure_compiler); Wizer strips the export from the snapshot.
echo "Compiling typescript_guest.base.wasm ..."
"$CLANG" \
    --target=wasm32-wasip1 -mexec-model=reactor \
    -O2 -DNDEBUG \
    -I"$QJS" \
    "$HERE/ts_guest.c" \
    "$BUILD/typescript_bc.c" "$BUILD/libs_bc.c" "$BUILD/tsblank_bc.c" "$BUILD/driver_bc.c" \
    "$QJS/quickjs.c" "$QJS/libregexp.c" "$QJS/libunicode.c" "$QJS/dtoa.c" \
    -lm \
    -Wl,--export=eval -Wl,--export=guest_alloc -Wl,--export=check \
    -Wl,-z,stack-size=12582912 \
    -o "$BUILD/typescript_guest.base.wasm"

# --- 6. pre-initialize with Wizer --------------------------------------------
# Bake the TypeScript-compiler heap into the module so ensure_compiler() is a
# no-op at runtime: first eval (and every fresh isolated instance) drops from
# ~1 s to tens of ms. The fixture grows (the compiler heap becomes data
# segments) but stays a portable .wasm — no version-locked precompiled artifact.
# The wizen tool is self-contained (its own workspace + Wasmtime); nothing here
# is needed at runtime or test time.
echo "Pre-initializing with Wizer ..."
cargo build --release --manifest-path "$HERE/wizen/Cargo.toml"
"$HERE/wizen/target/release/ts-wizen" \
    "$BUILD/typescript_guest.base.wasm" \
    "$HERE/typescript_guest.wasm"

cp "$HERE/typescript_guest.wasm" "$HERE/../../tests/wasm/typescript_guest.wasm"
ls -la "$HERE/../../tests/wasm/typescript_guest.wasm"
echo "done."

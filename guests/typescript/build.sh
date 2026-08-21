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
# Build-time-only deps: a host C compiler, curl, git (quickjs-ng fallback fetch),
# python3 (JSON-encoding the lib files), and cargo (the Wizer step). Nothing is
# needed at runtime or test time (the fixture is committed).
#
# ## Reproducibility
#
# The output is byte-for-byte reproducible on the pinned toolchain: the same
# inputs always produce the same `tests/wasm/typescript_guest.wasm`. Three things
# hold that up, and all three are load-bearing:
#
#   * The **WASI SDK is pinned** (`WASI_SDK_VERSION`, `WASI_SDK_CLANG_VERSION`)
#     and asserted against the installed SDK below. Codegen differs between
#     clang releases, so an unpinned compiler silently breaks byte-stability --
#     this was the original gap.
#   * **Wizer runs against a deterministic WASI** (see `wizen/src/main.rs`): the
#     warm-up would otherwise bake the host wall clock, which QuickJS uses to
#     seed each context's PRNG, into the snapshot's data segments.
#   * The JS payload generation is order-stable (the lib map is built from a
#     sorted directory listing).
#
# To verify: remove the intermediates and rebuild, keeping the fetched sources.
#
#   rm -f build/*_bc.c build/tsblank.js build/libs.js build/*.wasm && ./build.sh
#
# ## Fetching quickjs-ng
#
# The GitHub archive tarball is tried first, with a `git clone --depth 1
# --branch $QJS_VERSION` fallback: proxies commonly 403 the codeload redirect
# while allowing git over HTTPS. Both yield the same tree at the tag.
set -euo pipefail

WASI_SDK="${WASI_SDK:-/opt/wasi-sdk}"
WASI_SDK_VERSION="${WASI_SDK_VERSION:-25.0}"
WASI_SDK_CLANG_VERSION="${WASI_SDK_CLANG_VERSION:-19.1.5}"
QJS_VERSION="${QJS_VERSION:-v0.16.2}"
TS_VERSION="${TS_VERSION:-6.0.3}"
TBS_VERSION="${TBS_VERSION:-0.9.0}"
HOST_CC="${HOST_CC:-cc}"
HERE="$(cd "$(dirname "$0")" && pwd)"
BUILD="$HERE/build"
QJS="$BUILD/quickjs-${QJS_VERSION}"

CLANG="$WASI_SDK/bin/clang"
[ -x "$CLANG" ] || { echo "WASI SDK clang not found at $CLANG (set WASI_SDK)"; exit 1; }

# --- 0. assert the pinned WASI SDK -------------------------------------------
# Byte-stability is only meaningful against a known compiler, so refuse to build
# with an SDK other than the pin rather than emit a fixture nobody can reproduce.
sdk_mismatch() {
    cat >&2 <<EOF
$1

  expected: wasi-sdk $WASI_SDK_VERSION (clang $WASI_SDK_CLANG_VERSION)
  found:    $WASI_SDK

Install the pinned SDK from
  https://github.com/WebAssembly/wasi-sdk/releases/tag/wasi-sdk-${WASI_SDK_VERSION%%.*}
and point WASI_SDK at it. To build with a different SDK anyway (the fixture will
not match the committed one byte-for-byte), set WASI_SDK_VERSION and
WASI_SDK_CLANG_VERSION to what you have installed.
EOF
    exit 1
}

[ -f "$WASI_SDK/VERSION" ] || sdk_mismatch "No VERSION file at $WASI_SDK/VERSION -- cannot identify the WASI SDK."
sdk_version="$(head -n 1 "$WASI_SDK/VERSION" | tr -d '[:space:]')"
[ "$sdk_version" = "$WASI_SDK_VERSION" ] || sdk_mismatch "WASI SDK version mismatch: $WASI_SDK/VERSION reports '$sdk_version'."
"$CLANG" --version | head -n 1 | grep -qF "$WASI_SDK_CLANG_VERSION" \
    || sdk_mismatch "clang version mismatch: $("$CLANG" --version | head -n 1)"

mkdir -p "$BUILD"

# --- 1. quickjs-ng sources (shared pin with quickjs-guest) ------------------
# Tarball first, git clone as the fallback (see the header): the two produce the
# same tree, and the .git directory is dropped so they stay interchangeable.
if [ ! -d "$QJS" ]; then
    echo "Fetching quickjs-ng $QJS_VERSION ..."
    rm -rf "$QJS.partial"
    mkdir -p "$QJS.partial"
    if ! curl -fsSL "https://github.com/quickjs-ng/quickjs/archive/refs/tags/${QJS_VERSION}.tar.gz" \
        | tar xz -C "$QJS.partial" --strip-components=1; then
        echo "  tarball fetch failed, falling back to git clone ..."
        rm -rf "$QJS.partial"
        git clone --quiet --depth 1 --branch "$QJS_VERSION" \
            https://github.com/quickjs-ng/quickjs "$QJS.partial"
        rm -rf "$QJS.partial/.git"
    fi
    mv "$QJS.partial" "$QJS"
fi

# --- 2. native qjsc (must be the same tree as the wasm engine) --------------
# -D_GNU_SOURCE: quickjs-libc.c reaches for `environ`, which glibc only declares
# under that feature macro (the WASI build gets it from wasi-libc regardless).
#
# The binary is cached under the version it was built from. Keying on
# QJS_VERSION is load-bearing, not cosmetic: qjsc bytecode is version-locked (the
# v0.15.1 -> v0.16.2 bump moved BC_VERSION 26 -> 27), so a qjsc left over from the
# previous pin would emit bytecode the freshly built engine refuses to read --
# and the failure would surface only at runtime, inside the wasm.
QJSC="$BUILD/qjsc-${QJS_VERSION}"
if [ ! -x "$QJSC" ]; then
    echo "Building native qjsc ..."
    "$HOST_CC" -O2 -D_GNU_SOURCE -I"$QJS" -o "$QJSC" \
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
#
# One extra entry, `lib.es5.no-intl.d.ts`, is generated here rather than
# shipped by TypeScript. driver.js serves the whole `*.intl.d.ts` family empty
# because quickjs-ng is built without Intl, but lib.es5.d.ts declares its own
# `namespace Intl` and cannot be emptied -- everything else in ES5 lives in it.
# So the variant drops exactly the namespace's three VALUE declarations
# (`var Collator`, `var NumberFormat`, `var DateTimeFormat`) and keeps every
# interface, which leaves `Intl` a TYPE-ONLY namespace:
#
#   new Intl.NumberFormat("en")            -> a check error (Intl is not a value)
#   (1).toLocaleString("en", opts)         -> still fine; `Intl.NumberFormatOptions`
#                                             still resolves, so the signature is intact
#
# Deleting the whole namespace would leave those option types dangling; keeping
# the values would keep lying about an engine that has no Intl at all. The
# substitution is applied in driver.js (LIB_SUBSTITUTES) and mirrored by
# tools/dev-driver.mjs; tests/php/11_es_surface.php is the parity evidence.
echo "Generating libs.js ..."
python3 - "$TSPKG/lib" "$BUILD/libs.js" <<'PY'
import json, os, re, sys
libdir, out = sys.argv[1], sys.argv[2]

INTL_VALUE = re.compile(r"^\s{4}var [A-Za-z]+: [A-Za-z]+Constructor;\n", re.M)

def strip_intl_values(text):
    """Remove the value declarations from lib.es5's `declare namespace Intl`."""
    start = text.index("declare namespace Intl {")
    end = text.index("\n}\n", start) + len("\n}\n")
    body, removed = INTL_VALUE.subn("", text[start:end])
    if removed == 0:
        raise SystemExit("lib.es5.d.ts: no Intl value declarations matched -- the strip is stale")
    return text[:start] + body + text[end:], removed

libs = {}
for name in sorted(os.listdir(libdir)):
    if not (name.startswith("lib.") and name.endswith(".d.ts")):
        continue
    if any(x in name for x in ("dom", "webworker", "scripthost")):
        continue
    with open(os.path.join(libdir, name), "r", encoding="utf-8") as f:
        libs[name] = f.read()

es5 = libs.get("lib.es5.d.ts")
if es5 is None:
    raise SystemExit("lib.es5.d.ts missing from the TypeScript package")
stripped, removed = strip_intl_values(es5)
libs["lib.es5.no-intl.d.ts"] = stripped
print(f"  lib.es5.no-intl.d.ts: {removed} Intl value declarations removed, "
      f"{len(es5) - len(stripped)} bytes")

with open(out, "w", encoding="utf-8") as f:
    f.write("globalThis.LIBS = ")
    f.write(json.dumps(libs, sort_keys=True))
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
"$QJSC" -s -s -C -N qjsc_typescript -o "$BUILD/typescript_bc.c" "$TSPKG/lib/typescript.js"
"$QJSC" -s -s -C -N qjsc_libs       -o "$BUILD/libs_bc.c"       "$BUILD/libs.js"
"$QJSC" -s -s -C -N qjsc_tsblank    -o "$BUILD/tsblank_bc.c"    "$BUILD/tsblank.js"
"$QJSC" -s -s -C -N qjsc_driver     -o "$BUILD/driver_bc.c"     "$HERE/driver.js"

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
    -Wl,--export=eval -Wl,--export=guest_alloc -Wl,--export=check -Wl,--export=analyze \
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

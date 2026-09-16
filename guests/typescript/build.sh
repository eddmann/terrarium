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
HOST_CC="${HOST_CC:-cc}"
HERE="$(cd "$(dirname "$0")" && pwd)"
# Third-party pins + their checksums, shared with the plain QuickJS guest.
# shellcheck source=../pinned-sources.sh
. "$HERE/../pinned-sources.sh"
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
# Tarball first, git clone as the fallback (see the header); either way the
# extracted tree is verified against the pinned digest before use.
fetch_quickjs "$QJS"

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
# Both tarballs are checksum-verified against the pins (see ../pinned-sources.sh):
# an npm .tgz is content-addressed and immutable, so its sha256 is the pin.
TSPKG="$BUILD/typescript-$TS_VERSION"
fetch_npm "$TSPKG" typescript "$TS_VERSION" "$TS_SHA256"
TBSPKG="$BUILD/ts-blank-space-$TBS_VERSION"
fetch_npm "$TBSPKG" ts-blank-space "$TBS_VERSION" "$TBS_SHA256"

# --- 3b. the third-party licenses that travel with the fixture ---------------
# The compiled guest EMBEDS the TypeScript compiler and ts-blank-space, so
# redistributing tests/wasm/typescript_guest.wasm redistributes both. Apache-2.0
# requires the license text and any NOTICE alongside it, so copy them out of the
# pinned packages into a committed directory: the release workflow ships that
# directory in the guests archive, and CI asserts it is there. Refreshed on
# every build, so it can never describe a version that is no longer the pin.
LICDIR="$HERE/third-party"
echo "Refreshing $LICDIR from the pinned packages ..."
mkdir -p "$LICDIR/typescript" "$LICDIR/ts-blank-space"
cp "$TSPKG/LICENSE.txt" "$LICDIR/typescript/LICENSE.txt"
cp "$TSPKG/ThirdPartyNoticeText.txt" "$LICDIR/typescript/ThirdPartyNoticeText.txt"
cp "$TBSPKG/LICENSE" "$LICDIR/ts-blank-space/LICENSE"
cat > "$LICDIR/README.md" <<EOF
# Third-party licenses embedded in \`typescript_guest.wasm\`

Generated by \`guests/typescript/build.sh\` — do not edit by hand; change the
version pins in \`guests/pinned-sources.sh\` and rebuild.

| Component | Version | License |
|---|---|---|
| [TypeScript](https://github.com/microsoft/TypeScript) | $TS_VERSION | Apache-2.0 (\`typescript/LICENSE.txt\`, \`typescript/ThirdPartyNoticeText.txt\`) |
| [ts-blank-space](https://github.com/bloomberg/ts-blank-space) | $TBS_VERSION | Apache-2.0 (\`ts-blank-space/LICENSE\`) |

QuickJS-ng (MIT) is compiled into every JS guest; its notice is in the
repository-root [THIRD_PARTY_LICENSES.md](../../../THIRD_PARTY_LICENSES.md),
which also reproduces the two license texts above in full.
EOF

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
# The checker recurses deeply, so the guest needs more linker stack than the
# plain QuickJS guest's 1 MiB. 4 MiB is sized against what actually bounds
# recursion here, which is NOT QuickJS's own limit: quickjs-ng compiles out
# `js_check_stack_overflow` on wasi (`update_stack_limit` forces
# `stack_limit = 0` under `#if defined(__wasi__)`, and `JS_NewRuntime2` forces
# `rt->stack_size = 0`), so `JS_SetMaxStackSize` limits nothing on this target
# and ts_guest.c does not call it. What stops a runaway recursion is Wasmtime's
# NATIVE stack limit (`max_wasm_stack`, `maxStack` on the PHP side), which the
# engine hard-caps at `async_stack_size` = 2 MiB -- a host cannot configure
# more, and past it the guest takes a clean `call stack exhausted` trap.
#
# Measured against that ceiling (maxStack = 2 MiB, the deepest reachable
# checker, parser, schema and JS-recursion workloads), the linear-memory
# shadow stack tops out at ~1.2 MiB: a 1 MiB stack diverges from a 12 MiB one,
# a 2 MiB stack does not. 4 MiB is that worst case with ~3.3x headroom.
#
# `--stack-first` decides what happens if that headroom is ever wrong. The
# shadow stack grows DOWNWARDS, and in the default layout (static data, then
# the stack, then the heap) it grows straight into the static data: an overflow
# silently corrupts whatever constant or global sits below it, and the program
# fails later, somewhere else. Measured on a deliberately undersized 1 MiB
# build: the same deep-nesting check that traps out of bounds at one depth
# reports `InternalError: invalid opcode: pc=17 opcode=0x00` at the next -- the
# guest ran to completion and handed back a diagnostic about bytecode the
# overflow had scribbled on. With the stack placed first it runs off the BOTTOM
# of linear memory instead, so the overflowing access is itself out of bounds
# and traps deterministically, at the depth that overflowed and nowhere else.
# Same bytes reserved, sound failure mode.
#
# Sizing still is not free, but stack-first changed what it costs. A `.cwasm`
# carries linear memory from the first INITIALISED page to the last (which,
# for a Wizer snapshot whose heap reaches the top, is the end of the declared
# initial memory). With the stack between the data and the heap, page 0
# is initialised, so the reserved stack fell inside that image and every byte of
# it -- zero or not -- was a byte in the artifact (which is what made 12 MiB ->
# 4 MiB worth 45,062,768 -> 36,674,160 bytes). Placed first, the stack is BELOW
# the first initialised page and mostly drops out of the image: the same 4 MiB
# build precompiles to 32,557,568 bytes, and a 12 MiB stack-first build to
# 32,557,560 -- a rounding error apart. What a bigger stack still costs is the
# declared initial memory itself (496 pages here, 624 at 12 MiB), which every
# instance reserves and `memoryLimit` is measured against. So size it against
# the measurement above, not by reflex, and re-measure if it ever moves.
#
# `wizer.initialize` is the pre-init entrypoint consumed in step 6 (it calls the
# guest's ensure_compiler); Wizer strips the export from the snapshot.
#
# `-O2` is the measured setting, not an unexamined default. Medians over 25
# iterations, variants interleaved in one process so machine drift hits them
# equally: `-O3` is SLOWER on every path this guest is used for -- shared check
# 52.9 -> 55.8 ms, shared analyze 68.8 -> 72.1 ms, shared eval 63.8 -> 65.5 ms,
# a fixed CPU-bound eval 69.0 -> 73.7 ms, isolated check 182.8 -> 190.1 ms --
# and its larger frames cost recursion headroom, which is what actually bounds
# this guest: at maxStack = 2 MiB the deepest completing `f(n)` falls 3538 ->
# 3117, nested parens 318 -> 280, the generic chain 233 -> 205, schema nesting
# 204 -> 180. Adding `-flto` (at either level) lands within run-to-run noise and
# is not free either: the LTO link extracts libc's `__stack_chk_fail.o` as a
# possible libcall, and that object's constructor seeds the stack guard from
# `random_get` -- so the guest acquires a sixth WASI import, which the
# deterministic five-import linker in wizen/ does not answer and Wizer then
# fails on. Both variants rebuild byte-identically, and this guest's suites
# pass at -O3 -- so they were rejected on the numbers above, not on principle.
# (The plain QuickJS guest's suites do NOT pass at -O3: see its build.sh.)
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
    -Wl,-z,stack-size=4194304 -Wl,--stack-first \
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

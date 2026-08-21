#!/usr/bin/env bash
# Build the QuickJS guest to wasm with the WASI SDK (reactor mode).
#
#   WASI_SDK=/opt/wasi-sdk ./build.sh
#
# Downloads quickjs-ng sources (not vendored) and compiles them + the shim into
# tests/wasm/quickjs_guest.wasm. Requires a WASI SDK: https://github.com/WebAssembly/wasi-sdk
#
# The quickjs-ng pin, its checksum, and the fetch itself live in
# ../pinned-sources.sh — a *shared* pin with the TypeScript guest, because that
# guest embeds the TypeScript compiler as qjsc bytecode and the bytecode format
# is version-locked (v0.15.1 emitted BC_VERSION 26, v0.16.2 emits 27), so the
# two must move together. Whichever way the sources arrive (release tarball, or
# a git clone when a proxy 403s the codeload redirect), the extracted tree is
# verified against the pinned digest before anything is compiled.
set -euo pipefail

WASI_SDK="${WASI_SDK:-/opt/wasi-sdk}"
HERE="$(cd "$(dirname "$0")" && pwd)"
# shellcheck source=../pinned-sources.sh
. "$HERE/../pinned-sources.sh"
BUILD="$HERE/build"
QJS="$BUILD/quickjs-${QJS_VERSION}"

CLANG="$WASI_SDK/bin/clang"
[ -x "$CLANG" ] || { echo "WASI SDK clang not found at $CLANG (set WASI_SDK)"; exit 1; }

mkdir -p "$BUILD"
fetch_quickjs "$QJS"

echo "Compiling quickjs_guest.wasm ..."
"$CLANG" \
    --target=wasm32-wasip1 -mexec-model=reactor \
    -O2 -DNDEBUG \
    -I"$QJS" \
    "$HERE/quickjs_guest.c" \
    "$QJS/quickjs.c" "$QJS/libregexp.c" "$QJS/libunicode.c" "$QJS/dtoa.c" \
    -lm \
    -Wl,--export=eval -Wl,--export=guest_alloc -Wl,--export=check \
    -Wl,-z,stack-size=1048576 \
    -o "$HERE/quickjs_guest.wasm"

cp "$HERE/quickjs_guest.wasm" "$HERE/../../tests/wasm/quickjs_guest.wasm"
ls -la "$HERE/../../tests/wasm/quickjs_guest.wasm"
echo "done."

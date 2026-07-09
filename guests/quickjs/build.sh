#!/usr/bin/env bash
# Build the QuickJS guest to wasm with the WASI SDK (reactor mode).
#
#   WASI_SDK=/opt/wasi-sdk ./build.sh
#
# Downloads quickjs-ng sources (not vendored) and compiles them + the shim into
# tests/wasm/quickjs_guest.wasm. Requires a WASI SDK: https://github.com/WebAssembly/wasi-sdk
set -euo pipefail

WASI_SDK="${WASI_SDK:-/opt/wasi-sdk}"
QJS_VERSION="${QJS_VERSION:-v0.15.1}"
HERE="$(cd "$(dirname "$0")" && pwd)"
BUILD="$HERE/build"
QJS="$BUILD/quickjs-${QJS_VERSION}"

CLANG="$WASI_SDK/bin/clang"
[ -x "$CLANG" ] || { echo "WASI SDK clang not found at $CLANG (set WASI_SDK)"; exit 1; }

if [ ! -d "$QJS" ]; then
    echo "Fetching quickjs-ng $QJS_VERSION ..."
    mkdir -p "$QJS"
    curl -fsSL "https://github.com/quickjs-ng/quickjs/archive/refs/tags/${QJS_VERSION}.tar.gz" \
        | tar xz -C "$QJS" --strip-components=1
fi

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

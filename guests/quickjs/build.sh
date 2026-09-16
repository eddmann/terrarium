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

# `-Wl,-z,stack-size` sizes the linear-memory shadow stack; `--stack-first`
# places it at the BOTTOM of linear memory, below the static data.
#
# Placement is load-bearing here, not just tidiness -- this stack is reachable.
# QuickJS recurses in C for each JS call and cannot guard itself (its
# `js_check_stack_overflow` is compiled out on wasi; see quickjs_guest.c), so
# 1 MiB runs out around recursion depth 3100, well under the 2 MiB native
# ceiling Wasmtime caps `maxStack` at -- i.e. before `call stack exhausted`
# would ever fire. The shadow stack grows DOWNWARDS, so in the default layout
# (data, then stack, then heap) the overflow keeps going straight into the
# ~106 KB of static data below it: measured, the guest ran ~220 further frames,
# scribbling ~72 KB over the module's constants and globals, and only then hit
# an address that wrapped out of bounds -- all of it silent, and all of it on
# state a shared-mode instance reuses for the next eval. Stack-first puts the
# stack at the bottom of memory with nothing below, so the first frame past the
# end is itself out of bounds: one deterministic trap, at the depth that
# overflowed, nothing written.
#
# `-O2` is the measured setting here too (the TypeScript guest's build.sh has
# the full table). On a CPU-bound eval -- an integer loop, string building and
# an object-allocation loop -- `-O3` costs 5% (60.3 -> 63.2 ms, medians over 25
# interleaved iterations) and grows the frames enough to lose recursion that
# used to fit: at `maxStack` = 64 KiB `f(100)` completes at -O2 and traps at
# -O3, which is exactly what tests/php/13_shared_engine.php and
# 14_precompiled.php assert. `-flto` is within noise and pulls a `random_get`
# import in via libc's stack-guard constructor. Neither is adopted.
echo "Compiling quickjs_guest.wasm ..."
"$CLANG" \
    --target=wasm32-wasip1 -mexec-model=reactor \
    -O2 -DNDEBUG \
    -I"$QJS" \
    "$HERE/quickjs_guest.c" \
    "$QJS/quickjs.c" "$QJS/libregexp.c" "$QJS/libunicode.c" "$QJS/dtoa.c" \
    -lm \
    -Wl,--export=eval -Wl,--export=guest_alloc -Wl,--export=check \
    -Wl,-z,stack-size=1048576 -Wl,--stack-first \
    -o "$HERE/quickjs_guest.wasm"

cp "$HERE/quickjs_guest.wasm" "$HERE/../../tests/wasm/quickjs_guest.wasm"
ls -la "$HERE/../../tests/wasm/quickjs_guest.wasm"
echo "done."

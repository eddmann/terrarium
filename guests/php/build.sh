#!/usr/bin/env bash
# Build the PHP guest to wasm with the WASI SDK (reactor mode).
#
#   WASI_SDK=/opt/wasi-sdk ./build.sh
#
# Cross-compiles a *minimal* php-src (Zend + core only, embed SAPI, no bundled
# extensions) to wasm32-wasi, then links the php_guest.c shim against the static
# libphp into tests/wasm/php_guest.wasm. Requires a WASI SDK:
# https://github.com/WebAssembly/wasi-sdk
#
# STATUS: scaffold. Cross-building php-src to WASI is iterative -- the configure
# cache seed below and the emulated-libc / setjmp-longjmp flags are the usual
# sticking points and may need extending for a given php-src version. See the
# VMware Wasm Labs port for reference:
# https://wasmlabs.dev/articles/php-wasm32-wasi-port/
set -euo pipefail

WASI_SDK="${WASI_SDK:-/opt/wasi-sdk}"
PHP_VERSION="${PHP_VERSION:-8.3.14}"
HERE="$(cd "$(dirname "$0")" && pwd)"
BUILD="$HERE/build"
PHP_SRC="$BUILD/php-src-php-${PHP_VERSION}"

CLANG="$WASI_SDK/bin/clang"
SYSROOT="$WASI_SDK/share/wasi-sysroot"
[ -x "$CLANG" ] || { echo "WASI SDK clang not found at $CLANG (set WASI_SDK)"; exit 1; }

# php-src's parser needs bison >= 3; macOS ships 2.3. Prefer a Homebrew keg.
if [ -x /opt/homebrew/opt/bison/bin/bison ]; then
    export PATH="/opt/homebrew/opt/bison/bin:$PATH"
fi

# WASI needs libc features PHP assumes exist; the wasi-libc emulated variants
# cover the common ones. setjmp/longjmp (PHP's zend_bailout) is lowered by LLVM.
TARGET_FLAGS="--target=wasm32-wasi --sysroot=$SYSROOT"
EMU_DEFS="-D_WASI_EMULATED_SIGNAL -D_WASI_EMULATED_MMAN -D_WASI_EMULATED_PROCESS_CLOCKS -D_WASI_EMULATED_GETPID"
# -lsetjmp: wasi-libc's setjmp/longjmp runtime (__wasm_setjmp & friends), the
# other half of the -wasm-enable-sjlj lowering PHP's zend_bailout depends on.
EMU_LIBS="-lwasi-emulated-signal -lwasi-emulated-mman -lwasi-emulated-process-clocks -lwasi-emulated-getpid -lsetjmp"
# -wasm-use-legacy-eh=false: emit the *standardized* exception-handling
# encoding (try_table/exnref), which Wasmtime implements -- the default legacy
# encoding parses but does not execute there.
SJLJ="-mllvm -wasm-enable-sjlj -mllvm -wasm-use-legacy-eh=false"
# wasi-shims/ provides a ucontext.h so php-src's fiber backend compiles (the
# switching functions are stubbed in fiber_stub.c; fibers are unsupported at
# runtime -- a sandboxed guest doesn't use them).
CFLAGS_WASM="$TARGET_FLAGS $EMU_DEFS $SJLJ -O2 -DNDEBUG -D_GNU_SOURCE -I$HERE/wasi-shims -include $HERE/wasi-shims/terrarium_wasi_compat.h"

if [ ! -d "$PHP_SRC" ]; then
    echo "Fetching php-src $PHP_VERSION ..."
    mkdir -p "$BUILD"
    curl -fsSL "https://github.com/php/php-src/archive/refs/tags/php-${PHP_VERSION}.tar.gz" \
        | tar xz -C "$BUILD"
fi

cd "$PHP_SRC"

# Cross-compile cache: configure runs test programs it can't execute under WASI,
# so seed the answers it would otherwise probe for. Extend as configure demands.
CACHE="$BUILD/wasi-config.cache"
cat > "$CACHE" <<'EOF'
ac_cv_func_fork=no
ac_cv_func_exec=no
ac_cv_func_setpgid=no
ac_cv_func_getpgid=no
ac_cv_func_getpid=yes
ac_cv_func_kill=no
ac_cv_func_mmap=yes
ac_cv_func_usleep=yes
ac_cv_func_nanosleep=yes
ac_cv_func_getrusage=no
ac_cv_func_gettimeofday=yes
ac_cv_func_utime_null=yes
ac_cv_func_flock=no
ac_cv_have_broken_getcwd=no
php_cv_have_flush_io=no
ac_cv_header_ucontext_h=yes
ac_cv_header_sys_un_h=yes
EOF

if [ ! -f configure ]; then
    ./buildconf --force
fi

# Skip configure on re-runs (it's slow and the flags are stable); `rm Makefile`
# in the build dir to force a reconfigure after changing configure options.
if [ ! -f Makefile ]; then
    echo "Configuring minimal php-src for wasm32-wasi ..."
    CC="$CLANG" CXX="$WASI_SDK/bin/clang++" \
    AR="$WASI_SDK/bin/llvm-ar" RANLIB="$WASI_SDK/bin/llvm-ranlib" \
    CFLAGS="$CFLAGS_WASM" LDFLAGS="$TARGET_FLAGS $EMU_LIBS" \
    ./configure \
        --host=wasm32-wasi --target=wasm32-wasi \
        --cache-file="$CACHE" \
        --disable-all \
        --enable-embed=static \
        --disable-cli --disable-cgi --disable-phpdbg \
        --without-pcre-jit --with-pcre-jit=no \
        --disable-opcache \
        --without-iconv \
        --disable-phar \
        --disable-mbregex \
        --disable-zend-signals \
        --without-valgrind
fi

echo "Building libphp ..."
make -j"$(getconf _NPROCESSORS_ONLN 2>/dev/null || echo 4)" \
    EXTRA_CFLAGS="$CFLAGS_WASM" \
    libphp.la

# Locate the produced static archive (path varies by php version/libtool).
LIBPHP="$(find "$PHP_SRC" -name 'libphp.a' -o -name 'libphp*.a' | head -1)"
[ -n "$LIBPHP" ] || { echo "libphp.a not found -- check the configure/make output"; exit 1; }

echo "Linking php_guest.wasm ..."
"$CLANG" \
    $CFLAGS_WASM -mexec-model=reactor \
    -I"$PHP_SRC" -I"$PHP_SRC/main" -I"$PHP_SRC/Zend" -I"$PHP_SRC/TSRM" \
    -I"$PHP_SRC/sapi/embed" \
    "$HERE/php_guest.c" \
    "$HERE/fiber_stub.c" \
    "$HERE/posix_stub.c" \
    "$LIBPHP" \
    $EMU_LIBS \
    -Wl,--export=eval -Wl,--export=guest_alloc -Wl,--export=check \
    -Wl,-z,stack-size=1048576 \
    -Wl,--error-limit=0 \
    -o "$HERE/php_guest.wasm"

cp "$HERE/php_guest.wasm" "$HERE/../../tests/wasm/php_guest.wasm"
ls -la "$HERE/../../tests/wasm/php_guest.wasm"
echo "done."

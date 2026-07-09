/*
 * Fiber backend stubs for the wasm32-wasi PHP guest.
 *
 * php-src builds against the ucontext fiber backend (see wasi-shims/ucontext.h),
 * but wasm cannot switch stacks without Asyncify. `getcontext` is a successful
 * no-op (PHP captures the main context at startup and never resumes it here);
 * `makecontext`/`swapcontext` are only reached if guest code actually runs a
 * Fiber, which is unsupported -- so they abort rather than corrupt state.
 */
#include <ucontext.h>
#include <stdlib.h>

int getcontext(ucontext_t *ucp) {
    (void)ucp;
    return 0;
}

void makecontext(ucontext_t *ucp, void (*func)(void), int argc, ...) {
    (void)ucp;
    (void)func;
    (void)argc;
}

int swapcontext(ucontext_t *oucp, const ucontext_t *ucp) {
    (void)oucp;
    (void)ucp;
    /* Reaching here means the guest used a PHP Fiber, unsupported on wasm. */
    abort();
}

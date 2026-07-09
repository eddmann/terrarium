/*
 * Minimal ucontext.h shim for the wasm32-wasi PHP guest.
 *
 * wasi-libc has no ucontext (no stack switching), so php-src's fiber backend
 * detection fails. We provide just enough for Zend/zend_fibers.c to compile
 * against the ucontext backend; the actual switching functions are stubbed in
 * fiber_stub.c. Fibers therefore *exist* but are unsupported at runtime -- a
 * sandboxed SDK-calling guest does not use them (real fibers need Asyncify).
 *
 * The stack struct is inlined (not a named `stack_t`) to avoid clashing with
 * any wasi-libc / emulated-signal definition.
 */
#ifndef TERRARIUM_UCONTEXT_SHIM_H
#define TERRARIUM_UCONTEXT_SHIM_H

#include <stddef.h>

typedef struct __terrarium_ucontext {
    struct __terrarium_ucontext *uc_link;
    struct {
        void  *ss_sp;
        size_t ss_size;
        int    ss_flags;
    } uc_stack;
    /* Opaque machine context; unused (no real switching on wasm). */
    void *uc_mcontext[32];
} ucontext_t;

int  getcontext(ucontext_t *ucp);
void makecontext(ucontext_t *ucp, void (*func)(void), int argc, ...);
int  swapcontext(ucontext_t *oucp, const ucontext_t *ucp);

#endif /* TERRARIUM_UCONTEXT_SHIM_H */

/* <sys/un.h> shim for the wasm32-wasi PHP guest. wasi-libc guards its real
 * sockaddr_un behind __wasilibc_unmodified_upstream (no AF_UNIX sockets on
 * wasip1), so we provide the full struct. AF_UNIX sockets don't work at runtime
 * (see posix_stub.c) -- a sandboxed guest never opens one. */
#ifndef TERRARIUM_SYS_UN_H
#define TERRARIUM_SYS_UN_H
#include <sys/socket.h>   /* sa_family_t */
#include <string.h>       /* strlen (SUN_LEN) */

struct sockaddr_un {
    sa_family_t sun_family;
    char        sun_path[108];
};

#ifndef SUN_LEN
#define SUN_LEN(ptr) ((size_t)(((struct sockaddr_un *)0)->sun_path) + strlen((ptr)->sun_path))
#endif

#endif

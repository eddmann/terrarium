/* Minimal <sys/wait.h> shim for the wasm32-wasi PHP guest. wasi has no
 * processes; wait/waitpid are stubbed (see posix_stub.c) and fail. */
#ifndef TERRARIUM_SYS_WAIT_H
#define TERRARIUM_SYS_WAIT_H
#include <sys/types.h>

#define WNOHANG   1
#define WUNTRACED 2

#define WIFEXITED(s)   (((s) & 0x7f) == 0)
#define WEXITSTATUS(s) (((s) >> 8) & 0xff)
#define WIFSIGNALED(s) (((s) & 0x7f) != 0 && ((s) & 0x7f) != 0x7f)
#define WTERMSIG(s)    ((s) & 0x7f)
#define WIFSTOPPED(s)  (((s) & 0xff) == 0x7f)
#define WSTOPSIG(s)    WEXITSTATUS(s)

pid_t wait(int *status);
pid_t waitpid(pid_t pid, int *status, int options);

#endif

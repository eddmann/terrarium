/*
 * Force-included (-include) into every php-src translation unit.
 *
 * Declares the POSIX functions php-src assumes unconditionally but that
 * wasi-libc's <unistd.h>/<stdio.h> do not declare (users/groups, process,
 * pipes). They are stubbed in posix_stub.c -- a sandboxed guest never calls
 * them; if it does, they fail cleanly rather than corrupt state.
 */
#ifndef TERRARIUM_WASI_COMPAT_H
#define TERRARIUM_WASI_COMPAT_H

#include <sys/types.h>
#include <stdio.h>
#include <sys/socket.h>   /* constants (SO_ERROR, MSG_OOB, AF_*); wasi hides the fns below on wasip1 */

/* PF_* protocol-family aliases wasi omits. */
#ifndef PF_UNIX
#define PF_UNIX  AF_UNIX
#endif
#ifndef PF_INET
#define PF_INET  AF_INET
#endif
#ifndef PF_INET6
#define PF_INET6 AF_INET6
#endif

/* Socket options/levels/flags: wasi-libc guards these behind
 * __wasilibc_unmodified_upstream on wasip1 (along with the functions), so we
 * supply the standard Linux values. Runtime behaviour is irrelevant -- every
 * socket call is an ENOSYS stub (posix_stub.c). */
#ifndef SOL_SOCKET
#define SOL_SOCKET   1
#endif
#ifndef SOL_IP
#define SOL_IP       0
#endif
#ifndef SOL_TCP
#define SOL_TCP      6
#endif
#ifndef SO_DEBUG
#define SO_DEBUG     1
#define SO_REUSEADDR 2
#define SO_TYPE      3
#define SO_ERROR     4
#define SO_DONTROUTE 5
#define SO_BROADCAST 6
#define SO_SNDBUF    7
#define SO_RCVBUF    8
#define SO_KEEPALIVE 9
#define SO_OOBINLINE 10
#define SO_LINGER    13
#endif
#ifndef SO_RCVTIMEO
#define SO_RCVTIMEO  20
#define SO_SNDTIMEO  21
#endif
#ifndef TCP_NODELAY
#define TCP_NODELAY  1
#endif
#ifndef MSG_OOB
#define MSG_OOB      0x0001
#endif
#ifndef MSG_PEEK
#define MSG_PEEK     0x0002
#endif
#ifndef MSG_DONTWAIT
#define MSG_DONTWAIT 0x0040
#endif
#ifndef MSG_WAITALL
#define MSG_WAITALL  0x0100
#endif
#ifndef MSG_NOSIGNAL
#define MSG_NOSIGNAL 0x4000
#endif

/* fcntl advisory-locking cmds/types: wasi has `struct flock` but not these
 * values (its fcntl() ignores them at runtime). Standard Linux values. */
#ifndef F_GETLK
#define F_GETLK  5
#define F_SETLK  6
#define F_SETLKW 7
#endif
#ifndef F_RDLCK
#define F_RDLCK  0
#define F_WRLCK  1
#define F_UNLCK  2
#endif

#ifdef __cplusplus
extern "C" {
#endif

mode_t umask(mode_t mask);

uid_t getuid(void);
uid_t geteuid(void);
gid_t getgid(void);
gid_t getegid(void);
int   getgroups(int size, gid_t list[]);

int   chown(const char *path, uid_t owner, gid_t group);
int   lchown(const char *path, uid_t owner, gid_t group);
int   fchown(int fd, uid_t owner, gid_t group);

FILE *popen(const char *command, const char *type);
int   pclose(FILE *stream);

pid_t fork(void);

int dup(int oldfd);
int dup2(int oldfd, int newfd);
int getdtablesize(void);
int pipe(int pipefd[2]);

char *mktemp(char *template_);

/* BSD sockets: wasi-libc declares these only for non-wasip1 targets, so on our
 * wasip1 target they are hidden. Declared here and weak-stubbed (posix_stub.c);
 * a sandboxed guest opens no sockets. */
int socket(int domain, int type, int protocol);
int socketpair(int domain, int type, int protocol, int sv[2]);
int connect(int fd, const struct sockaddr *addr, socklen_t len);
int bind(int fd, const struct sockaddr *addr, socklen_t len);
int listen(int fd, int backlog);
int accept(int fd, struct sockaddr *addr, socklen_t *len);
int setsockopt(int fd, int level, int optname, const void *optval, socklen_t optlen);
int getsockopt(int fd, int level, int optname, void *optval, socklen_t *optlen);
int getpeername(int fd, struct sockaddr *addr, socklen_t *len);
int getsockname(int fd, struct sockaddr *addr, socklen_t *len);
ssize_t send(int fd, const void *buf, size_t n, int flags);
ssize_t recv(int fd, void *buf, size_t n, int flags);
ssize_t sendto(int fd, const void *buf, size_t n, int flags, const struct sockaddr *addr, socklen_t alen);
ssize_t recvfrom(int fd, void *buf, size_t n, int flags, struct sockaddr *addr, socklen_t *alen);
int shutdown(int fd, int how);

#ifdef __cplusplus
}
#endif

#endif

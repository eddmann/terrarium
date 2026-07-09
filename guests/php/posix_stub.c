/*
 * POSIX stubs for the wasm32-wasi PHP guest.
 *
 * php-src references users/groups/process/pipe functions that wasi-libc omits.
 * These weak stubs satisfy the link; `weak` means any real wasi-libc symbol
 * wins if a future sysroot provides one. A sandboxed SDK-calling guest never
 * exercises these paths -- they fail cleanly (not found / ENOSYS) if reached.
 */
#include <sys/types.h>
#include <stdio.h>
#include <stddef.h>
#include <errno.h>
#include <pwd.h>
#include <grp.h>
#include <netdb.h>
#include <syslog.h>
#include <stdarg.h>

#define WEAK __attribute__((weak))

/* --- users / groups --------------------------------------------------- */
WEAK uid_t getuid(void)  { return 0; }
WEAK uid_t geteuid(void) { return 0; }
WEAK gid_t getgid(void)  { return 0; }
WEAK gid_t getegid(void) { return 0; }
WEAK int getgroups(int size, gid_t list[]) { (void)size; (void)list; return 0; }

WEAK struct passwd *getpwnam(const char *name) { (void)name; return NULL; }
WEAK struct passwd *getpwuid(uid_t uid)         { (void)uid;  return NULL; }
WEAK int getpwnam_r(const char *name, struct passwd *pwd, char *buf, size_t buflen, struct passwd **result) {
    (void)name; (void)pwd; (void)buf; (void)buflen; if (result) *result = NULL; return 0;
}
WEAK int getpwuid_r(uid_t uid, struct passwd *pwd, char *buf, size_t buflen, struct passwd **result) {
    (void)uid; (void)pwd; (void)buf; (void)buflen; if (result) *result = NULL; return 0;
}

WEAK struct group *getgrnam(const char *name) { (void)name; return NULL; }
WEAK struct group *getgrgid(gid_t gid)         { (void)gid;  return NULL; }
WEAK int getgrnam_r(const char *name, struct group *grp, char *buf, size_t buflen, struct group **result) {
    (void)name; (void)grp; (void)buf; (void)buflen; if (result) *result = NULL; return 0;
}
WEAK int getgrgid_r(gid_t gid, struct group *grp, char *buf, size_t buflen, struct group **result) {
    (void)gid; (void)grp; (void)buf; (void)buflen; if (result) *result = NULL; return 0;
}

/* --- ownership -------------------------------------------------------- */
WEAK int chown(const char *path, uid_t owner, gid_t group)  { (void)path; (void)owner; (void)group; errno = ENOSYS; return -1; }
WEAK int lchown(const char *path, uid_t owner, gid_t group) { (void)path; (void)owner; (void)group; errno = ENOSYS; return -1; }
WEAK int fchown(int fd, uid_t owner, gid_t group)           { (void)fd;   (void)owner; (void)group; errno = ENOSYS; return -1; }

/* --- syslog (no system log in a wasm guest) ---------------------------- */
WEAK void openlog(const char *ident, int option, int facility) { (void)ident; (void)option; (void)facility; }
WEAK void closelog(void) {}
WEAK void syslog(int priority, const char *format, ...) { (void)priority; (void)format; }
WEAK void vsyslog(int priority, const char *format, va_list ap) { (void)priority; (void)format; (void)ap; }
WEAK int  setlogmask(int mask) { (void)mask; return 0; }

/* --- misc fs ----------------------------------------------------------- */
WEAK mode_t umask(mode_t mask) { (void)mask; return 0; }
WEAK int madvise(void *addr, size_t length, int advice) { (void)addr; (void)length; (void)advice; return 0; }
WEAK char *mktemp(char *template_) {
    /* Insecure-by-design API; wasi-libc omits it. PHP only uses it as a
     * fallback when mkstemp is missing (it isn't), but the call must link. */
    if (template_) *template_ = '\0';
    return template_;
}
WEAK int dup(int oldfd)             { (void)oldfd; errno = ENOSYS; return -1; }
WEAK int dup2(int oldfd, int newfd) { (void)oldfd; (void)newfd; errno = ENOSYS; return -1; }
WEAK int getdtablesize(void)        { return 1024; }
WEAK int pipe(int pipefd[2])        { (void)pipefd; errno = ENOSYS; return -1; }

/* --- dynamic loading (no shared objects inside a wasm module) --------- */
WEAK void *dlopen(const char *file, int mode) { (void)file; (void)mode; return NULL; }
WEAK void *dlsym(void *handle, const char *name) { (void)handle; (void)name; return NULL; }
WEAK int   dlclose(void *handle) { (void)handle; return -1; }
WEAK char *dlerror(void) { return "dynamic loading is unavailable in a wasm guest"; }

/* --- process / pipes -------------------------------------------------- */
WEAK FILE *popen(const char *command, const char *type) { (void)command; (void)type; errno = ENOSYS; return NULL; }
WEAK int   pclose(FILE *stream)                         { (void)stream; return -1; }
WEAK pid_t fork(void)                                   { errno = ENOSYS; return -1; }
WEAK pid_t wait(int *status)                            { (void)status; errno = ENOSYS; return -1; }
WEAK pid_t waitpid(pid_t pid, int *status, int options) { (void)pid; (void)status; (void)options; errno = ENOSYS; return -1; }

/* --- name resolution -------------------------------------------------- */
WEAK struct hostent  *gethostbyname(const char *name)                              { (void)name; return NULL; }
WEAK struct hostent  *gethostbyaddr(const void *addr, socklen_t len, int type)     { (void)addr; (void)len; (void)type; return NULL; }
WEAK struct servent  *getservbyname(const char *name, const char *proto)           { (void)name; (void)proto; return NULL; }
WEAK struct servent  *getservbyport(int port, const char *proto)                   { (void)port; (void)proto; return NULL; }
WEAK struct protoent *getprotobyname(const char *name)                             { (void)name; return NULL; }
WEAK struct protoent *getprotobynumber(int proto)                                  { (void)proto; return NULL; }
WEAK int getaddrinfo(const char *node, const char *service, const struct addrinfo *hints, struct addrinfo **res) {
    (void)node; (void)service; (void)hints; if (res) *res = NULL; return EAI_FAIL;
}
WEAK void freeaddrinfo(struct addrinfo *res) { (void)res; }
WEAK const char *gai_strerror(int errcode)   { (void)errcode; return "name resolution unavailable"; }
WEAK int getnameinfo(const struct sockaddr *sa, socklen_t salen, char *host, socklen_t hostlen,
                     char *serv, socklen_t servlen, int flags) {
    (void)sa; (void)salen; (void)host; (void)hostlen; (void)serv; (void)servlen; (void)flags;
    return EAI_FAIL;
}

/* --- BSD sockets (absent on wasip1) ----------------------------------- */
WEAK int socket(int d, int t, int p)                          { (void)d;(void)t;(void)p; errno = ENOSYS; return -1; }
WEAK int socketpair(int d, int t, int p, int sv[2])           { (void)d;(void)t;(void)p;(void)sv; errno = ENOSYS; return -1; }
WEAK int connect(int f, const struct sockaddr *a, socklen_t l){ (void)f;(void)a;(void)l; errno = ENOSYS; return -1; }
WEAK int bind(int f, const struct sockaddr *a, socklen_t l)   { (void)f;(void)a;(void)l; errno = ENOSYS; return -1; }
WEAK int listen(int f, int b)                                 { (void)f;(void)b; errno = ENOSYS; return -1; }
WEAK int accept(int f, struct sockaddr *a, socklen_t *l)      { (void)f;(void)a;(void)l; errno = ENOSYS; return -1; }
WEAK int setsockopt(int f, int lv, int o, const void *v, socklen_t l) { (void)f;(void)lv;(void)o;(void)v;(void)l; errno = ENOSYS; return -1; }
WEAK int getsockopt(int f, int lv, int o, void *v, socklen_t *l)      { (void)f;(void)lv;(void)o;(void)v;(void)l; errno = ENOSYS; return -1; }
WEAK int getpeername(int f, struct sockaddr *a, socklen_t *l) { (void)f;(void)a;(void)l; errno = ENOSYS; return -1; }
WEAK int getsockname(int f, struct sockaddr *a, socklen_t *l) { (void)f;(void)a;(void)l; errno = ENOSYS; return -1; }
WEAK ssize_t send(int f, const void *b, size_t n, int fl)     { (void)f;(void)b;(void)n;(void)fl; errno = ENOSYS; return -1; }
WEAK ssize_t recv(int f, void *b, size_t n, int fl)          { (void)f;(void)b;(void)n;(void)fl; errno = ENOSYS; return -1; }
WEAK ssize_t sendto(int f, const void *b, size_t n, int fl, const struct sockaddr *a, socklen_t al) { (void)f;(void)b;(void)n;(void)fl;(void)a;(void)al; errno = ENOSYS; return -1; }
WEAK ssize_t recvfrom(int f, void *b, size_t n, int fl, struct sockaddr *a, socklen_t *al)          { (void)f;(void)b;(void)n;(void)fl;(void)a;(void)al; errno = ENOSYS; return -1; }
WEAK int shutdown(int f, int h)                               { (void)f;(void)h; errno = ENOSYS; return -1; }

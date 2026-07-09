/* Minimal <pwd.h> shim for the wasm32-wasi PHP guest. wasi has no users; the
 * lookups are stubbed (see posix_stub.c) and return "not found". */
#ifndef TERRARIUM_PWD_H
#define TERRARIUM_PWD_H
#include <sys/types.h>

struct passwd {
    char  *pw_name;
    char  *pw_passwd;
    uid_t  pw_uid;
    gid_t  pw_gid;
    char  *pw_gecos;
    char  *pw_dir;
    char  *pw_shell;
};

struct passwd *getpwnam(const char *name);
struct passwd *getpwuid(uid_t uid);
int getpwnam_r(const char *name, struct passwd *pwd, char *buf, size_t buflen, struct passwd **result);
int getpwuid_r(uid_t uid, struct passwd *pwd, char *buf, size_t buflen, struct passwd **result);

#endif

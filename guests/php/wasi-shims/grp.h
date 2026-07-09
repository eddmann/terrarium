/* Minimal <grp.h> shim for the wasm32-wasi PHP guest. wasi has no groups; the
 * lookups are stubbed (see posix_stub.c) and return "not found". */
#ifndef TERRARIUM_GRP_H
#define TERRARIUM_GRP_H
#include <sys/types.h>

struct group {
    char  *gr_name;
    char  *gr_passwd;
    gid_t  gr_gid;
    char **gr_mem;
};

struct group *getgrnam(const char *name);
struct group *getgrgid(gid_t gid);
int getgrnam_r(const char *name, struct group *grp, char *buf, size_t buflen, struct group **result);
int getgrgid_r(gid_t gid, struct group *grp, char *buf, size_t buflen, struct group **result);

#endif

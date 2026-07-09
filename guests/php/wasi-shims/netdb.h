/* Minimal <netdb.h> shim for the wasm32-wasi PHP guest. wasi has no name
 * resolution; the lookups are stubbed (see posix_stub.c) and fail. sys/socket.h
 * and netinet/in.h exist in the sysroot and provide the socket types. */
#ifndef TERRARIUM_NETDB_H
#define TERRARIUM_NETDB_H
#include <sys/socket.h>
#include <netinet/in.h>

struct hostent {
    char  *h_name;
    char **h_aliases;
    int    h_addrtype;
    int    h_length;
    char **h_addr_list;
};
#define h_addr h_addr_list[0]

struct servent  { char *s_name; char **s_aliases; int s_port; char *s_proto; };
struct protoent { char *p_name; char **p_aliases; int p_proto; };

struct addrinfo {
    int              ai_flags;
    int              ai_family;
    int              ai_socktype;
    int              ai_protocol;
    socklen_t        ai_addrlen;
    struct sockaddr *ai_addr;
    char            *ai_canonname;
    struct addrinfo *ai_next;
};

#define EAI_FAIL     -4
#define EAI_NONAME   -2
#define AI_CANONNAME  0x02
#define NI_MAXHOST    1025
#define NI_MAXSERV    32
#define NI_NUMERICHOST 1
#define NI_NUMERICSERV 2
#define NI_NAMEREQD    8
#define NI_DGRAM       16

struct hostent  *gethostbyname(const char *name);
struct hostent  *gethostbyaddr(const void *addr, socklen_t len, int type);
struct servent  *getservbyname(const char *name, const char *proto);
struct servent  *getservbyport(int port, const char *proto);
struct protoent *getprotobyname(const char *name);
struct protoent *getprotobynumber(int proto);
int   getaddrinfo(const char *node, const char *service, const struct addrinfo *hints, struct addrinfo **res);
void  freeaddrinfo(struct addrinfo *res);
const char *gai_strerror(int errcode);
int   getnameinfo(const struct sockaddr *sa, socklen_t salen, char *host, socklen_t hostlen,
                  char *serv, socklen_t servlen, int flags);

#endif

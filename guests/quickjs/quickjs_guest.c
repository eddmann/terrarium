/*
 * Terrarium guest: real QuickJS-ng compiled to WebAssembly.
 *
 * Same host ABI as the other guests:
 *   - exports `memory` and `guest_alloc(len) -> ptr` (host->guest writes)
 *   - exports `eval(ptr, len) -> packed` where the argument is a msgpack string
 *     (the JS source) and the result is `(retPtr << 32) | retLen` (msgpack)
 *   - imports host.host_call(name_ptr, name_len, args_ptr, args_len) -> packed
 *
 * Guest JS reaches PHP capabilities as <name>(...) via a Proxy over __host —
 * there is no synthetic php.* root; the registered top-level names are installed
 * as globals directly (queried from the host via the reserved "$names" cap).
 * QuickJS runs *inside* the wasm sandbox, so an engine memory-corruption bug
 * cannot reach the host — an isolation guarantee native embedding cannot give.
 *
 * Built with the WASI SDK in reactor mode; see build.sh.
 */
#include <stdint.h>
#include <stdlib.h>
#include <string.h>
#include <math.h>
#include "quickjs.h"

/* The one host import. */
__attribute__((import_module("host"), import_name("host_call")))
extern int64_t host_call(int32_t name_ptr, int32_t name_len,
                         int32_t args_ptr, int32_t args_len);

/* ------------------------------------------------------------------ */
/* memory contract                                                    */
/* ------------------------------------------------------------------ */

__attribute__((export_name("guest_alloc")))
void *guest_alloc(int32_t len) {
    return malloc(len < 1 ? 1 : (size_t)len);
}

static int64_t pack(void *ptr, size_t len) {
    return ((int64_t)(uint32_t)(uintptr_t)ptr << 32) | (int64_t)(uint32_t)len;
}

/* A growable byte buffer for msgpack output. */
typedef struct {
    uint8_t *data;
    size_t len, cap;
} Buf;

static void buf_put(Buf *b, const void *p, size_t n) {
    if (b->len + n > b->cap) {
        size_t cap = b->cap ? b->cap * 2 : 64;
        while (cap < b->len + n) cap *= 2;
        b->data = realloc(b->data, cap);
        b->cap = cap;
    }
    memcpy(b->data + b->len, p, n);
    b->len += n;
}
static void buf_u8(Buf *b, uint8_t v) { buf_put(b, &v, 1); }

/* ------------------------------------------------------------------ */
/* msgpack encode: JSValue -> bytes                                   */
/* ------------------------------------------------------------------ */

static void be64(uint8_t out[8], uint64_t v) {
    for (int i = 7; i >= 0; i--) { out[i] = (uint8_t)(v & 0xff); v >>= 8; }
}

static void enc_int(Buf *b, int64_t i) {
    if (i >= 0 && i <= 127) { buf_u8(b, (uint8_t)i); return; }
    if (i >= -32 && i < 0) { buf_u8(b, (uint8_t)(int8_t)i); return; }
    uint8_t tmp[8]; be64(tmp, (uint64_t)i);
    buf_u8(b, 0xd3); buf_put(b, tmp, 8);
}
static void enc_str(Buf *b, const char *s, size_t n) {
    if (n < 32) buf_u8(b, 0xa0 | (uint8_t)n);
    else { buf_u8(b, 0xdb); uint8_t t[4]; t[0]=n>>24;t[1]=n>>16;t[2]=n>>8;t[3]=n; buf_put(b, t, 4); }
    buf_put(b, s, n);
}
static void enc_arr_hdr(Buf *b, uint32_t n) {
    if (n < 16) buf_u8(b, 0x90 | (uint8_t)n);
    else { buf_u8(b, 0xdc); uint8_t t[2]; t[0]=n>>8; t[1]=n; buf_put(b, t, 2); }
}
static void enc_map_hdr(Buf *b, uint32_t n) {
    if (n < 16) buf_u8(b, 0x80 | (uint8_t)n);
    else { buf_u8(b, 0xde); uint8_t t[2]; t[0]=n>>8; t[1]=n; buf_put(b, t, 2); }
}

static void js_to_mp(JSContext *ctx, JSValueConst v, Buf *b) {
    if (JS_IsNull(v) || JS_IsUndefined(v)) { buf_u8(b, 0xc0); return; }
    if (JS_IsBool(v)) { buf_u8(b, JS_ToBool(ctx, v) ? 0xc3 : 0xc2); return; }
    if (JS_IsFunction(ctx, v)) { buf_u8(b, 0xc0); return; }
    if (JS_IsNumber(v)) {
        double d;
        JS_ToFloat64(ctx, &d, v);
        if (isfinite(d) && d == floor(d) && fabs(d) < 9007199254740992.0)
            enc_int(b, (int64_t)d);
        else { buf_u8(b, 0xcb); uint8_t t[8]; uint64_t bits; memcpy(&bits,&d,8); be64(t,bits); buf_put(b,t,8); }
        return;
    }
    if (JS_IsString(v)) {
        size_t n; const char *s = JS_ToCStringLen(ctx, &n, v);
        enc_str(b, s ? s : "", s ? n : 0);
        if (s) JS_FreeCString(ctx, s);
        return;
    }
    if (JS_IsArray(v)) {
        int64_t n = 0;
        JSValue lv = JS_GetPropertyStr(ctx, v, "length");
        JS_ToInt64(ctx, &n, lv); JS_FreeValue(ctx, lv);
        enc_arr_hdr(b, (uint32_t)n);
        for (int64_t i = 0; i < n; i++) {
            JSValue el = JS_GetPropertyUint32(ctx, v, (uint32_t)i);
            js_to_mp(ctx, el, b); JS_FreeValue(ctx, el);
        }
        return;
    }
    if (JS_IsObject(v)) {
        JSPropertyEnum *tab = NULL; uint32_t plen = 0;
        if (JS_GetOwnPropertyNames(ctx, &tab, &plen, v,
                                   JS_GPN_STRING_MASK | JS_GPN_ENUM_ONLY) < 0) {
            buf_u8(b, 0x80); return;
        }
        enc_map_hdr(b, plen);
        for (uint32_t i = 0; i < plen; i++) {
            const char *key = JS_AtomToCString(ctx, tab[i].atom);
            enc_str(b, key ? key : "", key ? strlen(key) : 0);
            if (key) JS_FreeCString(ctx, key);
            JSValue pv = JS_GetProperty(ctx, v, tab[i].atom);
            js_to_mp(ctx, pv, b); JS_FreeValue(ctx, pv);
        }
        JS_FreePropertyEnum(ctx, tab, plen);
        return;
    }
    buf_u8(b, 0xc0);
}

/* ------------------------------------------------------------------ */
/* msgpack decode: bytes -> JSValue                                   */
/* ------------------------------------------------------------------ */

typedef struct { const uint8_t *p; size_t off, len; } Rd;

static uint64_t rd_be(Rd *r, int n) {
    uint64_t v = 0;
    for (int i = 0; i < n; i++) v = (v << 8) | r->p[r->off++];
    return v;
}

static JSValue mp_to_js(JSContext *ctx, Rd *r) {
    if (r->off >= r->len) return JS_NULL;
    uint8_t m = r->p[r->off++];
    if (m <= 0x7f) return JS_NewInt64(ctx, m);
    if (m >= 0xe0) return JS_NewInt64(ctx, (int8_t)m);
    if ((m & 0xe0) == 0xa0) { /* fixstr */
        size_t n = m & 0x1f; JSValue s = JS_NewStringLen(ctx, (const char *)r->p + r->off, n);
        r->off += n; return s;
    }
    if ((m & 0xf0) == 0x90) { /* fixarray */
        uint32_t n = m & 0x0f; JSValue a = JS_NewArray(ctx);
        for (uint32_t i = 0; i < n; i++) JS_SetPropertyUint32(ctx, a, i, mp_to_js(ctx, r));
        return a;
    }
    if ((m & 0xf0) == 0x80) { /* fixmap */
        uint32_t n = m & 0x0f; JSValue o = JS_NewObject(ctx);
        for (uint32_t i = 0; i < n; i++) {
            JSValue k = mp_to_js(ctx, r);
            const char *ks = JS_ToCString(ctx, k);
            JSValue val = mp_to_js(ctx, r);
            JS_SetPropertyStr(ctx, o, ks ? ks : "", val);
            if (ks) JS_FreeCString(ctx, ks);
            JS_FreeValue(ctx, k);
        }
        return o;
    }
    switch (m) {
        case 0xc0: return JS_NULL;
        case 0xc2: return JS_NewBool(ctx, 0);
        case 0xc3: return JS_NewBool(ctx, 1);
        case 0xcc: return JS_NewInt64(ctx, rd_be(r, 1));
        case 0xcd: return JS_NewInt64(ctx, rd_be(r, 2));
        case 0xce: return JS_NewInt64(ctx, rd_be(r, 4));
        case 0xcf: return JS_NewInt64(ctx, (int64_t)rd_be(r, 8));
        case 0xd0: return JS_NewInt64(ctx, (int8_t)rd_be(r, 1));
        case 0xd1: return JS_NewInt64(ctx, (int16_t)rd_be(r, 2));
        case 0xd2: return JS_NewInt64(ctx, (int32_t)rd_be(r, 4));
        case 0xd3: return JS_NewInt64(ctx, (int64_t)rd_be(r, 8));
        case 0xca: { uint32_t bits = rd_be(r, 4); float f; memcpy(&f, &bits, 4); return JS_NewFloat64(ctx, f); }
        case 0xcb: { uint64_t bits = rd_be(r, 8); double d; memcpy(&d, &bits, 8); return JS_NewFloat64(ctx, d); }
        case 0xd9: { size_t n = rd_be(r, 1); JSValue s = JS_NewStringLen(ctx, (const char*)r->p+r->off, n); r->off += n; return s; }
        case 0xda: { size_t n = rd_be(r, 2); JSValue s = JS_NewStringLen(ctx, (const char*)r->p+r->off, n); r->off += n; return s; }
        case 0xdb: { size_t n = rd_be(r, 4); JSValue s = JS_NewStringLen(ctx, (const char*)r->p+r->off, n); r->off += n; return s; }
        case 0xdc: { uint32_t n = rd_be(r, 2); JSValue a = JS_NewArray(ctx); for (uint32_t i=0;i<n;i++) JS_SetPropertyUint32(ctx,a,i,mp_to_js(ctx,r)); return a; }
        case 0xde: { uint32_t n = rd_be(r, 2); JSValue o = JS_NewObject(ctx);
            for (uint32_t i=0;i<n;i++){ JSValue k=mp_to_js(ctx,r); const char*ks=JS_ToCString(ctx,k); JSValue v=mp_to_js(ctx,r); JS_SetPropertyStr(ctx,o,ks?ks:"",v); if(ks)JS_FreeCString(ctx,ks); JS_FreeValue(ctx,k);} return o; }
        default: return JS_NULL;
    }
}

/* ------------------------------------------------------------------ */
/* the host capability bridge                                          */
/* ------------------------------------------------------------------ */

/* __host(name, ...args) -> result, calling the host capability. */
static JSValue js_host(JSContext *ctx, JSValueConst this_val, int argc, JSValueConst *argv) {
    if (argc < 1) return JS_ThrowTypeError(ctx, "__host requires a capability name");

    size_t name_len; const char *name = JS_ToCStringLen(ctx, &name_len, argv[0]);
    if (!name) return JS_EXCEPTION;

    /* Encode the rest of the args as a msgpack array. */
    Buf ab = {0};
    enc_arr_hdr(&ab, (uint32_t)(argc - 1));
    for (int i = 1; i < argc; i++) js_to_mp(ctx, argv[i], &ab);

    int64_t packed = host_call((int32_t)(intptr_t)name, (int32_t)name_len,
                               (int32_t)(intptr_t)ab.data, (int32_t)ab.len);
    JS_FreeCString(ctx, name);
    free(ab.data);

    uint32_t rptr = (uint32_t)(packed >> 32);
    uint32_t rlen = (uint32_t)(packed & 0xffffffff);
    Rd r = { (const uint8_t *)(uintptr_t)rptr, 0, rlen };
    JSValue result = mp_to_js(ctx, &r);
    free((void *)(uintptr_t)rptr);
    return result;
}

/* Recursive facade: a.b.c(...) -> __host("a.b.c", ...). Each access deepens the
 * dotted path; calling a node invokes that capability. There is no synthetic
 * php.* root: the guest asks the host (reserved "$names" cap) which top-level
 * names are registered and installs each as a global. */
static const char PRELUDE[] =
    "function __ns(p){"
    "  return new Proxy(function(){}, {"
    "    get(_t, name){ return typeof name === 'string' ? __ns(p ? p+'.'+name : name) : undefined; },"
    "    apply(_t, _th, args){ return __host(p, ...args); }"
    "  });"
    "}"
    "for (var __n = __host('$names'), __i = 0; __i < __n.length; __i++) { globalThis[__n[__i]] = __ns(__n[__i]); }"
    "globalThis.console = (function(){"
    "  var fmt = function(x){ return (typeof x === 'object' && x !== null) ? JSON.stringify(x) : String(x); };"
    "  var emit = function(){ __host('$out', Array.prototype.slice.call(arguments).map(fmt).join(' ')); };"
    "  return { log: emit, error: emit, warn: emit, info: emit, debug: emit };"
    "})();";

/* ------------------------------------------------------------------ */
/* eval entrypoint                                                     */
/* ------------------------------------------------------------------ */

static int64_t ret_value(JSContext *ctx, JSValueConst v) {
    Buf b = {0};
    js_to_mp(ctx, v, &b);
    void *out = guest_alloc((int32_t)b.len);
    memcpy(out, b.data, b.len);
    free(b.data);
    return pack(out, b.len);
}

/* Encode the structured sentinel { "$error": {message, type?, line?} }. */
static int64_t ret_error_full(const char *msg, const char *type, int has_line, int64_t line) {
    Buf b = {0};
    enc_map_hdr(&b, 1);
    enc_str(&b, "$error", 6);
    enc_map_hdr(&b, 1 + (type ? 1u : 0u) + (has_line ? 1u : 0u));
    enc_str(&b, "message", 7);
    enc_str(&b, msg, strlen(msg));
    if (type) { enc_str(&b, "type", 4); enc_str(&b, type, strlen(type)); }
    if (has_line) { enc_str(&b, "line", 4); enc_int(&b, line); }
    void *out = guest_alloc((int32_t)b.len);
    memcpy(out, b.data, b.len);
    free(b.data);
    return pack(out, b.len);
}

/* A plain internal error (no engine type/line). ctx is unused but kept so call
 * sites read uniformly. */
static int64_t ret_error(JSContext *ctx, const char *msg) {
    (void)ctx;
    return ret_error_full(msg, NULL, 0, 0);
}

/* A thrown JS exception: pull name/message/stack off the error object and parse
 * the source line out of the stack ("...<eval>:LINE..."). */
static int64_t ret_error_exc(JSContext *ctx, JSValueConst exc) {
    JSValue jname = JS_GetPropertyStr(ctx, exc, "name");
    JSValue jmsg = JS_GetPropertyStr(ctx, exc, "message");
    JSValue jstack = JS_GetPropertyStr(ctx, exc, "stack");
    const char *name = JS_IsString(jname) ? JS_ToCString(ctx, jname) : NULL;
    const char *msg = JS_IsString(jmsg) ? JS_ToCString(ctx, jmsg) : NULL;
    const char *stack = JS_IsString(jstack) ? JS_ToCString(ctx, jstack) : NULL;

    /* Non-Error throws (e.g. `throw "x"`) lack message: fall back to String(exc). */
    const char *fallback = (msg && *msg) ? NULL : JS_ToCString(ctx, exc);

    int has_line = 0;
    int64_t line = 0;
    if (stack) {
        const char *p = strstr(stack, "<eval>:");
        if (p) {
            p += 7; /* strlen("<eval>:") */
            while (*p >= '0' && *p <= '9') { line = line * 10 + (*p - '0'); p++; has_line = 1; }
        }
    }

    int64_t out = ret_error_full(
        (msg && *msg) ? msg : (fallback ? fallback : "uncaught JS exception"),
        (name && *name) ? name : NULL,
        has_line, line);

    if (name) JS_FreeCString(ctx, name);
    if (msg) JS_FreeCString(ctx, msg);
    if (stack) JS_FreeCString(ctx, stack);
    if (fallback) JS_FreeCString(ctx, fallback);
    JS_FreeValue(ctx, jname);
    JS_FreeValue(ctx, jmsg);
    JS_FreeValue(ctx, jstack);
    return out;
}

/* Encode a msgpack diagnostics array: `[]`, or `[ {message, type?, line?} ]`. */
static int64_t ret_diags(const char *msg, const char *type, int has_line, int64_t line) {
    Buf b = {0};
    if (!msg) {
        enc_arr_hdr(&b, 0);
    } else {
        enc_arr_hdr(&b, 1);
        enc_map_hdr(&b, 1 + (type ? 1u : 0u) + (has_line ? 1u : 0u));
        enc_str(&b, "message", 7);
        enc_str(&b, msg, strlen(msg));
        if (type) { enc_str(&b, "type", 4); enc_str(&b, type, strlen(type)); }
        if (has_line) { enc_str(&b, "line", 4); enc_int(&b, line); }
    }
    void *out = guest_alloc((int32_t)b.len);
    memcpy(out, b.data, b.len);
    free(b.data);
    return pack(out, b.len);
}

/* `check(ptr, len)`: compile-only validation — the strongest static check
 * JavaScript offers. Parses+compiles the source (JS_EVAL_FLAG_COMPILE_ONLY,
 * nothing executes, no prelude installed, no capability reachable) and returns
 * a msgpack array of diagnostics; empty means "compiles". */
__attribute__((export_name("check")))
int64_t check(int32_t ptr, int32_t len) {
    JSRuntime *rt = JS_NewRuntime();
    if (!rt) return ret_diags("failed to create JS runtime", NULL, 0, 0);
    JS_SetMaxStackSize(rt, 256 * 1024);
    JSContext *ctx = JS_NewContext(rt);

    Rd r = { (const uint8_t *)(uintptr_t)(uint32_t)ptr, 0, (size_t)(uint32_t)len };
    JSValue srcv = mp_to_js(ctx, &r);
    size_t slen; const char *src = JS_ToCStringLen(ctx, &slen, srcv);

    int64_t out;
    if (!src) {
        out = ret_diags("check expects a source string", NULL, 0, 0);
    } else {
        JSValue res = JS_Eval(ctx, src, slen, "<eval>",
                              JS_EVAL_TYPE_GLOBAL | JS_EVAL_FLAG_COMPILE_ONLY);
        if (JS_IsException(res)) {
            JSValue exc = JS_GetException(ctx);
            JSValue jname = JS_GetPropertyStr(ctx, exc, "name");
            JSValue jmsg = JS_GetPropertyStr(ctx, exc, "message");
            JSValue jstack = JS_GetPropertyStr(ctx, exc, "stack");
            const char *name = JS_IsString(jname) ? JS_ToCString(ctx, jname) : NULL;
            const char *msg = JS_IsString(jmsg) ? JS_ToCString(ctx, jmsg) : NULL;
            const char *stack = JS_IsString(jstack) ? JS_ToCString(ctx, jstack) : NULL;
            int has_line = 0;
            int64_t line = 0;
            if (stack) {
                const char *p = strstr(stack, "<eval>:");
                if (p) {
                    p += 7;
                    while (*p >= '0' && *p <= '9') { line = line * 10 + (*p - '0'); p++; has_line = 1; }
                }
            }
            out = ret_diags((msg && *msg) ? msg : "compile error",
                            (name && *name) ? name : NULL, has_line, line);
            if (name) JS_FreeCString(ctx, name);
            if (msg) JS_FreeCString(ctx, msg);
            if (stack) JS_FreeCString(ctx, stack);
            JS_FreeValue(ctx, jname);
            JS_FreeValue(ctx, jmsg);
            JS_FreeValue(ctx, jstack);
            JS_FreeValue(ctx, exc);
        } else {
            out = ret_diags(NULL, NULL, 0, 0);   /* compiles: no diagnostics */
        }
        JS_FreeValue(ctx, res);
        JS_FreeCString(ctx, src);
    }
    JS_FreeValue(ctx, srcv);
    JS_FreeContext(ctx);
    JS_FreeRuntime(rt);
    return out;
}

__attribute__((export_name("eval")))
int64_t eval(int32_t ptr, int32_t len) {
    /* The argument is a msgpack string: the JS source. */
    Rd r = { (const uint8_t *)(uintptr_t)(uint32_t)ptr, 0, (size_t)(uint32_t)len };

    JSRuntime *rt = JS_NewRuntime();
    if (!rt) return ret_error(NULL, "failed to create JS runtime");
    /* A clean JS-level stack limit before the wasm stack would overflow. */
    JS_SetMaxStackSize(rt, 256 * 1024);
    JSContext *ctx = JS_NewContext(rt);

    /* Decode the source string. */
    JSValue srcv = mp_to_js(ctx, &r);
    size_t slen; const char *src = JS_ToCStringLen(ctx, &slen, srcv);

    int64_t out;
    if (!src) {
        out = ret_error(ctx, "eval expects a source string");
    } else {
        /* Install __host and the php proxy. */
        JSValue global = JS_GetGlobalObject(ctx);
        JS_SetPropertyStr(ctx, global, "__host",
                          JS_NewCFunction(ctx, js_host, "__host", 1));
        JS_FreeValue(ctx, global);
        JSValue pre = JS_Eval(ctx, PRELUDE, strlen(PRELUDE), "<prelude>", JS_EVAL_TYPE_GLOBAL);
        JS_FreeValue(ctx, pre);

        JSValue res = JS_Eval(ctx, src, slen, "<eval>", JS_EVAL_TYPE_GLOBAL);
        if (JS_IsException(res)) {
            JSValue exc = JS_GetException(ctx);
            out = ret_error_exc(ctx, exc);
            JS_FreeValue(ctx, exc);
        } else {
            out = ret_value(ctx, res);
        }
        JS_FreeValue(ctx, res);
        JS_FreeCString(ctx, src);
    }
    JS_FreeValue(ctx, srcv);
    JS_FreeContext(ctx);
    JS_FreeRuntime(rt);
    return out;
}

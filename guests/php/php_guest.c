/*
 * Terrarium guest: real PHP (php-src) compiled to WebAssembly (wasm32-wasi).
 *
 * Same host ABI as every other guest:
 *   - exports `memory` and `guest_alloc(len) -> ptr` (host->guest writes)
 *   - exports `eval(ptr, len) -> packed` where the argument is a msgpack string
 *     (the PHP source) and the result is `(retPtr << 32) | retLen` (msgpack)
 *   - imports host.host_call(name_ptr, name_len, args_ptr, args_len) -> packed
 *
 * PHP is embedded via its embed SAPI (php_embed_init / zend_eval_string /
 * php_embed_shutdown), the same way the QuickJS guest embeds QuickJS. Because
 * PHP runs *inside* the wasm sandbox, an engine memory-corruption bug cannot
 * reach the host.
 *
 * The SDK is reached by name, the PHP-idiomatic way -- there is no synthetic
 * root. The guest asks the host (reserved "$names" cap) which top-level names
 * are registered and installs each as a global proxy object:
 *
 *     $math->add(2, 3)            // -> __host("math.add", 2, 3)
 *     $api->v1->hello("Ada")      // -> __host("api.v1.hello", "Ada")
 *     $ping()                     // -> __host("ping")           (flat name)
 *     return $user->fetch(42);    // the top-level `return` is the eval result
 *
 * `echo` / `print` output is captured (SAPI ub_write) and sent to the reserved
 * "$out" cap. An uncaught PHP exception or fatal error comes back as the
 * sentinel { "$error": {message, type?, line?} } for the host to raise.
 *
 * NOTE: this is the guest shim. Building it needs php-src compiled to
 * wasm32-wasi with `--enable-embed=static`; see build.sh. Some Zend/embed API
 * details below are verified against the headers at build time.
 *
 * Built with the WASI SDK in reactor mode; see build.sh.
 */
#include <stdint.h>
#include <stdlib.h>
#include <string.h>
#include <math.h>

#include <sapi/embed/php_embed.h>
#include <Zend/zend_exceptions.h>
#include <Zend/zend_API.h>

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

/* A growable byte buffer for msgpack output (identical to the other guests). */
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
/* msgpack encode (byte helpers shared with the other guests)         */
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
static void enc_dbl(Buf *b, double d) {
    buf_u8(b, 0xcb); uint8_t t[8]; uint64_t bits; memcpy(&bits, &d, 8); be64(t, bits); buf_put(b, t, 8);
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

/* zval -> msgpack. A PHP list array (sequential 0..n-1 keys) becomes a msgpack
 * array; any other array becomes a map. Objects become a map of their public
 * properties. */
static void zval_to_mp(zval *v, Buf *b);

static void array_to_mp(zend_array *arr, Buf *b) {
    if (zend_array_is_list(arr)) {
        enc_arr_hdr(b, zend_hash_num_elements(arr));
        zval *el;
        ZEND_HASH_FOREACH_VAL(arr, el) {
            zval_to_mp(el, b);
        } ZEND_HASH_FOREACH_END();
        return;
    }
    enc_map_hdr(b, zend_hash_num_elements(arr));
    zend_string *key; zend_ulong idx; zval *el;
    ZEND_HASH_FOREACH_KEY_VAL(arr, idx, key, el) {
        if (key) {
            enc_str(b, ZSTR_VAL(key), ZSTR_LEN(key));
        } else {
            char tmp[24]; int n = snprintf(tmp, sizeof(tmp), ZEND_LONG_FMT, (zend_long)idx);
            enc_str(b, tmp, (size_t)n);
        }
        zval_to_mp(el, b);
    } ZEND_HASH_FOREACH_END();
}

static void zval_to_mp(zval *v, Buf *b) {
    ZVAL_DEREF(v);
    switch (Z_TYPE_P(v)) {
        case IS_NULL:
        case IS_UNDEF:   buf_u8(b, 0xc0); return;
        case IS_TRUE:    buf_u8(b, 0xc3); return;
        case IS_FALSE:   buf_u8(b, 0xc2); return;
        case IS_LONG:    enc_int(b, (int64_t)Z_LVAL_P(v)); return;
        case IS_DOUBLE:  enc_dbl(b, Z_DVAL_P(v)); return;
        case IS_STRING:  enc_str(b, Z_STRVAL_P(v), Z_STRLEN_P(v)); return;
        case IS_ARRAY:   array_to_mp(Z_ARRVAL_P(v), b); return;
        case IS_OBJECT: {
            zend_array *props = Z_OBJPROP_P(v);
            if (props) { array_to_mp(props, b); return; }
            buf_u8(b, 0x80); return;
        }
        default:         buf_u8(b, 0xc0); return;
    }
}

/* ------------------------------------------------------------------ */
/* msgpack decode: bytes -> zval                                      */
/* ------------------------------------------------------------------ */

typedef struct { const uint8_t *p; size_t off, len; } Rd;

static uint64_t rd_be(Rd *r, int n) {
    uint64_t v = 0;
    for (int i = 0; i < n; i++) v = (v << 8) | r->p[r->off++];
    return v;
}

static void mp_to_zval(Rd *r, zval *out);

static void mp_to_array(Rd *r, uint32_t n, zval *out) {
    array_init_size(out, n);
    for (uint32_t i = 0; i < n; i++) {
        zval el; mp_to_zval(r, &el);
        add_next_index_zval(out, &el);
    }
}
static void mp_to_map(Rd *r, uint32_t n, zval *out) {
    array_init_size(out, n);
    for (uint32_t i = 0; i < n; i++) {
        zval k; mp_to_zval(r, &k);
        zval val; mp_to_zval(r, &val);
        if (Z_TYPE(k) == IS_STRING) {
            add_assoc_zval_ex(out, Z_STRVAL(k), Z_STRLEN(k), &val);
        } else {
            convert_to_long(&k);
            add_index_zval(out, Z_LVAL(k), &val);
        }
        zval_ptr_dtor(&k);
    }
}

static void mp_to_zval(Rd *r, zval *out) {
    if (r->off >= r->len) { ZVAL_NULL(out); return; }
    uint8_t m = r->p[r->off++];
    if (m <= 0x7f) { ZVAL_LONG(out, m); return; }
    if (m >= 0xe0) { ZVAL_LONG(out, (int8_t)m); return; }
    if ((m & 0xe0) == 0xa0) { size_t n = m & 0x1f; ZVAL_STRINGL(out, (const char*)r->p+r->off, n); r->off += n; return; }
    if ((m & 0xf0) == 0x90) { mp_to_array(r, m & 0x0f, out); return; }
    if ((m & 0xf0) == 0x80) { mp_to_map(r, m & 0x0f, out); return; }
    switch (m) {
        case 0xc0: ZVAL_NULL(out); return;
        case 0xc2: ZVAL_FALSE(out); return;
        case 0xc3: ZVAL_TRUE(out); return;
        case 0xcc: ZVAL_LONG(out, rd_be(r, 1)); return;
        case 0xcd: ZVAL_LONG(out, rd_be(r, 2)); return;
        case 0xce: ZVAL_LONG(out, rd_be(r, 4)); return;
        case 0xcf: ZVAL_LONG(out, (int64_t)rd_be(r, 8)); return;
        case 0xd0: ZVAL_LONG(out, (int8_t)rd_be(r, 1)); return;
        case 0xd1: ZVAL_LONG(out, (int16_t)rd_be(r, 2)); return;
        case 0xd2: ZVAL_LONG(out, (int32_t)rd_be(r, 4)); return;
        case 0xd3: ZVAL_LONG(out, (int64_t)rd_be(r, 8)); return;
        case 0xca: { uint32_t bits = rd_be(r, 4); float f; memcpy(&f, &bits, 4); ZVAL_DOUBLE(out, f); return; }
        case 0xcb: { uint64_t bits = rd_be(r, 8); double d; memcpy(&d, &bits, 8); ZVAL_DOUBLE(out, d); return; }
        case 0xd9: { size_t n = rd_be(r, 1); ZVAL_STRINGL(out, (const char*)r->p+r->off, n); r->off += n; return; }
        case 0xda: { size_t n = rd_be(r, 2); ZVAL_STRINGL(out, (const char*)r->p+r->off, n); r->off += n; return; }
        case 0xdb: { size_t n = rd_be(r, 4); ZVAL_STRINGL(out, (const char*)r->p+r->off, n); r->off += n; return; }
        case 0xdc: { uint32_t n = rd_be(r, 2); mp_to_array(r, n, out); return; }
        case 0xde: { uint32_t n = rd_be(r, 2); mp_to_map(r, n, out); return; }
        default:   ZVAL_NULL(out); return;
    }
}

/* ------------------------------------------------------------------ */
/* the host capability bridge: __host(name, ...args) -> result        */
/* ------------------------------------------------------------------ */

PHP_FUNCTION(terrarium_host) {
    zend_string *name;
    zval *args = NULL;
    int argc = 0;

    ZEND_PARSE_PARAMETERS_START(1, -1)
        Z_PARAM_STR(name)
        Z_PARAM_VARIADIC('*', args, argc)
    ZEND_PARSE_PARAMETERS_END();

    Buf ab = {0};
    enc_arr_hdr(&ab, (uint32_t)argc);
    for (int i = 0; i < argc; i++) zval_to_mp(&args[i], &ab);

    int64_t packed = host_call((int32_t)(intptr_t)ZSTR_VAL(name), (int32_t)ZSTR_LEN(name),
                               (int32_t)(intptr_t)ab.data, (int32_t)ab.len);
    free(ab.data);

    uint32_t rptr = (uint32_t)(packed >> 32);
    uint32_t rlen = (uint32_t)(packed & 0xffffffff);
    Rd r = { (const uint8_t *)(uintptr_t)rptr, 0, rlen };
    mp_to_zval(&r, return_value);
    free((void *)(uintptr_t)rptr);
}

ZEND_BEGIN_ARG_INFO_EX(arginfo_host, 0, 0, 1)
    ZEND_ARG_INFO(0, name)
    ZEND_ARG_VARIADIC_INFO(0, args)
ZEND_END_ARG_INFO()

static const zend_function_entry terrarium_functions[] = {
    ZEND_NAMED_FE(__host, ZEND_FN(terrarium_host), arginfo_host)
    ZEND_FE_END
};

/* Recursive facade: proxy objects deepen a dotted path; calling one invokes the
 * capability. Installs each registered top-level name (from the reserved
 * "$names" cap) as a global proxy -- no synthetic root. */
static const char PRELUDE[] =
    "class __TerrariumNs {\n"
    "    public $__p;\n"
    "    function __construct($p = '') { $this->__p = $p; }\n"
    "    function __get($n) { return new __TerrariumNs($this->__p === '' ? $n : $this->__p . '.' . $n); }\n"
    "    function __call($n, $a) { return __host($this->__p === '' ? $n : $this->__p . '.' . $n, ...$a); }\n"
    "    function __invoke(...$a) { return __host($this->__p, ...$a); }\n"
    "}\n"
    "foreach (__host('$names') as $__n) { $GLOBALS[$__n] = new __TerrariumNs($__n); }\n";

/* ------------------------------------------------------------------ */
/* output capture: SAPI ub_write -> the reserved "$out" cap           */
/* ------------------------------------------------------------------ */

static Buf g_out;   /* accumulates echo/print output for the current eval */

static size_t terrarium_ub_write(const char *str, size_t len) {
    buf_put(&g_out, str, len);
    return len;
}

static void flush_output(void) {
    if (g_out.len == 0) return;
    /* host_call("$out", [ <string> ]) */
    Buf ab = {0};
    enc_arr_hdr(&ab, 1);
    enc_str(&ab, (const char *)g_out.data, g_out.len);
    const char *cap = "$out";
    host_call((int32_t)(intptr_t)cap, 4, (int32_t)(intptr_t)ab.data, (int32_t)ab.len);
    free(ab.data);
    free(g_out.data);
    g_out.data = NULL; g_out.len = g_out.cap = 0;
}

/* ------------------------------------------------------------------ */
/* result / error marshaling                                          */
/* ------------------------------------------------------------------ */

static int64_t ret_value(zval *v) {
    Buf b = {0};
    zval_to_mp(v, &b);
    void *out = guest_alloc((int32_t)b.len);
    memcpy(out, b.data, b.len);
    free(b.data);
    return pack(out, b.len);
}

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

/* Turn the pending PHP exception (if any) into the error sentinel: class name,
 * message, and line pulled off the exception object. Clears it. */
static int64_t take_exception(void) {
    zend_object *ex = EG(exception);
    if (!ex) return 0;
    zend_string *cls = ex->ce->name;

    zval rv;
    zval *zmsg = zend_read_property(ex->ce, ex, "message", sizeof("message") - 1, 1, &rv);
    zval *zline = zend_read_property(ex->ce, ex, "line", sizeof("line") - 1, 1, &rv);
    zend_string *msg = (zmsg && Z_TYPE_P(zmsg) == IS_STRING) ? Z_STR_P(zmsg) : NULL;
    int has_line = zline && Z_TYPE_P(zline) == IS_LONG;
    /* The IIFE wrapper occupies line 1; user source starts on line 2. */
    int64_t line = has_line ? (int64_t)Z_LVAL_P(zline) - 1 : 0;
    if (has_line && line < 1) line = 1;

    /* Copy strings before clearing the exception frees them. */
    char *m = msg ? strdup(ZSTR_VAL(msg)) : strdup("uncaught exception");
    char *t = strdup(ZSTR_VAL(cls));
    zend_clear_exception();

    int64_t out = ret_error_full(m, t, has_line, line);
    free(m); free(t);
    return out;
}

/* ------------------------------------------------------------------ */
/* check entrypoint (optional export — compile-only, nothing runs)    */
/* ------------------------------------------------------------------ */

static const char *raw_mp_str(const uint8_t *p, size_t len, size_t *out_len);

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

/* The pending exception (a ParseError from zend_compile_string) as a single
 * msgpack diagnostic, with the IIFE wrapper's one-line offset corrected --
 * same adjustment as take_exception(). Clears it. */
static int64_t take_exception_diags(void) {
    zend_object *ex = EG(exception);
    if (!ex) return ret_diags("compile failed", NULL, 0, 0);
    zend_string *cls = ex->ce->name;

    zval rv;
    zval *zmsg = zend_read_property(ex->ce, ex, "message", sizeof("message") - 1, 1, &rv);
    zval *zline = zend_read_property(ex->ce, ex, "line", sizeof("line") - 1, 1, &rv);
    zend_string *msg = (zmsg && Z_TYPE_P(zmsg) == IS_STRING) ? Z_STR_P(zmsg) : NULL;
    int has_line = zline && Z_TYPE_P(zline) == IS_LONG;
    int64_t line = has_line ? (int64_t)Z_LVAL_P(zline) - 1 : 0;
    if (has_line && line < 1) line = 1;

    char *m = msg ? strdup(ZSTR_VAL(msg)) : strdup("compile failed");
    char *t = strdup(ZSTR_VAL(cls));
    zend_clear_exception();

    int64_t out = ret_diags(m, t, has_line, line);
    free(m); free(t);
    return out;
}

/* `check(ptr, len)`: compile-only validation -- PHP's `php -l`, against the
 * exact wrapped form eval() executes (so reported lines match). Nothing runs:
 * no capability is registered, no user code executes. Returns a msgpack array
 * of diagnostics; empty means "compiles". */
__attribute__((export_name("check")))
int64_t check(int32_t ptr, int32_t len) {
    size_t src_len = 0;
    const char *src = raw_mp_str((const uint8_t *)(uintptr_t)(uint32_t)ptr,
                                 (size_t)(uint32_t)len, &src_len);
    if (!src) {
        return ret_diags("check expects a source string", NULL, 0, 0);
    }

    /* Same IIFE wrapper as eval() (same one-line offset), but with a trailing
     * `;` -- eval goes through zend_eval_stringl, which wraps the expression
     * itself; here we hand zend_compile_string a complete statement. */
    static const char PRE[] = "(static function () { extract($GLOBALS, EXTR_SKIP);\n";
    static const char POST[] = "\n})();";
    size_t code_len = (sizeof(PRE) - 1) + src_len + (sizeof(POST) - 1);
    char *code = malloc(code_len + 1);
    memcpy(code, PRE, sizeof(PRE) - 1);
    memcpy(code + sizeof(PRE) - 1, src, src_len);
    memcpy(code + sizeof(PRE) - 1 + src_len, POST, sizeof(POST) - 1);
    code[code_len] = '\0';

    php_embed_module.ub_write = terrarium_ub_write;
    g_out.data = NULL; g_out.len = g_out.cap = 0;

    if (php_embed_init(0, NULL) == FAILURE) {
        free(code);
        return ret_diags("failed to start PHP", NULL, 0, 0);
    }

    int64_t out;
    zend_first_try {
        zend_string *zcode = zend_string_init(code, code_len, 0);
        zend_op_array *ops = zend_compile_string(zcode, "<eval>", ZEND_COMPILE_POSITION_AFTER_OPEN_TAG);
        zend_string_release(zcode);
        if (!ops || EG(exception)) {
            out = take_exception_diags();
        } else {
            destroy_op_array(ops);
            efree(ops);
            out = ret_diags(NULL, NULL, 0, 0);   /* compiles: no diagnostics */
        }
    } zend_catch {
        out = EG(exception) ? take_exception_diags()
                            : ret_diags("fatal error during compilation", NULL, 0, 0);
    } zend_end_try();

    /* Discard anything buffered; check produces no output. */
    free(g_out.data);
    g_out.data = NULL; g_out.len = g_out.cap = 0;
    php_embed_shutdown();
    free(code);
    return out;
}

/* ------------------------------------------------------------------ */
/* eval entrypoint                                                    */
/* ------------------------------------------------------------------ */

/* Parse the incoming msgpack *string* header without touching Zend -- nothing
 * zval-ish may run before php_embed_init brings the Zend allocator up. */
static const char *raw_mp_str(const uint8_t *p, size_t len, size_t *out_len) {
    if (len == 0) return NULL;
    uint8_t m = p[0];
    if ((m & 0xe0) == 0xa0) { *out_len = m & 0x1f; return (len >= 1 + *out_len) ? (const char *)p + 1 : NULL; }
    if (m == 0xd9 && len >= 2) { *out_len = p[1]; return (len >= 2 + *out_len) ? (const char *)p + 2 : NULL; }
    if (m == 0xda && len >= 3) { *out_len = ((size_t)p[1] << 8) | p[2]; return (len >= 3 + *out_len) ? (const char *)p + 3 : NULL; }
    if (m == 0xdb && len >= 5) {
        *out_len = ((size_t)p[1] << 24) | ((size_t)p[2] << 16) | ((size_t)p[3] << 8) | p[4];
        return (len >= 5 + *out_len) ? (const char *)p + 5 : NULL;
    }
    return NULL;
}

__attribute__((export_name("eval")))
int64_t eval(int32_t ptr, int32_t len) {
    /* The argument is a msgpack string: the PHP source. Parsed raw -- PHP (and
     * therefore the Zend allocator behind zvals) is not up yet. */
    size_t src_len = 0;
    const char *src = raw_mp_str((const uint8_t *)(uintptr_t)(uint32_t)ptr,
                                 (size_t)(uint32_t)len, &src_len);
    if (!src) {
        return ret_error_full("eval expects a source string", NULL, 0, 0);
    }
    /* Wrap the source in an IIFE: `zend_eval_stringl` with a retval expects an
     * *expression* (it wraps as `return (<expr>);`), while guest programs are
     * statement sequences. `(static function () { <src> })()` is that
     * expression, and a top-level `return` inside the body naturally becomes
     * the eval result. The newline guards a trailing `// comment` in the
     * source from swallowing the closing brace. */
    /* extract($GLOBALS): the closure body has function scope, so the SDK
     * proxies the prelude installed as globals ($user, $math, ...) are pulled
     * in as locals -- the guest author sees them "at top level" as expected.
     * Kept on the wrapper's first line so user code starts on line 2 (the
     * error path subtracts that one-line offset). */
    static const char PRE[] = "(static function () { extract($GLOBALS, EXTR_SKIP);\n";
    static const char POST[] = "\n})()";
    size_t code_len = (sizeof(PRE) - 1) + src_len + (sizeof(POST) - 1);
    char *code = malloc(code_len + 1);
    memcpy(code, PRE, sizeof(PRE) - 1);
    memcpy(code + sizeof(PRE) - 1, src, src_len);
    memcpy(code + sizeof(PRE) - 1 + src_len, POST, sizeof(POST) - 1);
    code[code_len] = '\0';

    /* Route output to our buffer, then bring PHP up for this request.
     * NOTE: per-eval init/shutdown is the simple, hermetic starting point
     * (mirrors the QuickJS guest's fresh runtime per eval); module startup can
     * later be hoisted to _initialize if the MINIT cost matters. */
    php_embed_module.ub_write = terrarium_ub_write;
    g_out.data = NULL; g_out.len = g_out.cap = 0;

    int64_t out;
    if (php_embed_init(0, NULL) == FAILURE) {
        free(code);
        return ret_error_full("failed to start PHP", NULL, 0, 0);
    }

    zend_first_try {
        /* Register __host, then the by-name prelude, then run the source. */
        zend_register_functions(NULL, terrarium_functions, NULL, MODULE_PERSISTENT);
        zend_eval_string((char *)PRELUDE, NULL, "<prelude>");

        zval retval;
        ZVAL_UNDEF(&retval);
        zend_eval_stringl(code, code_len, &retval, "<eval>");

        if (EG(exception)) {
            out = take_exception();
        } else {
            out = ret_value(&retval);
        }
        zval_ptr_dtor(&retval);
    } zend_catch {
        /* A fatal error / zend_bailout unwound here. */
        out = EG(exception) ? take_exception()
                            : ret_error_full("fatal error", NULL, 0, 0);
    } zend_end_try();

    flush_output();
    php_embed_shutdown();
    free(code);
    return out;
}

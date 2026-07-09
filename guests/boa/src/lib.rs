//! A real JavaScript engine ([Boa], pure Rust) compiled to WebAssembly as a
//! Terrarium guest. It exposes `eval(source) -> value` and lets the guest JS
//! reach host capabilities as `<name>(...)` — proving the headline of the
//! design: run a wasm build of a language runtime, sandboxed, with a host
//! capability SDK and a returned value. Because the engine runs *inside* the
//! WASM sandbox, an engine memory-corruption bug cannot reach the host — an
//! isolation guarantee no natively-embedded engine can offer.
//!
//! Boa is used because it is pure Rust and so builds for
//! `wasm32-unknown-unknown` with no C/WASI toolchain (the bundled QuickJS-ng
//! guest is the WASI-toolchain counterpart, behind the *same* host ABI).
//!
//! [Boa]: https://github.com/boa-dev/boa

use boa_engine::{js_string, Context, JsValue, NativeFunction, Source};
use std::slice;
use std::sync::atomic::{AtomicU64, Ordering};

// ---------------------------------------------------------------------------
// entropy: a bare wasm guest has no OS RNG; register a deterministic backend
// ---------------------------------------------------------------------------

static RNG: AtomicU64 = AtomicU64::new(0x9e37_79b9_7f4a_7c15);
fn custom_getrandom(buf: &mut [u8]) -> Result<(), getrandom::Error> {
    for chunk in buf.chunks_mut(8) {
        let mut x = RNG.load(Ordering::Relaxed);
        x ^= x << 13;
        x ^= x >> 7;
        x ^= x << 17;
        RNG.store(x, Ordering::Relaxed);
        for (b, byte) in chunk.iter_mut().zip(x.to_le_bytes()) {
            *b = byte;
        }
    }
    Ok(())
}
getrandom::register_custom_getrandom!(custom_getrandom);

// ---------------------------------------------------------------------------
// host import + memory contract (same host ABI as the other guests)
// ---------------------------------------------------------------------------

#[link(wasm_import_module = "host")]
extern "C" {
    fn host_call(name_ptr: i32, name_len: i32, args_ptr: i32, args_len: i32) -> i64;
}

#[no_mangle]
pub extern "C" fn guest_alloc(len: i32) -> i32 {
    let mut buf = Vec::<u8>::with_capacity(len.max(1) as usize);
    let ptr = buf.as_mut_ptr();
    std::mem::forget(buf);
    ptr as i32
}

fn read_input(ptr: i32, len: i32) -> Vec<u8> {
    unsafe { slice::from_raw_parts(ptr as *const u8, len as usize).to_vec() }
}

fn ret(bytes: Vec<u8>) -> i64 {
    let ptr = guest_alloc(bytes.len() as i32);
    unsafe { std::ptr::copy_nonoverlapping(bytes.as_ptr(), ptr as *mut u8, bytes.len()) };
    ((ptr as i64) << 32) | (bytes.len() as i64)
}

fn call_host(name: &str, args: &Value) -> Value {
    let nb = name.as_bytes();
    let np = guest_alloc(nb.len() as i32);
    unsafe { std::ptr::copy_nonoverlapping(nb.as_ptr(), np as *mut u8, nb.len()) };

    let ab = encode(args);
    let ap = guest_alloc(ab.len() as i32);
    unsafe { std::ptr::copy_nonoverlapping(ab.as_ptr(), ap as *mut u8, ab.len()) };

    let packed = unsafe { host_call(np, nb.len() as i32, ap, ab.len() as i32) };
    let rptr = (packed >> 32) as i32;
    let rlen = (packed & 0xffff_ffff) as i32;
    decode(&read_input(rptr, rlen)).0
}

// ---------------------------------------------------------------------------
// check entrypoint (optional export — compile-only, nothing runs)
// ---------------------------------------------------------------------------

/// `check(ptr, len)`: compile-only validation — the strongest static check
/// JavaScript offers. Parses the source (nothing executes, no prelude, no
/// capability reachable) and returns a msgpack array of diagnostics; empty
/// means "compiles".
#[no_mangle]
pub extern "C" fn check(ptr: i32, len: i32) -> i64 {
    let source = match decode(&read_input(ptr, len)).0 {
        Value::Str(s) => s,
        _ => return ret(encode(&diags1("check expects a source string", None, None))),
    };

    let mut ctx = Context::default();
    let out = match boa_engine::Script::parse(Source::from_bytes(source.as_bytes()), None, &mut ctx)
    {
        Ok(_) => Value::Arr(vec![]),
        Err(e) => {
            let text = e.to_string();
            // Boa parse errors read "SyntaxError: ... at line N, col M"; the
            // type rides separately, so drop the prefix and lift the line out.
            let msg = text.strip_prefix("SyntaxError: ").unwrap_or(&text);
            let line = text.split("line ").nth(1).and_then(|rest| {
                let digits: String = rest.chars().take_while(|c| c.is_ascii_digit()).collect();
                digits.parse::<i64>().ok()
            });
            diags1(msg, Some("SyntaxError"), line)
        }
    };
    ret(encode(&out))
}

/// A one-diagnostic msgpack array: `[ {message, type?, line?} ]`.
fn diags1(message: &str, ty: Option<&str>, line: Option<i64>) -> Value {
    let mut fields = vec![("message".to_owned(), Value::Str(message.to_owned()))];
    if let Some(t) = ty {
        fields.push(("type".to_owned(), Value::Str(t.to_owned())));
    }
    if let Some(l) = line {
        fields.push(("line".to_owned(), Value::Int(l)));
    }
    Value::Arr(vec![Value::Map(fields)])
}

// ---------------------------------------------------------------------------
// eval entrypoint
// ---------------------------------------------------------------------------

/// `eval(ptr, len)`: the argument is a msgpack string (the JS source). Runs it
/// in a fresh Boa context with the registered capabilities installed as globals
/// wired to `host_call`, and returns the result value (msgpack). A JS error comes back as the structured
/// sentinel `{ "$error": {message, type?, line?} }` for the host to raise.
#[no_mangle]
pub extern "C" fn eval(ptr: i32, len: i32) -> i64 {
    let source = match decode(&read_input(ptr, len)).0 {
        Value::Str(s) => s,
        _ => return ret(encode(&js_error("eval expects a source string"))),
    };

    let mut ctx = Context::default();

    // The single native bridge: __host(name, ...args) -> result.
    let host_fn = NativeFunction::from_fn_ptr(|_this, args, ctx| {
        let name = args
            .first()
            .cloned()
            .unwrap_or(JsValue::undefined())
            .to_string(ctx)?
            .to_std_string_escaped();
        let rest: Vec<Value> = args.iter().skip(1).map(|a| js_to_value(a, ctx)).collect();
        let result = call_host(&name, &Value::Arr(rest));
        Ok(value_to_js(&result, ctx))
    });
    if ctx
        .register_global_callable(js_string!("__host"), 1, host_fn)
        .is_err()
    {
        return ret(encode(&js_error("failed to install host bridge")));
    }

    // A recursive facade: each access deepens a dotted path, and calling a node
    // invokes that capability — so a.b.c(...) -> __host("a.b.c", ...). There is no
    // synthetic `php.*` root: the guest asks the host (via the reserved "$names"
    // capability) which top-level names are registered and installs each as a
    // global, so `math.add` registered host-side is reached as `math.add(...)`.
    // Plus a `console` that routes log/error/warn/info to the host output buffer
    // (the reserved "$out" capability) so guest prints are captured host-side.
    let prelude = r#"
        function __ns(prefix) {
            return new Proxy(function () {}, {
                get(_t, name) {
                    if (typeof name !== 'string') return undefined;
                    return __ns(prefix ? prefix + '.' + name : name);
                },
                apply(_t, _this, args) { return __host(prefix, ...args); }
            });
        }
        for (const name of __host('$names')) {
            globalThis[name] = __ns(name);
        }
        globalThis.console = (function () {
            const fmt = (x) => (typeof x === 'object' && x !== null) ? JSON.stringify(x) : String(x);
            const emit = (...a) => { __host('$out', a.map(fmt).join(' ')); };
            return { log: emit, error: emit, warn: emit, info: emit, debug: emit };
        })();
    "#;
    let _ = ctx.eval(Source::from_bytes(prelude.as_bytes()));

    let out = match ctx.eval(Source::from_bytes(source.as_bytes())) {
        Ok(v) => js_to_value(&v, &mut ctx),
        Err(e) => js_error_from(&e.to_string()),
    };
    ret(encode(&out))
}

/// Build the structured `{ "$error": {message, type?, line?} }` sentinel.
fn err_value(message: &str, ty: Option<&str>, line: Option<i64>) -> Value {
    let mut fields = vec![("message".to_owned(), Value::Str(message.to_owned()))];
    if let Some(t) = ty {
        fields.push(("type".to_owned(), Value::Str(t.to_owned())));
    }
    if let Some(l) = line {
        fields.push(("line".to_owned(), Value::Int(l)));
    }
    Value::Map(vec![("$error".to_owned(), Value::Map(fields))])
}

/// A plain internal error (no engine type/line available).
fn js_error(msg: &str) -> Value {
    err_value(msg, None, None)
}

/// Parse a Boa error string ("Uncaught TypeError: message") into type + message.
/// Boa does not surface a reliable source line through its public API, so none
/// is reported.
fn js_error_from(text: &str) -> Value {
    let s = text.trim().strip_prefix("Uncaught ").unwrap_or(text).trim();
    match s.split_once(": ") {
        Some((ty, msg)) if !ty.is_empty() && !ty.contains(' ') => err_value(msg, Some(ty), None),
        _ => err_value(s, None, None),
    }
}

// ---------------------------------------------------------------------------
// Boa JsValue <-> neutral Value
// ---------------------------------------------------------------------------

fn js_to_value(v: &JsValue, ctx: &mut Context) -> Value {
    if v.is_null_or_undefined() {
        return Value::Nil;
    }
    if let Some(b) = v.as_boolean() {
        return Value::Bool(b);
    }
    if let Some(n) = v.as_number() {
        return num(n);
    }
    if let Some(s) = v.as_string() {
        return Value::Str(s.to_std_string_escaped());
    }
    if let Some(obj) = v.as_object() {
        if obj.is_callable() {
            // Functions don't cross in this phase; surface as null.
            return Value::Nil;
        }
        if obj.is_array() {
            let len = obj
                .get(js_string!("length"), ctx)
                .ok()
                .and_then(|l| l.as_number())
                .unwrap_or(0.0) as u64;
            let mut items = Vec::with_capacity(len as usize);
            for i in 0..len {
                let el = obj.get(i, ctx).unwrap_or(JsValue::undefined());
                items.push(js_to_value(&el, ctx));
            }
            return Value::Arr(items);
        }
        // Plain object -> map over its own keys.
        let mut entries = Vec::new();
        if let Ok(keys) = obj.own_property_keys(ctx) {
            for key in keys {
                let val = obj.get(key.clone(), ctx).unwrap_or(JsValue::undefined());
                if val.is_callable() || val.is_undefined() {
                    continue;
                }
                entries.push((key.to_string(), js_to_value(&val, ctx)));
            }
        }
        return Value::Map(entries);
    }
    Value::Nil
}

fn num(n: f64) -> Value {
    if n.is_finite() && n.fract() == 0.0 && n.abs() < 9.007_199_254_740_992e15 {
        Value::Int(n as i64)
    } else {
        Value::F64(n)
    }
}

fn value_to_js(v: &Value, ctx: &mut Context) -> JsValue {
    match v {
        Value::Nil => JsValue::null(),
        Value::Bool(b) => JsValue::from(*b),
        Value::Int(i) => {
            if let Ok(i32v) = i32::try_from(*i) {
                JsValue::from(i32v)
            } else {
                JsValue::from(*i as f64)
            }
        }
        Value::F64(f) => JsValue::from(*f),
        Value::Str(s) => JsValue::from(js_string!(s.as_str())),
        Value::Bin(b) => {
            let arr = boa_engine::object::builtins::JsArray::new(ctx);
            for byte in b {
                let _ = arr.push(JsValue::from(*byte as i32), ctx);
            }
            arr.into()
        }
        Value::Arr(items) => {
            let arr = boa_engine::object::builtins::JsArray::new(ctx);
            for it in items {
                let jv = value_to_js(it, ctx);
                let _ = arr.push(jv, ctx);
            }
            arr.into()
        }
        Value::Map(entries) => {
            let obj = boa_engine::object::JsObject::with_null_proto();
            for (k, val) in entries {
                let jv = value_to_js(val, ctx);
                let _ = obj.set(js_string!(k.as_str()), jv, false, ctx);
            }
            obj.into()
        }
    }
}

// ---------------------------------------------------------------------------
// minimal msgpack codec (shared shape with the example guest)
// ---------------------------------------------------------------------------

#[derive(Clone, Debug)]
enum Value {
    Nil,
    Bool(bool),
    Int(i64),
    F64(f64),
    Str(String),
    Bin(Vec<u8>),
    Arr(Vec<Value>),
    Map(Vec<(String, Value)>),
}

fn encode(v: &Value) -> Vec<u8> {
    let mut out = Vec::new();
    enc(v, &mut out);
    out
}

fn enc(v: &Value, out: &mut Vec<u8>) {
    match v {
        Value::Nil => out.push(0xc0),
        Value::Bool(false) => out.push(0xc2),
        Value::Bool(true) => out.push(0xc3),
        Value::Int(i) => {
            let i = *i;
            if (0..=127).contains(&i) {
                out.push(i as u8);
            } else if (-32..0).contains(&i) {
                out.push((i as i8) as u8);
            } else {
                out.push(0xd3);
                out.extend_from_slice(&i.to_be_bytes());
            }
        }
        Value::F64(f) => {
            out.push(0xcb);
            out.extend_from_slice(&f.to_be_bytes());
        }
        Value::Str(s) => {
            let b = s.as_bytes();
            if b.len() < 32 {
                out.push(0xa0 | b.len() as u8);
            } else {
                out.push(0xdb);
                out.extend_from_slice(&(b.len() as u32).to_be_bytes());
            }
            out.extend_from_slice(b);
        }
        Value::Bin(b) => {
            out.push(0xc4);
            out.push(b.len() as u8);
            out.extend_from_slice(b);
        }
        Value::Arr(items) => {
            if items.len() < 16 {
                out.push(0x90 | items.len() as u8);
            } else {
                out.push(0xdc);
                out.extend_from_slice(&(items.len() as u16).to_be_bytes());
            }
            for it in items {
                enc(it, out);
            }
        }
        Value::Map(entries) => {
            if entries.len() < 16 {
                out.push(0x80 | entries.len() as u8);
            } else {
                out.push(0xde);
                out.extend_from_slice(&(entries.len() as u16).to_be_bytes());
            }
            for (k, val) in entries {
                enc(&Value::Str(k.clone()), out);
                enc(val, out);
            }
        }
    }
}

fn decode(b: &[u8]) -> (Value, usize) {
    let m = b[0];
    match m {
        0x00..=0x7f => (Value::Int(m as i64), 1),
        0xe0..=0xff => (Value::Int((m as i8) as i64), 1),
        0xc0 => (Value::Nil, 1),
        0xc2 => (Value::Bool(false), 1),
        0xc3 => (Value::Bool(true), 1),
        0xcc => (Value::Int(b[1] as i64), 2),
        0xcd => (Value::Int(u16::from_be_bytes([b[1], b[2]]) as i64), 3),
        0xce => (
            Value::Int(u32::from_be_bytes([b[1], b[2], b[3], b[4]]) as i64),
            5,
        ),
        0xcf => (Value::Int(read_u64(&b[1..]) as i64), 9),
        0xd0 => (Value::Int((b[1] as i8) as i64), 2),
        0xd1 => (Value::Int(i16::from_be_bytes([b[1], b[2]]) as i64), 3),
        0xd2 => (
            Value::Int(i32::from_be_bytes([b[1], b[2], b[3], b[4]]) as i64),
            5,
        ),
        0xd3 => (Value::Int(read_u64(&b[1..]) as i64), 9),
        0xca => {
            let f = f32::from_be_bytes([b[1], b[2], b[3], b[4]]);
            (Value::F64(f as f64), 5)
        }
        0xcb => (Value::F64(f64::from_bits(read_u64(&b[1..]))), 9),
        0xa0..=0xbf => {
            let n = (m & 0x1f) as usize;
            (str_val(&b[1..1 + n]), 1 + n)
        }
        0xd9 => {
            let n = b[1] as usize;
            (str_val(&b[2..2 + n]), 2 + n)
        }
        0xda => {
            let n = u16::from_be_bytes([b[1], b[2]]) as usize;
            (str_val(&b[3..3 + n]), 3 + n)
        }
        0xdb => {
            let n = u32::from_be_bytes([b[1], b[2], b[3], b[4]]) as usize;
            (str_val(&b[5..5 + n]), 5 + n)
        }
        0xc4 => {
            let n = b[1] as usize;
            (Value::Bin(b[2..2 + n].to_vec()), 2 + n)
        }
        0xc5 => {
            let n = u16::from_be_bytes([b[1], b[2]]) as usize;
            (Value::Bin(b[3..3 + n].to_vec()), 3 + n)
        }
        0x90..=0x9f => decode_arr((m & 0x0f) as usize, &b[1..], 1),
        0xdc => {
            let n = u16::from_be_bytes([b[1], b[2]]) as usize;
            decode_arr(n, &b[3..], 3)
        }
        0x80..=0x8f => decode_map((m & 0x0f) as usize, &b[1..], 1),
        0xde => {
            let n = u16::from_be_bytes([b[1], b[2]]) as usize;
            decode_map(n, &b[3..], 3)
        }
        _ => (Value::Nil, 1),
    }
}

fn decode_arr(n: usize, mut rest: &[u8], header: usize) -> (Value, usize) {
    let mut items = Vec::with_capacity(n);
    let mut used = header;
    for _ in 0..n {
        let (v, c) = decode(rest);
        items.push(v);
        rest = &rest[c..];
        used += c;
    }
    (Value::Arr(items), used)
}

fn decode_map(n: usize, mut rest: &[u8], header: usize) -> (Value, usize) {
    let mut entries = Vec::with_capacity(n);
    let mut used = header;
    for _ in 0..n {
        let (k, kc) = decode(rest);
        rest = &rest[kc..];
        used += kc;
        let (v, vc) = decode(rest);
        rest = &rest[vc..];
        used += vc;
        let key = match k {
            Value::Str(s) => s,
            Value::Int(i) => i.to_string(),
            _ => String::new(),
        };
        entries.push((key, v));
    }
    (Value::Map(entries), used)
}

fn read_u64(b: &[u8]) -> u64 {
    u64::from_be_bytes([b[0], b[1], b[2], b[3], b[4], b[5], b[6], b[7]])
}

fn str_val(b: &[u8]) -> Value {
    Value::Str(String::from_utf8_lossy(b).into_owned())
}

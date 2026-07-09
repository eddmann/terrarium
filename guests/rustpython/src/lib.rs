//! A real Python interpreter ([RustPython], pure Rust) compiled to WebAssembly
//! as a Terrarium guest. Same host ABI as every other guest: `eval(source)`
//! runs Python and returns a value, and the guest reaches PHP capabilities as
//! `<name>(...)`. Proves the host extension is language-agnostic — the very
//! same bridge that runs Boa and QuickJS runs CPython-compatible Python.
//!
//! [RustPython]: https://github.com/RustPython/RustPython

use rustpython_vm as vm;
use std::slice;
use std::sync::atomic::{AtomicU64, Ordering};
use vm::builtins::{PyDict, PyFloat, PyInt, PyList, PyStr};
use vm::{AsObject, PyObjectRef, VirtualMachine};

// --- entropy: a bare wasm guest has no OS RNG -----------------------------
// A deterministic xorshift stand-in, fed to both getrandom 0.2 (via the macro)
// and 0.3 (via its custom-backend hook). RustPython pulls both versions.
static RNG: AtomicU64 = AtomicU64::new(0x9e37_79b9_7f4a_7c15);
fn fill(buf: &mut [u8]) {
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
}
fn custom_getrandom(buf: &mut [u8]) -> Result<(), getrandom::Error> {
    fill(buf);
    Ok(())
}
getrandom::register_custom_getrandom!(custom_getrandom);

#[no_mangle]
unsafe extern "Rust" fn __getrandom_v03_custom(
    dest: *mut u8,
    len: usize,
) -> Result<(), getrandom3::Error> {
    fill(core::slice::from_raw_parts_mut(dest, len));
    Ok(())
}

// --- host import + memory contract ----------------------------------------
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

// --- eval entrypoint ------------------------------------------------------

// A recursive facade: a.b.c(...) -> _hostcall(\"a.b.c\", ...). Each attribute
// access deepens a dotted path; calling a node invokes that capability. There is
// no synthetic `php.*` root: the guest asks the host (via the reserved "$names"
// capability) which top-level names are registered and installs each as a global,
// so `math.add` registered host-side is reached as `math.add(...)`.
// Note: the bridge global must not start with a double underscore — Python name
// mangling inside the class body would rewrite `__host` to `_Php__host`.
const PRELUDE: &str = "
class _NS:
    def __init__(self, p=''):
        self._p = p
    def __getattr__(self, name):
        return _NS(self._p + '.' + name if self._p else name)
    def __call__(self, *args):
        return _hostcall(self._p, *args)
for _n in _hostcall('$names'):
    globals()[_n] = _NS(_n)

def print(*args, sep=' ', end=''):
    _hostcall('$out', sep.join(str(a) for a in args))
";

/// `check(ptr, len)`: compile-only validation — the strongest static check
/// Python offers. Compiles the source (nothing executes, no prelude, no
/// capability reachable) and returns a msgpack array of diagnostics; empty
/// means "compiles".
#[no_mangle]
pub extern "C" fn check(ptr: i32, len: i32) -> i64 {
    let source = match decode(&read_input(ptr, len)).0 {
        Value::Str(s) => s,
        _ => return ret(encode(&diags1("check expects a source string", None, None))),
    };

    let interp = vm::Interpreter::without_stdlib(Default::default());
    let out = interp.enter(|vm| {
        match vm.compile(&source, vm::compiler::Mode::Exec, "<eval>".to_owned()) {
            Ok(_) => Value::Arr(vec![]),
            Err(e) => {
                // Render through the interpreter's own SyntaxError formatting so
                // the message and line match what eval() would have reported.
                let exc = vm.new_syntax_error(&e, Some(&source));
                let tb = exc_message(vm, exc);
                let line = last_line_number(&tb);
                let (ty, msg) = split_type_message(&tb);
                diags1(&msg, ty.as_deref().or(Some("SyntaxError")), line)
            }
        }
    });
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

#[no_mangle]
pub extern "C" fn eval(ptr: i32, len: i32) -> i64 {
    let source = match decode(&read_input(ptr, len)).0 {
        Value::Str(s) => s,
        _ => return ret(encode(&py_error("eval expects a source string"))),
    };

    let interp = vm::Interpreter::without_stdlib(Default::default());
    let out = interp.enter(|vm| {
        let scope = vm.new_scope_with_builtins();

        // Install __host(name, *args) and the php facade.
        let host_fn = vm.new_function("_hostcall", host_native).into();
        if scope.globals.set_item("_hostcall", host_fn, vm).is_err() {
            return py_error("failed to install host bridge");
        }
        if let Err(e) = vm
            .compile(PRELUDE, vm::compiler::Mode::Exec, "<prelude>".to_owned())
            .map_err(|e| vm.new_syntax_error(&e, Some(PRELUDE)))
            .and_then(|code| vm.run_code_obj(code, scope.clone()))
        {
            return py_error_exc(vm, e);
        }

        run_source(vm, &scope, &source)
    });
    ret(encode(&out))
}

/// Evaluate Python and return the value of its last top-level *expression* —
/// matching JS's "eval returns the completion value". Python distinguishes
/// expressions (eval, returns a value) from statements (exec, returns nothing),
/// so: try the whole source as an expression; else run the leading statements
/// with exec and evaluate the trailing expression with eval (walking the split
/// point up until the suffix parses as an expression).
fn run_source(vm: &VirtualMachine, scope: &vm::scope::Scope, source: &str) -> Value {
    use vm::compiler::Mode;
    let finish = |r: vm::PyResult<PyObjectRef>| match r {
        Ok(o) => py_to_value(vm, &o),
        Err(e) => py_error_exc(vm, e),
    };

    if let Ok(code) = vm.compile(source, Mode::Eval, "<eval>".to_owned()) {
        return finish(vm.run_code_obj(code, scope.clone()));
    }

    for nl in source.match_indices('\n').map(|(i, _)| i).rev() {
        let (head, tail) = (&source[..nl], source[nl + 1..].trim());
        if tail.is_empty() {
            continue;
        }
        let Ok(tail_code) = vm.compile(tail, Mode::Eval, "<eval>".to_owned()) else {
            continue;
        };
        if !head.trim().is_empty() {
            match vm.compile(head, Mode::Exec, "<eval>".to_owned()) {
                Ok(head_code) => {
                    if let Err(e) = vm.run_code_obj(head_code, scope.clone()) {
                        return py_error_exc(vm, e);
                    }
                }
                Err(_) => continue,
            }
        }
        return finish(vm.run_code_obj(tail_code, scope.clone()));
    }

    // No trailing expression: run as statements; the result is None.
    match vm.compile(source, Mode::Exec, "<eval>".to_owned()) {
        Ok(code) => finish(vm.run_code_obj(code, scope.clone())),
        Err(e) => py_error(&format!("SyntaxError: {e}")),
    }
}

fn host_native(args: vm::function::FuncArgs, vm: &VirtualMachine) -> PyObjectRef {
    let mut it = args.args.into_iter();
    let name = match it.next() {
        Some(o) => o
            .str(vm)
            .ok()
            .and_then(|s| s.to_str().map(str::to_owned))
            .unwrap_or_default(),
        None => return vm.ctx.none(),
    };
    let rest: Vec<Value> = it.map(|o| py_to_value(vm, &o)).collect();
    let result = call_host(&name, &Value::Arr(rest));
    value_to_py(vm, &result)
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

/// A plain internal/compile error (no live exception object).
fn py_error(msg: &str) -> Value {
    err_value(msg, None, None)
}

/// Turn a live Python exception into the structured sentinel. The type, message,
/// and deepest source line are parsed from the rendered traceback — robust
/// across RustPython versions and free of its internal type API.
fn py_error_exc(vm: &VirtualMachine, e: vm::PyRef<vm::builtins::PyBaseException>) -> Value {
    let tb = exc_message(vm, e);
    let line = last_line_number(&tb);
    let (ty, msg) = split_type_message(&tb);
    err_value(&msg, ty.as_deref(), line)
}

fn exc_message(vm: &VirtualMachine, e: vm::PyRef<vm::builtins::PyBaseException>) -> String {
    let mut s = String::new();
    vm.write_exception(&mut s, &e).ok();
    if s.is_empty() {
        "uncaught Python exception".to_owned()
    } else {
        s.trim().to_owned()
    }
}

/// The deepest `line N` in a rendered traceback (the frame the error fired in).
fn last_line_number(tb: &str) -> Option<i64> {
    let mut found = None;
    for part in tb.split("line ").skip(1) {
        let digits: String = part.chars().take_while(|c| c.is_ascii_digit()).collect();
        if let Ok(n) = digits.parse::<i64>() {
            found = Some(n);
        }
    }
    found
}

/// Split a traceback's final line ("ExceptionType: message") into type + message.
fn split_type_message(tb: &str) -> (Option<String>, String) {
    let last = tb
        .lines()
        .rev()
        .find(|l| !l.trim().is_empty())
        .unwrap_or("")
        .trim();
    match last.split_once(": ") {
        Some((ty, msg)) if !ty.is_empty() && !ty.contains(' ') => {
            (Some(ty.to_owned()), msg.to_owned())
        }
        _ => (None, last.to_owned()),
    }
}

// --- Python <-> neutral Value ---------------------------------------------

fn py_to_value(vm: &VirtualMachine, obj: &PyObjectRef) -> Value {
    if vm.is_none(obj) {
        return Value::Nil;
    }
    if obj.is(&vm.ctx.true_value) {
        return Value::Bool(true);
    }
    if obj.is(&vm.ctx.false_value) {
        return Value::Bool(false);
    }
    if let Some(i) = obj.downcast_ref::<PyInt>() {
        return i64::try_from(i.as_bigint())
            .map(Value::Int)
            .unwrap_or_else(|_| Value::F64(i.as_bigint().to_string().parse().unwrap_or(0.0)));
    }
    if let Some(f) = obj.downcast_ref::<PyFloat>() {
        return Value::F64(f.to_f64());
    }
    if let Some(s) = obj.downcast_ref::<PyStr>() {
        return Value::Str(s.to_str().unwrap_or("").to_owned());
    }
    if let Some(list) = obj.downcast_ref::<PyList>() {
        let items = list.borrow_vec().iter().map(|e| py_to_value(vm, e)).collect();
        return Value::Arr(items);
    }
    if let Some(dict) = obj.downcast_ref::<PyDict>() {
        let mut entries = Vec::new();
        for (k, v) in dict {
            let key = k
                .str(vm)
                .ok()
                .and_then(|s| s.to_str().map(str::to_owned))
                .unwrap_or_default();
            entries.push((key, py_to_value(vm, &v)));
        }
        return Value::Map(entries);
    }
    // Tuples and other iterables: best-effort via str().
    match obj.str(vm) {
        Ok(s) => Value::Str(s.to_str().unwrap_or("").to_owned()),
        Err(_) => Value::Nil,
    }
}

fn value_to_py(vm: &VirtualMachine, v: &Value) -> PyObjectRef {
    match v {
        Value::Nil => vm.ctx.none(),
        Value::Bool(b) => vm.ctx.new_bool(*b).into(),
        Value::Int(i) => vm.ctx.new_int(*i).into(),
        Value::F64(f) => vm.ctx.new_float(*f).into(),
        Value::Str(s) => vm.ctx.new_str(s.as_str()).into(),
        Value::Bin(b) => vm.ctx.new_bytes(b.clone()).into(),
        Value::Arr(items) => {
            let v: Vec<PyObjectRef> = items.iter().map(|it| value_to_py(vm, it)).collect();
            vm.ctx.new_list(v).into()
        }
        Value::Map(entries) => {
            let d = vm.ctx.new_dict();
            for (k, val) in entries {
                let pv = value_to_py(vm, val);
                d.set_item(k.as_str(), pv, vm).ok();
            }
            d.into()
        }
    }
}

// --- minimal msgpack codec (shared shape with the other guests) -----------

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

//! The trust boundary: a flat dispatch table of registered PHP callables,
//! reached from the guest through one `host_call` import.
//!
//! The `host_call(name, argsBytes)` ABI is byte-based: the guest encodes its
//! argument array to msgpack, the host decodes it, looks the dotted name up in
//! the dispatch table (rejecting anything not registered — this is the trust
//! boundary), dispatches to the PHP callable, and returns the msgpack-encoded
//! result. Adding a capability never changes this ABI.

use crate::handles::HandleTable;
use crate::marshal::{middle_to_zval, zval_to_middle, MiddleValue};
use ext_php_rs::convert::IntoZvalDyn;
use ext_php_rs::types::{ZendCallable, Zval};
use std::cell::RefCell;
use std::collections::HashMap;
use std::rc::Rc;

/// Reserved capability name the guest preludes route `console.log`/`print`
/// through. It is intercepted host-side and never reaches the dispatch table, so
/// it cannot collide with a registered capability. Names starting with `$` are
/// reserved for the bridge.
pub const OUTPUT_CAP: &str = "$out";

/// Reserved capability the guest preludes query once, at startup, to learn which
/// top-level globals to install. There is no synthetic root (`php.*`): the
/// registered names *are* the guest surface, so a name like `math.add` is reached
/// as `math.add(...)`. This returns the unique first segment of every registered
/// name (`["math", "user", ...]`); the guest installs each as a global proxy.
pub const NAMES_CAP: &str = "$names";

/// Reserved capability returning the host-generated TypeScript declaration
/// (`.d.ts`) of the registered SDK. A type-aware guest (the TypeScript guest)
/// fetches it per eval and checks the submitted source against it — the type
/// environment is exactly the capability environment. Pushed down from the PHP
/// facade on every `register()`.
pub const DTS_CAP: &str = "$dts";

/// Shared host-side state behind the bridge. Single-threaded (PHP NTS), so
/// `Rc`/`RefCell` interior mutability is sufficient and correct.
#[derive(Default)]
pub struct BridgeState {
    /// Dotted name -> PHP callable (the trust-boundary allowlist).
    dispatch: RefCell<HashMap<String, Zval>>,
    /// Dotted capability names in registration order (the audit manifest). Type
    /// information lives in the PHP `Terrarium` facade, which infers it from the
    /// registered closures' signatures.
    manifest: RefCell<Vec<String>>,
    /// Captured guest output (`console.log` / `print`), one entry per call.
    /// Cleared at the start of each `eval` and readable afterwards — including
    /// after a guest error, so partial output before a crash is preserved.
    output: RefCell<Vec<String>>,
    /// The `.d.ts` of the registered SDK, served to type-aware guests via the
    /// reserved `$dts` capability. Kept current by the PHP facade on register().
    types_dts: RefCell<String>,
    /// Live PHP objects granted to the guest as opaque handles.
    pub handles: HandleTable,
}

impl BridgeState {
    pub fn new() -> Rc<Self> {
        Rc::new(Self::default())
    }

    /// Register a PHP callable under a flat, dotted name. Re-registering a name
    /// replaces the callable and keeps its manifest position.
    pub fn register(&self, name: String, callable: &Zval) -> Result<(), String> {
        if !callable.is_callable() {
            return Err(format!("value registered as '{name}' is not callable"));
        }
        let existed = self
            .dispatch
            .borrow_mut()
            .insert(name.clone(), callable.shallow_clone())
            .is_some();
        if !existed {
            self.manifest.borrow_mut().push(name);
        }
        Ok(())
    }

    /// The registered capability names (audit surface), sorted.
    pub fn names(&self) -> Vec<String> {
        let mut names = self.manifest.borrow().clone();
        names.sort();
        names
    }

    /// The unique first segment of every registered dotted name — the set of
    /// top-level globals a guest installs (there is no synthetic `php.*` root).
    /// Sorted and de-duplicated: `math.add` + `math.sub` + `ping` -> `["math",
    /// "ping"]`.
    pub fn top_level(&self) -> Vec<String> {
        let mut segments: Vec<String> = self
            .manifest
            .borrow()
            .iter()
            .map(|n| n.split('.').next().unwrap_or(n).to_owned())
            .collect();
        segments.sort();
        segments.dedup();
        segments
    }

    /// Replace the SDK `.d.ts` served via the reserved `$dts` capability.
    pub fn set_types(&self, dts: String) {
        *self.types_dts.borrow_mut() = dts;
    }

    /// Clear the captured output buffer (called at the start of each `eval`).
    pub fn clear_output(&self) {
        self.output.borrow_mut().clear();
    }

    /// The guest output captured since the last clear, lines joined by `\n`.
    pub fn output_text(&self) -> String {
        self.output.borrow().join("\n")
    }

    /// Dispatch a named host call: look the name up (trust-boundary rejection
    /// if absent), marshal the args to PHP, invoke, and marshal the result back.
    pub fn host_call(&self, name: &str, args: Vec<MiddleValue>) -> Result<MiddleValue, String> {
        // The reserved output capability is intercepted before the trust-boundary
        // lookup: append the (already guest-formatted) line and return nothing.
        if name == OUTPUT_CAP {
            if let Some(MiddleValue::Str(line)) = args.into_iter().next() {
                self.output.borrow_mut().push(line);
            }
            return Ok(MiddleValue::Null);
        }

        // The reserved names capability: the guest asks which top-level globals
        // to install (there is no `php.*` root). Also intercepted before the
        // trust-boundary lookup, so it can never collide with a real capability.
        if name == NAMES_CAP {
            let segments = self.top_level().into_iter().map(MiddleValue::Str).collect();
            return Ok(MiddleValue::Array(segments));
        }

        // The reserved types capability: a type-aware guest asks for the SDK's
        // `.d.ts` to check submitted source against. Same pre-lookup intercept.
        if name == DTS_CAP {
            return Ok(MiddleValue::Str(self.types_dts.borrow().clone()));
        }

        let callable_zv = self
            .dispatch
            .borrow()
            .get(name)
            .map(Zval::shallow_clone)
            .ok_or_else(|| format!("unknown capability: {name}"))?;

        let zvals: Vec<Zval> = args.iter().map(middle_to_zval).collect::<Result<_, _>>()?;
        let params: Vec<&dyn IntoZvalDyn> = zvals.iter().map(|z| z as &dyn IntoZvalDyn).collect();

        let callable = ZendCallable::new(&callable_zv).map_err(|e| e.to_string())?;
        let ret = callable
            .try_call(params)
            .map_err(|e| format!("host call '{name}' failed: {e}"))?;
        zval_to_middle(&ret)
    }
}

/// Decode the msgpack arg payload from `host_call` into a list of positional
/// arguments. A top-level array is the argument list; any other value is a
/// single argument.
pub fn decode_args(bytes: &[u8]) -> Result<Vec<MiddleValue>, String> {
    match MiddleValue::from_msgpack(bytes).map_err(|e| e.to_string())? {
        MiddleValue::Array(a) => Ok(a),
        other => Ok(vec![other]),
    }
}

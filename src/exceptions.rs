//! Typed PHP exception classes thrown by the extension.
//!
//! ```text
//! \Exception
//!   └─ Terrarium\Exception              (base for everything this ext throws)
//!        ├─ Terrarium\TrapException     (the guest trapped: unreachable, bad call, …)
//!        ├─ Terrarium\TimeoutException  (the wall-clock deadline / fuel budget tripped)
//!        ├─ Terrarium\MemoryException   (a linear-memory bound was hit)
//!        └─ Terrarium\GuestException    (the guest program itself raised an error)
//! ```
//!
//! All four specialisations extend `Terrarium\Exception`, so a single
//! `catch (\Terrarium\Exception)` covers every failure the sandbox can produce —
//! engine-level traps/limits and guest-level program errors alike — while the
//! subclasses let callers `catch` by the failure mode they care about.

use ext_php_rs::prelude::*;
use ext_php_rs::zend::ce;

#[php_class]
#[php(extends(ce = ce::exception, stub = "\\Exception"))]
#[derive(Default)]
#[php(name = "Terrarium\\Exception")]
pub struct TerrariumException;

#[php_impl]
impl TerrariumException {}

#[php_class]
#[php(extends(ce = terrarium_exception_ce, stub = "\\Terrarium\\Exception"))]
#[derive(Default)]
#[php(name = "Terrarium\\TrapException")]
pub struct TerrariumTrapException;

#[php_impl]
impl TerrariumTrapException {}

#[php_class]
#[php(extends(ce = terrarium_exception_ce, stub = "\\Terrarium\\Exception"))]
#[derive(Default)]
#[php(name = "Terrarium\\TimeoutException")]
pub struct TerrariumTimeoutException;

#[php_impl]
impl TerrariumTimeoutException {}

#[php_class]
#[php(extends(ce = terrarium_exception_ce, stub = "\\Terrarium\\Exception"))]
#[derive(Default)]
#[php(name = "Terrarium\\MemoryException")]
pub struct TerrariumMemoryException;

#[php_impl]
impl TerrariumMemoryException {}

#[php_class]
#[php(extends(ce = terrarium_exception_ce, stub = "\\Terrarium\\Exception"))]
#[derive(Default)]
#[php(name = "Terrarium\\GuestException")]
pub struct TerrariumGuestException;

#[php_impl]
impl TerrariumGuestException {}

/// Class-entry accessor for the base exception, used as the parent of the
/// specialised subclasses above.
fn terrarium_exception_ce() -> &'static ext_php_rs::zend::ClassEntry {
    <TerrariumException as ext_php_rs::class::RegisteredClass>::get_metadata().ce()
}

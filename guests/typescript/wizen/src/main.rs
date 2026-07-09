//! Pre-initialize the TypeScript guest with Wizer (build.sh, final step).
//!
//! The freshly-linked `typescript_guest.wasm` exports `wizer.initialize`, which
//! calls the guest's `ensure_compiler()` — reading the ~2 MB TypeScript
//! bytecode, evaluating it into a live compiler object graph, and parsing the
//! lib `.d.ts` chain + ts-blank-space + the driver. Wizer runs that once here,
//! offline, then snapshots the resulting linear memory and globals into the
//! module's data segments. The runtime effect: `g_cctx` is already non-null, so
//! `ensure_compiler()` short-circuits and the ~1 s bootstrap is gone from every
//! eval — including each fresh instance in isolated mode.
//!
//! Two properties make this drop-in against the host with no host-side change:
//!
//!   * Wizer auto-defines every unsatisfied import (here `host.host_call`) as a
//!     trap. `ensure_compiler()` makes no host calls, so the trap never fires;
//!     we need no custom linker, only `allow_wasi` for the reactor's
//!     `_initialize` and QuickJS's clock/random.
//!   * Wizer strips both the `wizer.initialize` export and the reactor's
//!     `_initialize` export from the output (state is baked in). The host only
//!     calls `_initialize` when the export is present, so the snapshot is never
//!     re-initialized — no double-run of libc/global ctors over the baked heap.
//!     `eval`, `check`, `guest_alloc`, and `memory` are preserved unchanged.
//!
//! The output is a plain `.wasm` (portable data segments), not a version-locked
//! precompiled artifact — any Wasmtime version loads it.
//!
//!   ts-wizen <input.wasm> <output.wasm>

use anyhow::{Context, Result};
use wizer::Wizer;

fn main() -> Result<()> {
    let mut args = std::env::args().skip(1);
    let input = args
        .next()
        .context("usage: ts-wizen <input.wasm> <output.wasm>")?;
    let output = args
        .next()
        .context("usage: ts-wizen <input.wasm> <output.wasm>")?;

    let wasm = std::fs::read(&input).with_context(|| format!("reading {input}"))?;

    let mut wizer = Wizer::new();
    wizer
        .allow_wasi(true)?
        .init_func("wizer.initialize");
    let snapshot = wizer
        .run(&wasm)
        .context("Wizer pre-initialization failed")?;

    std::fs::write(&output, &snapshot).with_context(|| format!("writing {output}"))?;
    eprintln!(
        "  wizened: {} ({:.2} MB) -> {} ({:.2} MB)",
        input,
        wasm.len() as f64 / 1e6,
        output,
        snapshot.len() as f64 / 1e6,
    );
    Ok(())
}

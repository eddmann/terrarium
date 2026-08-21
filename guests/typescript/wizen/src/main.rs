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
//!     the custom linker below only has to cover WASI.
//!   * Wizer strips both the `wizer.initialize` export and the reactor's
//!     `_initialize` export from the output (state is baked in). The host only
//!     calls `_initialize` when the export is present, so the snapshot is never
//!     re-initialized — no double-run of libc/global ctors over the baked heap.
//!     `eval`, `check`, `guest_alloc`, and `memory` are preserved unchanged.
//!
//! The output is a plain `.wasm` (portable data segments), not a version-locked
//! precompiled artifact — any Wasmtime version loads it.
//!
//! ## Why a custom WASI, and why that is correct
//!
//! The guest imports exactly five WASI preview1 functions — `clock_time_get`,
//! `fd_close`, `fd_fdstat_get`, `fd_seek`, `fd_write` — and nothing else: no
//! `random_get`, no filesystem, no environment, no args. Wizer's built-in
//! `allow_wasi(true)` wires those to the real host, which makes the snapshot
//! **non-reproducible**: QuickJS seeds each context's PRNG from the wall clock
//! (`ctx->random_state = js__gettimeofday_us()` in `js_random_init`), so the
//! live 64-bit seed — plus any timestamp the bring-up happens to cache — is
//! baked into the data segments. Two wizenings of the *same* base module
//! differed in 22 bytes across three clusters, every one of them clock-derived.
//!
//! So instead of `allow_wasi` we install those five functions ourselves through
//! Wizer's `make_linker` hook with all entropy pinned: the clocks read from a
//! fixed epoch advanced by a fixed increment per call, and the stdio fds behave
//! like a non-seekable character device (writes are forwarded to the builder's
//! own stderr, which is host-side and never enters the snapshot). The build is
//! then byte-reproducible on a pinned toolchain.
//!
//! This changes only what the *snapshot* bakes, never runtime behaviour: the
//! host instantiates the finished module against its own real WASI, so
//! `Date.now()` and friends read the true clock at eval time. The one lasting
//! effect is that the pre-baked compiler context carries a fixed PRNG seed —
//! which is exactly the point, and is safe here: that context exists only to
//! parse, check and type-erase source, and nothing on that path uses
//! `Math.random()` for anything security-sensitive. User code is evaluated in a
//! context created at runtime, which seeds itself from the real clock as usual.
//!
//!   ts-wizen <input.wasm> <output.wasm>

use std::io::Write;
use std::rc::Rc;
use std::sync::atomic::{AtomicU64, Ordering};
use std::sync::Arc;

use anyhow::{bail, Context, Result};
use wizer::wasmtime::{Caller, Engine, Extern, Linker, Memory};
use wizer::{StoreData, Wizer};

/// Fixed wall-clock epoch handed to the guest, in nanoseconds:
/// 2024-01-01T00:00:00Z. Any constant would do; a recent-but-round one keeps
/// date arithmetic inside the compiler in ordinary ranges.
const EPOCH_NS: u64 = 1_704_067_200_000_000_000;

/// How far each clock read advances the shared tick, in nanoseconds. Time stays
/// strictly monotonic — nothing can spin waiting for the clock to move — while
/// remaining a pure function of the call sequence, which is itself fixed.
const TICK_NS: u64 = 1_000;

// WASI preview1 errno values.
const ERRNO_SUCCESS: i32 = 0;
const ERRNO_BADF: i32 = 8;
const ERRNO_INVAL: i32 = 28;
const ERRNO_SPIPE: i32 = 70;

/// WASI preview1 `filetype::character_device`.
const FILETYPE_CHARACTER_DEVICE: u8 = 2;

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
        .make_linker(Some(Rc::new(deterministic_wasi_linker)))?
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

/// Build the linker Wizer instantiates the guest with: the five WASI preview1
/// functions the guest imports, each of them deterministic. Every other import
/// (`host.host_call`) is left to Wizer, which stubs it out as a trap.
fn deterministic_wasi_linker(engine: &Engine) -> Result<Linker<StoreData>> {
    let mut linker = Linker::new(engine);
    let tick = Arc::new(AtomicU64::new(0));

    // clock_time_get(id, precision, out) -> errno
    //
    // `realtime` (0) counts from the fixed epoch; `monotonic` (1) and the two
    // cputime clocks (2, 3) count from zero. All four share one tick, so their
    // relative ordering is stable too.
    let clock_tick = Arc::clone(&tick);
    linker.func_wrap(
        "wasi_snapshot_preview1",
        "clock_time_get",
        move |mut caller: Caller<'_, StoreData>,
              clock_id: i32,
              _precision: i64,
              out: i32|
              -> Result<i32> {
            let now = clock_tick.fetch_add(TICK_NS, Ordering::Relaxed) + TICK_NS;
            let time = match clock_id {
                0 => EPOCH_NS + now,
                1 | 2 | 3 => now,
                _ => return Ok(ERRNO_INVAL),
            };
            let memory = guest_memory(&mut caller)?;
            if write_bytes(&memory, &mut caller, out, &time.to_le_bytes()).is_err() {
                return Ok(ERRNO_INVAL);
            }
            Ok(ERRNO_SUCCESS)
        },
    )?;

    // fd_write(fd, iovs, iovs_len, nwritten) -> errno
    //
    // stdout and stderr are forwarded to the builder's stderr so warm-up
    // diagnostics stay visible; that output is host-side and never reaches the
    // snapshot. Only the byte count crosses back into guest memory.
    linker.func_wrap(
        "wasi_snapshot_preview1",
        "fd_write",
        |mut caller: Caller<'_, StoreData>,
         fd: i32,
         iovs: i32,
         iovs_len: i32,
         nwritten: i32|
         -> Result<i32> {
            if !is_stdio(fd) {
                return Ok(ERRNO_BADF);
            }
            let memory = guest_memory(&mut caller)?;
            let mut total: u32 = 0;
            for i in 0..iovs_len.max(0) {
                let iov_ptr = iovs.wrapping_add(i.wrapping_mul(8));
                let mut iov = [0u8; 8];
                if read_bytes(&memory, &mut caller, iov_ptr, &mut iov).is_err() {
                    return Ok(ERRNO_INVAL);
                }
                let ptr = i32::from_le_bytes([iov[0], iov[1], iov[2], iov[3]]);
                let len = u32::from_le_bytes([iov[4], iov[5], iov[6], iov[7]]);
                if len == 0 {
                    continue;
                }
                let mut buf = vec![0u8; len as usize];
                if read_bytes(&memory, &mut caller, ptr, &mut buf).is_err() {
                    return Ok(ERRNO_INVAL);
                }
                let _ = std::io::stderr().write_all(&buf);
                total = total.saturating_add(len);
            }
            if write_bytes(&memory, &mut caller, nwritten, &total.to_le_bytes()).is_err() {
                return Ok(ERRNO_INVAL);
            }
            Ok(ERRNO_SUCCESS)
        },
    )?;

    // fd_fdstat_get(fd, out) -> errno
    //
    // The 24-byte fdstat is: filetype u8, one pad byte, fs_flags u16, four pad
    // bytes, then the two u64 rights masks. A character device with full rights
    // is what a real host reports for a tty, which is what wasi-libc reads to
    // pick line buffering for stdout.
    linker.func_wrap(
        "wasi_snapshot_preview1",
        "fd_fdstat_get",
        |mut caller: Caller<'_, StoreData>, fd: i32, out: i32| -> Result<i32> {
            if !is_stdio(fd) {
                return Ok(ERRNO_BADF);
            }
            let mut fdstat = [0u8; 24];
            fdstat[0] = FILETYPE_CHARACTER_DEVICE;
            fdstat[8..16].copy_from_slice(&u64::MAX.to_le_bytes());
            fdstat[16..24].copy_from_slice(&u64::MAX.to_le_bytes());
            let memory = guest_memory(&mut caller)?;
            if write_bytes(&memory, &mut caller, out, &fdstat).is_err() {
                return Ok(ERRNO_INVAL);
            }
            Ok(ERRNO_SUCCESS)
        },
    )?;

    // fd_seek(fd, offset, whence, out) -> errno
    //
    // Character devices are not seekable, and wasi-libc probes exactly that to
    // decide whether a stream is buffered — answering ESPIPE (rather than
    // faking a position) keeps the baked FILE state matching a real host.
    linker.func_wrap(
        "wasi_snapshot_preview1",
        "fd_seek",
        |fd: i32, _offset: i64, _whence: i32, _out: i32| -> i32 {
            if is_stdio(fd) {
                ERRNO_SPIPE
            } else {
                ERRNO_BADF
            }
        },
    )?;

    // fd_close(fd) -> errno
    linker.func_wrap("wasi_snapshot_preview1", "fd_close", |fd: i32| -> i32 {
        if is_stdio(fd) {
            ERRNO_SUCCESS
        } else {
            ERRNO_BADF
        }
    })?;

    Ok(linker)
}

fn is_stdio(fd: i32) -> bool {
    (0..=2).contains(&fd)
}

fn guest_memory(caller: &mut Caller<'_, StoreData>) -> Result<Memory> {
    match caller.get_export("memory") {
        Some(Extern::Memory(memory)) => Ok(memory),
        _ => bail!("the guest does not export a linear memory named `memory`"),
    }
}

fn read_bytes(
    memory: &Memory,
    caller: &mut Caller<'_, StoreData>,
    ptr: i32,
    buf: &mut [u8],
) -> Result<()> {
    memory
        .read(&mut *caller, ptr as u32 as usize, buf)
        .context("out-of-bounds WASI read")
}

fn write_bytes(
    memory: &Memory,
    caller: &mut Caller<'_, StoreData>,
    ptr: i32,
    buf: &[u8],
) -> Result<()> {
    memory
        .write(&mut *caller, ptr as u32 as usize, buf)
        .context("out-of-bounds WASI write")
}

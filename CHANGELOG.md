# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/).

## [1.4.0] - 2026-09-16

### Added

- Added a runtime-only build: the `compiler` cargo feature (in the default set) gates Cranelift, `Terrarium\Runtime::precompile()`, Wasmtime's on-disk module cache and the `.wat` text format, so `cargo build --release --no-default-features` (`make release-runtime`) produces an extension that loads precompiled artifacts and refuses raw WebAssembly and `precompile()` with a `Terrarium\Exception`. Everything else is unchanged — fuel, timeouts, limits, WASI preview 1, shared and isolated execution, the exception family — and both builds configure the engine identically, so an artifact precompiled by a release's full build loads into its runtime-only build.
- Added `Terrarium\Runtime::hasCompiler(): bool`, reporting whether the loaded extension can compile WebAssembly, so a deployment can tell the two builds apart before taking a path that would throw.
- Added `-runtime` Lambda/Bref release assets (`…-lambda-bref-ARCH-runtime.zip` and `…-runtime.so`) in the same layer layout and under the same bare `terrarium.so` name as the full build — the recommended pair for a deployment that ships precompiled guests, which every Lambda deployment should. The release workflow proves the runtime-only binary loads the guest the full one precompiled, and refuses raw wasm, before publishing.
- Added `make test-runtime`, which precompiles the QuickJS and TypeScript guests with the full extension and runs `tests/php/runtime/` against the runtime-only one; CI builds `--no-default-features` and runs that suite alongside the existing ones.

### Changed

- Stripped release binaries (`strip = "symbols"`): the shipped `.so` no longer carries a symbol table nothing reads at run time, while a panic still prints its message and `#[track_caller]` location to stderr (it aborts the process either way — ext-php-rs cannot unwind a panic into a PHP exception); what is lost is `perf`/`gdb` symbolisation of extension frames.
- Built Wasmtime with `default-features = false` and only the features this extension reaches for — `runtime`, `std`, `backtrace`, `gc`, `gc-copying`, `threads`, plus `cranelift`, `parallel-compilation`, `cache` and `wat` behind the `compiler` feature. Dropped from every build: `component-model-async`, `stack-switching`, `pooling-allocator`, `profiling`, `demangle`, `addr2line`, `coredump`, `debug`, `debug-builtins`, `compile-time-builtins`, `wit-parser`, `anyhow`, and the `gc-drc` and `gc-null` collectors.
- Sized the TypeScript guest's linker stack at 4 MiB instead of 12 MiB. The stack sits inside the declared initial memory, which a precompiled artifact carries verbatim, so the cwasm shrinks one byte per byte reserved: 45,062,768 → 36,674,160 bytes (−18.6%), with the raw `.wasm` unchanged in size. Recursion is bounded by Wasmtime's native stack limit (capped at 2 MiB), against which the guest's shadow stack was measured at ~1.2 MiB worst case; QuickJS's own `JS_SetMaxStackSize` is compiled out on wasi and never applied.
- Linked both QuickJS-based guests with `-Wl,--stack-first`, putting the linear-memory shadow stack at the bottom of memory instead of between the static data and the heap. The stack grows downwards, so the old layout let an overflow run on into the static data and corrupt it silently — measured on the plain QuickJS guest, recursion past the 1 MiB stack scribbled ~72 KB over the module's constants across ~220 further frames before an address finally wrapped out of bounds, and on an undersized TypeScript build a deep-nesting check returned `InternalError: invalid opcode: pc=17 opcode=0x00` from bytecode the overflow had overwritten. With the stack placed first there is nothing below it, so the frame that overflows traps out of bounds immediately and at a repeatable depth. Both fixtures stay byte-for-byte reproducible and declare the same initial memory as before (496 and 18 pages).
- Removed the `JS_SetMaxStackSize` calls from both guests. quickjs-ng compiles its stack guard out on wasi — `update_stack_limit` pins `stack_limit` to 0 and `JS_NewRuntime2` pins `rt->stack_size` to 0 — so the calls limited nothing and the comments around them claimed a guard that did not exist. Recursion depth is bounded by the host: `maxStack` (Wasmtime's native stack limit, capped at 2 MiB by the engine) traps with `call stack exhausted`, and the shadow stack traps out of bounds; neither is catchable inside the guest, and neither is a JS `RangeError`.
- Shrank the precompiled TypeScript artifact to 32,557,568 bytes (from the 36,674,160 above, −11.2%), a side effect of stack-first placement: an artifact carries linear memory from its first to its last initialised page, and the reserved stack now sits below the first instead of inside the image. Cold construct-plus-eval from the artifact drops from 102 ms to 91 ms (median of 5). It also means stack size is now nearly free in the artifact — a 12 MiB stack-first build precompiles to within 8 bytes of the 4 MiB one — so what a larger stack costs is the declared initial memory, not the download.
- Together, measured on x86_64 Linux: the release `.so` is 15.9 MiB (full) and 3.7 MiB (runtime-only), down from the 23.1 MiB unstripped v1.3.0 Lambda build.

## [1.3.0] - 2026-09-07

### Added

- Added ahead-of-time compilation: `Terrarium::precompile()` (`Terrarium\Runtime::precompile()`) emits an artifact for the current extension build, loaded with `precompiled: true`, so a deployment without a usable module cache deserializes a guest instead of compiling it; artifacts are host-trusted native code, are never auto-detected, and share the compiled-guest cache.
- Precompiled artifacts target the architecture's baseline CPU by default (`portable: true`), so one built on a newer machine loads on any host of that architecture; `tools/precompile-guests.php` precompiles a set of guests with checksums, and Lambda/Bref releases ship the TypeScript guest precompiled for each `.so` (without fuel metering).

### Changed

- Shared one compiled `Engine`/`Module`/`InstancePre` process-wide between Runtimes built from identical guest bytes and engine options, so repeat construction costs an instantiation instead of another compile, while each Runtime keeps its own Store, instance, limits, deadlines, fuel budget and capability table. Up to eight compiled guests stay resident per process.
- Cached the parsed SDK declaration file inside the TypeScript compiler context, so checking a new source against unchanged `setTypes()` declarations no longer re-parses and re-binds them — removing a per-check cost that grew with the size of the `.d.ts`.

## [1.2.1] - 2026-08-28

### Changed

- Reused the last TypeScript Program and checker for identical source and SDK declarations, reducing repeated compilation during shared `check()` followed by `eval()` while preserving complete diagnostics and current per-call options (#4).

## [1.2.0] - 2026-08-27

### Added

- Added optional per-call `timeoutMs` to `eval()`, `check()` and `analyze()` on the native Runtime and PHP facade, including guest initialization for explicit positive timeouts. Omitted/null retains the constructor default's setup exemption; zero overrides are unbounded and negative overrides are rejected.

### Fixed

- Enabled timed operations on runtimes originally constructed without a timeout, without rebuilding their compiled module.
- Scoped timers to individual operations with cancellation and joining, and checked each Store's own deadline during nested isolated calls.

## [1.1.0] - 2026-08-21

### Added

- Added synchronous-only guest execution that rejects or reports incomplete asynchronous work.
- Added TypeScript call-site analysis with canonical JSON Schema extraction for capability type arguments.
- Added line information to extracted schemas for precise host-side diagnostics.
- Added an ES2024 TypeScript declaration surface constrained to APIs supported by the embedded engine.
- Added reproducible PHP 8.4 Bref/Lambda x86-64 release artifacts with checksums, provenance, and redistributed licence notices.

### Changed

- Updated the TypeScript compiler to 6.0.3, QuickJS-ng to 0.16.2, and Wasmtime to 46.0.3.
- Made TypeScript guest pre-initialization and committed WASM fixture generation deterministic.
- Hardened guest source downloads and builds with pinned, verified third-party inputs.

### Fixed

- Rejected unsupported `Intl`, syntax, and non-JSON numeric literal types during checking instead of failing later at runtime.
- Matched schema-producing calls by their TypeScript declarations rather than by textual names.
- Detected registered promise reactions that would otherwise allow a partial guest run to report success.
- Cleared two RustSec advisories through the Wasmtime upgrade.

[1.4.0]: https://github.com/eddmann/terrarium/compare/v1.3.0...v1.4.0
[1.3.0]: https://github.com/eddmann/terrarium/compare/v1.2.1...v1.3.0
[1.2.1]: https://github.com/eddmann/terrarium/compare/v1.2.0...v1.2.1
[1.2.0]: https://github.com/eddmann/terrarium/compare/v1.1.0...v1.2.0
[1.1.0]: https://github.com/eddmann/terrarium/compare/v1.0.0...v1.1.0

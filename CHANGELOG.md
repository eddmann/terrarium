# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/).

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

[1.3.0]: https://github.com/eddmann/terrarium/compare/v1.2.1...v1.3.0
[1.2.1]: https://github.com/eddmann/terrarium/compare/v1.2.0...v1.2.1
[1.2.0]: https://github.com/eddmann/terrarium/compare/v1.1.0...v1.2.0
[1.1.0]: https://github.com/eddmann/terrarium/compare/v1.0.0...v1.1.0

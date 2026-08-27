# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/).

## [Unreleased]

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

[Unreleased]: https://github.com/eddmann/terrarium/compare/v1.1.0...HEAD
[1.1.0]: https://github.com/eddmann/terrarium/compare/v1.0.0...v1.1.0

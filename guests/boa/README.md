# Boa guest — JavaScript (pure Rust)

[Boa](https://github.com/boa-dev/boa) `0.20`, a JavaScript engine written in
Rust, compiled to `wasm32-unknown-unknown`. **No C toolchain** — a plain
`cargo build`, no WASI SDK. The alternative JS guest to
[QuickJS](../quickjs/README.md).

## Build

```sh
make boa-guest      # rustup target add wasm32-unknown-unknown
```

Builds the `boa-guest` cdylib for `wasm32-unknown-unknown` and copies
`boa_guest.wasm` to `tests/wasm/` (the committed fixture).

## Build internals

- **`boa_engine` with `default-features = false`** — drops the heavy ICU/`Intl`
  data, keeping the fixture small.
- **`getrandom` custom backend** — a bare `wasm32-unknown-unknown` guest has no OS
  entropy source, so a custom (deterministic) `getrandom` backend is registered so
  Boa's RNG / hash-seeding links. Fine for a sandboxed guest.
- **Release profile** — `opt-level = "s"`, `lto`, `strip`, `panic = "abort"`.
- **Capability-only** — imports no WASI; a bare instance can only compute and call
  `host_call`. Zero ambient authority by construction.

## The guest contract

Exports `memory`, `guest_alloc`, `eval`, `check`; imports `host_call`. The
prelude installs registered names as JS globals and routes `console.*` to
`output()`, identical to the QuickJS guest's surface.

## Notes

- On a guest error, Boa reports the **error type and message** but not a reliable
  source line — its public API exposes no span (see
  [errors](../../docs/errors.md#source-lines-stay-exact)). The other guests report
  the line.
- `check()` is a parse check (`SyntaxError` diagnostics).

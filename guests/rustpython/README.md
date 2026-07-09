# RustPython guest — Python (pure Rust)

[RustPython](https://github.com/RustPython/RustPython) `0.5`, a Python 3
interpreter written in Rust, compiled to `wasm32-unknown-unknown`. **No C
toolchain** — a plain `cargo build`, no WASI SDK.

## Build

```sh
make rustpython-guest      # rustup target add wasm32-unknown-unknown
```

Builds the `rustpython-guest` cdylib for `wasm32-unknown-unknown` and copies
`rustpython_guest.wasm` to `tests/wasm/` (the committed fixture).

## Build internals

- **`rustpython-vm` with `default-features = false` + `compiler`** — no bundled
  stdlib (builtins only) keeps the fixture lean; `compiler` enables `eval`.
- **Two `getrandom` backends** — RustPython pulls both `getrandom` 0.2 (custom
  feature) and 0.3 (custom backend, selected via `--cfg getrandom_backend="custom"`
  in `.cargo/config.toml`). A bare wasm guest has no OS entropy, so `src/lib.rs`
  provides both hooks (`__getrandom_v03_custom` + the 0.2 shim).
- **Release profile** — `opt-level = "s"`, `lto`, `strip`, `panic = "abort"`.

## The guest contract

Exports `memory`, `guest_alloc`, `eval`, `check`; imports `host_call`. The
prelude installs registered capability names as Python globals (reached as
`user.fetch(...)`) and routes `print` through the reserved `$out` capability into
`output()`.

Types reach the guest author as a Python stub — `types('pyi')` emits
`TypedDict`s inferred from the registered PHP signatures.

## Notes

- `check()` is a syntax/compile check (`[]` = compiles); Python's static typing
  stays in the editor via the generated `.pyi`.
- No filesystem, network, or OS access: a bare instance imports no WASI.

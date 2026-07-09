# Terrarium — documentation

Implementation notes and internals for the Terrarium extension. For the
user-facing overview and quick start, see the [project README](../README.md).

- **[Installation](install.md)** — the three pieces (extension binary, PHP
  library, guest wasm), the release matrix, AWS Lambda (Bref), and building from
  source.
- **[API reference](api.md)** — the `Terrarium` class and every method.
- **[Architecture](architecture.md)** — why WebAssembly gives in-process
  isolation native embedding can't, the capability bridge and `host_call` ABI,
  value marshaling, the type-inference story, and the trust model.
- **[Execution modes](execution-modes.md)** — shared vs. isolated wasm instances,
  what a fault does, and `reset()`.
- **[Errors](errors.md)** — the two failure layers, the typed exception family,
  the `$error` sentinel, output capture, and source-line exactness.

Each bundled engine has its own notes under [`guests/`](../guests):
[boa](../guests/boa/README.md), [quickjs](../guests/quickjs/README.md),
[rustpython](../guests/rustpython/README.md), [php](../guests/php/README.md),
[typescript](../guests/typescript/README.md).

Runnable examples live in [`examples/`](../examples):

- [`four_langs.php`](../examples/four_langs.php) — one SDK, four guest languages.
- [`inferred_types.php`](../examples/inferred_types.php) — the generated
  `.d.ts`/`.pyi`/`.php`.
- [`js_and_python.php`](../examples/js_and_python.php) — the same capabilities
  from JS and Python.
- [`typescript.php`](../examples/typescript.php) — checking against the SDK.

```sh
make build
php -d extension=$(pwd)/target/debug/libterrarium.so examples/four_langs.php
```

## Source map

| File | Responsibility |
|------|----------------|
| `src/lib.rs` | The `Terrarium\Runtime` engine primitive: load → instantiate → `invoke`; execution modes (shared/isolated via `InstancePre`); resource limits (`StoreLimits`, epoch interruption, fuel, `max_wasm_stack`); mapping every fault to the typed exception family. |
| `src/bridge.rs` | The trust boundary: the flat dispatch table of registered PHP callables, reached through the single `host_call` ABI. |
| `src/marshal.rs` | Value marshaling — `PHP zval ↔ MiddleValue ↔ native msgpack`, the wire form carried over linear memory. |
| `src/handles.rs` | The capability handle table (`int → live zval`): `grant` / `resolve` / `revoke`. |
| `src/exceptions.rs` | The typed `Terrarium\Exception` classes. |
| `lib/Terrarium.php` | The public `Terrarium` facade: `register` / `eval` / `check` / `output` / `types` / `grant` / `resolve` / `revoke` / `manifest` / `reset`. |
| `lib/TypeInference.php` | The inference trait: PHP Reflection + PHPDoc → `.d.ts` / `.pyi` / `.php`. |
| `lib/PhpDocType.php` | A recursive-descent parser for the PHPDoc/PHPStan type grammar → a neutral type AST (nested `array{…}` shapes, unions, `?T`, `T[]`, …). |
| `guests/` | The language engines, one directory each — see their READMEs. |

## Stack

- **[`ext-php-rs`](https://github.com/davidcole1340/ext-php-rs)** — the Zend
  extension API; makes `Terrarium\Runtime` a native PHP class. RAII deletes the
  manual `zval` refcounting bug class.
- **[`wasmtime`](https://wasmtime.dev/)** — the WebAssembly runtime (Cranelift +
  native trap handling). The isolation boundary and every resource limit
  (`StoreLimits`, epoch interruption, fuel) map onto it.
- **`wasmtime-wasi`** — WASI Preview 1, so libc-based guests (QuickJS, PHP from
  the WASI SDK) get a working clock/random; capability-only guests import none of
  it.
- **`rmp-serde`** — the native-msgpack wire format across the `host_call`
  boundary.
- **[`wizer`](https://github.com/bytecodealliance/wizer)** — build-time only, for
  the [TypeScript guest](../guests/typescript/README.md) snapshot.

## Threading

PHP here is **NTS** (non-thread-safe) — one OS thread. The Wasmtime `Engine`,
`Store`, `Instance`, all `zval`s, and the bridge state live on that thread, so
the implementation uses `Rc`/`RefCell` rather than `Arc`/`Mutex`. Nothing crosses
a thread boundary.

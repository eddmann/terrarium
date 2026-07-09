# PHP guest — PHP-in-PHP

Real [php-src](https://github.com/php/php-src) `8.3.14` via its **embed SAPI**,
cross-compiled to `wasm32-wasi` with the WASI SDK. Untrusted PHP runs sandboxed
*inside* your PHP process — part of why the project is named Terrarium.

## Build

```sh
make php-guest      # WASI_SDK=/path/to/wasi-sdk   (and bison >= 3)
```

`build.sh` cross-compiles a **minimal** php-src (Zend + core only, embed SAPI, no
bundled extensions) to `wasm32-wasi`, then links `php_guest.c` against the static
`libphp`, producing `tests/wasm/php_guest.wasm`. php-src's parser needs
bison ≥ 3 (macOS ships 2.3 — the script prefers a Homebrew keg).

> **Status: scaffold.** Cross-building php-src to WASI is iterative — the
> `configure` cache seed and the emulated-libc / setjmp-longjmp flags are the
> usual sticking points and may need extending for a given php-src version. See
> the [VMware Wasm Labs port](https://wasmlabs.dev/articles/php-wasm32-wasi-port/)
> for reference. The committed fixture works and is covered by the suite.

## Build internals & upstream shims

wasi-libc omits (or hides behind wasip1 guards) a slice of the POSIX surface
php-src assumes exists. Two mechanisms bridge the gap:

- **`wasi-shims/`** — replacement headers + weak stubs for users/groups
  (`grp.h`, `pwd.h`), sockets (`netdb.h`, `sys/un.h`), process control
  (`sys/wait.h`), `syslog.h`, and `ucontext.h`, plus `terrarium_wasi_compat.h`.
  Every stub fails cleanly (`ENOSYS`-style); a sandboxed SDK-calling guest never
  exercises them.
- **Emulated libc** — `_WASI_EMULATED_SIGNAL` / `_MMAN` / `_PROCESS_CLOCKS` /
  `_GETPID`, linked against the matching `wasi-emulated-*` libs.
- **`zend_bailout`** — PHP's `setjmp`/`longjmp` is lowered by LLVM
  (`-wasm-enable-sjlj`, linked against wasi-libc's `-lsetjmp`), with
  `-wasm-use-legacy-eh=false` so the emitted exception handling is the
  **standardized** encoding — the one Wasmtime executes (the host enables it via
  `Config::wasm_exceptions(true)`).

## The guest contract

Exports `memory`, `guest_alloc`, `eval`, `check`; imports `host_call`. The prelude
speaks PHP's idiom:

- Registered capabilities are reached as proxy objects — `$user->fetch(42)`,
  `$api->v1->hello(...)`, or an invokable `$ping()`.
- Submitted source runs inside an IIFE (`extract($GLOBALS)` in scope), so a
  top-level `return` is the eval result.
- `echo`/`print` is captured via a SAPI `ub_write` hook into `output()`.
- Uncaught exceptions **and** fatal errors (`zend_first_try` / `zend_catch`)
  become the `$error` sentinel → `Terrarium\GuestException`.
- `check()` is literally `php -l` over the same wrapped form `eval` runs.

## Notes

- Fibers compile but abort if used — real fibers need Asyncify.

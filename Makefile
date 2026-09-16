# Terrarium — build & test
#
# A plain cargo cdylib (no phpize). Load the built extension by absolute path
# with `php -d extension=...`. cargo names a cdylib `.dylib` on macOS and `.so`
# on Linux; PHP loads either given the full path.

PROFILE ?= debug
ifeq ($(PROFILE),release)
CARGO_FLAGS := --release
else
CARGO_FLAGS :=
endif

ifeq ($(shell uname -s),Darwin)
DYLIB_EXT := dylib
else
DYLIB_EXT := so
endif

EXT := $(CURDIR)/target/$(PROFILE)/libterrarium.$(DYLIB_EXT)
PHP := php -d extension=$(EXT)

# The runtime-only build (`--no-default-features`: no `compiler`, so no
# Cranelift) gets its own target directory. Its `.so` has the same name as the
# full build's, and the two are not interchangeable — one loads WebAssembly, the
# other only precompiled artifacts — so they must never share a path.
RUNTIME_DIR := $(CURDIR)/target/runtime
RUNTIME_EXT := $(RUNTIME_DIR)/$(PROFILE)/libterrarium.$(DYLIB_EXT)
RUNTIME_PHP := php -d extension=$(RUNTIME_EXT)
# Where `test-runtime` puts the artifacts it precompiles with the FULL build.
RUNTIME_ARTIFACTS := $(CURDIR)/target/runtime-artifacts

.PHONY: all build release build-runtime release-runtime test test-rust test-php test-runtime boa-guest rustpython-guest quickjs-guest php-guest typescript-guest guests example clean fmt

all: build

build:
	cargo build $(CARGO_FLAGS)

release:
	$(MAKE) build PROFILE=release

# The deployment build: no compiler, so it loads precompiled artifacts and
# nothing else (`Terrarium\Runtime::hasCompiler()` is false). Produce the
# artifacts with the matching FULL build — `tools/precompile-guests.php` — and
# ship them beside it.
build-runtime:
	cargo build $(CARGO_FLAGS) --no-default-features --target-dir $(RUNTIME_DIR)

release-runtime:
	$(MAKE) build-runtime PROFILE=release

# Rust unit tests (marshaling) + the PHP integration suites, for both builds.
test: build test-rust test-php test-runtime

test-rust:
	cargo test --lib

test-php: build
	@fail=0; \
	for t in tests/php/[0-9]*.php; do \
	  printf '\n=== %s ===\n' "$$t"; \
	  $(PHP) "$$t" || fail=1; \
	done; \
	exit $$fail

# The runtime-only build, end to end. Needs both builds: the full one
# precompiles the guests (the release pipeline's job), the runtime-only one
# loads what it produced — which is also the check that trimming the compiler
# out left the engine `Config` an artifact is bound to untouched.
# `tests/php/runtime/` is outside the `[0-9]*.php` glob above on purpose: the
# suite needs a second binary and a directory of artifacts.
test-runtime: build build-runtime
	@rm -rf $(RUNTIME_ARTIFACTS)
	@$(PHP) tools/precompile-guests.php $(RUNTIME_ARTIFACTS) \
	  tests/wasm/quickjs_guest.wasm tests/wasm/typescript_guest.wasm || exit 1; \
	$(PHP) tools/precompile-guests.php --fuel $(RUNTIME_ARTIFACTS)/fuel \
	  tests/wasm/quickjs_guest.wasm || exit 1; \
	fail=0; \
	for t in tests/php/runtime/*.php; do \
	  printf '\n=== %s ===\n' "$$t"; \
	  $(RUNTIME_PHP) "$$t" $(RUNTIME_ARTIFACTS) || fail=1; \
	done; \
	exit $$fail

# Rebuild the sandboxed guest language engines and refresh the committed wasm
# fixtures. The pure-Rust guests need the wasm32 target
# (rustup target add wasm32-unknown-unknown); QuickJS-ng needs a WASI SDK.
#   guests/boa/        — Boa, a JS engine in pure Rust
#   guests/rustpython/ — RustPython, a Python interpreter in pure Rust
#   guests/quickjs/    — QuickJS-ng, compiled from C via the WASI SDK
boa-guest:
	cd guests/boa && cargo build --target wasm32-unknown-unknown --release
	cp guests/boa/target/wasm32-unknown-unknown/release/boa_guest.wasm tests/wasm/boa_guest.wasm

rustpython-guest:
	cd guests/rustpython && cargo build --target wasm32-unknown-unknown --release
	cp guests/rustpython/target/wasm32-unknown-unknown/release/rustpython_guest.wasm tests/wasm/rustpython_guest.wasm

# Needs a WASI SDK (set WASI_SDK=/path), not the wasm32 rustup target.
quickjs-guest:
	cd guests/quickjs && ./build.sh

# PHP embedded via its embed SAPI, php-src cross-built to wasm32-wasi (WASI SDK).
php-guest:
	cd guests/php && ./build.sh

# QuickJS-ng + the real TypeScript compiler embedded as QuickJS bytecode:
# submitted TS is type-checked against the registered SDK, stripped, and run.
typescript-guest:
	cd guests/typescript && ./build.sh

guests: boa-guest rustpython-guest quickjs-guest php-guest typescript-guest

example: build
	$(PHP) examples/four_langs.php

fmt:
	cargo fmt

clean:
	cargo clean
	cd guests/boa && cargo clean
	cd guests/rustpython && cargo clean

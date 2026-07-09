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

.PHONY: all build release test test-rust test-php boa-guest rustpython-guest quickjs-guest php-guest typescript-guest guests example clean fmt

all: build

build:
	cargo build $(CARGO_FLAGS)

release:
	$(MAKE) build PROFILE=release

# Rust unit tests (marshaling) + the PHP integration suites.
test: build test-rust test-php

test-rust:
	cargo test --lib

test-php: build
	@fail=0; \
	for t in tests/php/[0-9]*.php; do \
	  printf '\n=== %s ===\n' "$$t"; \
	  $(PHP) "$$t" || fail=1; \
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

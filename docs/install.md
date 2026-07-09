# Installation

Terrarium has **three pieces**, and you need all three:

1. **The extension binary** — the Rust `cdylib` PHP loads (`libterrarium.so` /
   `.dylib`). Tied to an exact **OS · CPU arch · PHP minor version ·
   thread-safety**.
2. **The PHP library** (`lib/*.php`) — the public `Terrarium` facade and the type
   inference. Platform-independent.
3. **A guest engine** (`*_guest.wasm`) — the language engine the extension runs.
   Platform-independent; pick the language(s) you want.

Each [release](https://github.com/eddmann/terrarium/releases) attaches all three.

## Release artifacts

Per PHP 8.4 / 8.5, NTS:

| Artifact | For |
|---|---|
| `terrarium-vX-php8.4-linux-x86_64.so` / `-aarch64.so` | self-hosted Linux / Docker (glibc ≥ 2.35) |
| `terrarium-vX-php8.4-lambda-bref-x86_64.zip` / `-arm64.zip` | AWS Lambda via [Bref](https://bref.sh) (a ready Lambda layer) |
| `terrarium-vX-php8.4-lambda-bref-*.so` | Lambda / Amazon Linux 2023, if you prefer the raw `.so` (glibc ≥ 2.34) |
| `terrarium-vX-php8.4-macos-arm64.dylib` | local development on macOS (Apple Silicon) |
| `terrarium-vX-php-lib.zip` | the PHP library (`lib/`) — required, platform-independent |
| `terrarium-vX-guests.zip` | the guest engines (`*_guest.wasm`) — pick your language |

> The Lambda build is made **inside the Bref Amazon Linux 2023 image** so it
> links against glibc 2.34 and loads on Lambda; a binary built on Ubuntu links
> against a newer glibc and will fail to load there.

## Self-hosted (Linux / macOS / Docker)

Download the `.so`/`.dylib` matching your PHP version and arch, unzip the PHP
library and a guest, then enable the extension:

```ini
; php.ini  (find it with: php --ini)
extension=/path/to/terrarium-vX-php8.4-linux-x86_64.so
```

Verify:

```sh
php -d extension=/path/to/...so -r 'var_dump(class_exists("Terrarium\Runtime"));'
# bool(true)
```

Install the PHP library — either via Composer (recommended), which autoloads the
`Terrarium\` namespace and declares the `ext-terrarium` requirement:

```sh
composer require eddmann/terrarium
```

…or by unzipping `terrarium-vX-php-lib.zip` and requiring it directly. Then load
the facade and a guest in your script:

```php
require 'vendor/autoload.php';        // or: require '/path/to/lib/Terrarium.php';

use Terrarium\Terrarium;

$t = new Terrarium('/path/to/quickjs_guest.wasm');
echo $t->eval('1 + 1');   // 2
```

In Docker, copy the `.so` into the image and add the `extension=` line to a
`conf.d` ini:

```dockerfile
COPY terrarium-vX-php8.4-linux-x86_64.so /usr/local/lib/php/terrarium.so
RUN echo 'extension=/usr/local/lib/php/terrarium.so' > /usr/local/etc/php/conf.d/terrarium.ini
```

## AWS Lambda (Bref)

A Bref function is the runtime layer mounted at `/opt` plus your code at
`/var/task`. The release artifacts follow Bref's layout (`extension_dir` is
`/opt/bref/extensions`, inis scanned from `/opt/bref/etc/php/conf.d/`), so the
**same `.so` + ini work whether you go via a layer or a Docker image**. Match the
**architecture** (`arm64` for Graviton, `x86_64` otherwise) and **PHP version** to
your Bref runtime.

**Docker image** — bake the released `.so` into a `FROM bref/php-XX:3` image:

```dockerfile
FROM bref/php-84:3
COPY terrarium-vX-php8.4-lambda-bref-arm64.so /opt/bref/extensions/terrarium.so
RUN echo 'extension=terrarium.so' > /opt/bref/etc/php/conf.d/ext-terrarium.ini
COPY . /var/task
```

**Lambda layer** — the `lambda-bref-*.zip` is a ready layer (it contains
`bref/extensions/terrarium.so` and the ini). Publish it and reference its ARN
alongside the Bref runtime:

```sh
aws lambda publish-layer-version \
  --layer-name terrarium-php84-arm64 \
  --compatible-architectures arm64 \
  --zip-file fileb://terrarium-vX-php8.4-lambda-bref-arm64.zip
```

Ship the PHP library and the guest `.wasm` you need alongside your code in
`/var/task`.

## Build from source

A plain cargo `cdylib` — no `phpize`. Requires Rust 1.96+, clang, and PHP
8.4/8.5 dev headers (`php-config`).

```sh
git clone https://github.com/eddmann/terrarium && cd terrarium
make release      # -> target/release/libterrarium.so (or .dylib on macOS)
make test         # optional: Rust unit tests + the PHP suites
```

The guest `.wasm` fixtures are **committed** (`tests/wasm/*.wasm`), so nothing
above needs a wasm toolchain. You only rebuild a guest to *change* it:

```sh
make boa-guest rustpython-guest               # pure Rust: rustup target add wasm32-unknown-unknown
make quickjs-guest php-guest typescript-guest # C via a WASI SDK: WASI_SDK=/path
make guests                                   # all five
```

See each guest's README (`guests/<name>/README.md`) for its toolchain and build
details.

## Choosing the right binary

- **PHP version** must match exactly (an 8.4 extension won't load in 8.5).
- **Architecture** must match (`x86_64` vs `arm64`/`aarch64`).
- **glibc**: the Lambda/Bref build (glibc 2.34) is the most portable on Linux; the
  generic Linux build (glibc 2.35) needs a reasonably recent distro.
- All builds are **NTS** (non-thread-safe) — what CLI, FPM, and Bref use. The
  design assumes a single thread; do not use under a ZTS SAPI.

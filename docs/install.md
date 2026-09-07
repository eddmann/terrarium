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
| `terrarium-vX-php8.4-lambda-bref-*-precompiled-guests.zip` | the TypeScript guest precompiled for that exact Lambda `.so`, without fuel metering (see [Precompiling for deployment](#precompiling-for-deployment)) |
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

Ship the PHP library alongside your code in `/var/task`. On Lambda the module
cache cannot survive a cold start, so do not ship the guest `.wasm`: ship its
**precompiled artifact** instead and construct with `precompiled: true`. Every
Lambda build in a release comes with the TypeScript guest (the heavy one)
already precompiled for that exact `.so`, as
`terrarium-vX-phpY-lambda-bref-ARCH-precompiled-guests.zip`, compiled without
fuel metering. Take the zip that matches the layer or `.so` you deploy, unpack
`typescript_guest.cwasm` into `/var/task`, and check it against the
`SHA256SUMS` inside:

```php
$ts = new Terrarium\Terrarium(__DIR__ . '/typescript_guest.cwasm', precompiled: true);
```

Any other guest, a Runtime constructed with `fuel:` (fuel metering is compiled
in, so it needs its own artifact), or any other build of the extension, you
precompile yourself — see [precompiling for deployment](#precompiling-for-deployment).

## Precompiling for deployment

Constructing a guest compiles its wasm with Cranelift: one to two seconds for a
heavy guest, paid by the **first construction in each process**. Wasmtime's
on-disk module cache normally absorbs that, but a deployment with no writable
(or no persistent) cache directory — an AWS Lambda function, where `$HOME` is
read-only and each cold start is a fresh filesystem — pays it on every cold
start.

`Terrarium::precompile()` moves that work into your build pipeline. It returns
the compiled artifact; the deployed process loads it with `precompiled: true`
and only deserializes, which also removes the dependency on a writable cache
directory entirely.

```sh
# build step — run with the SAME extension build you are deploying
php -d extension=./terrarium.so -r '
    require "vendor/autoload.php";
    file_put_contents("guest.cwasm", Terrarium\Terrarium::precompile("typescript_guest.wasm"));
'
```

`tools/precompile-guests.php` does the same for a whole set of guests and
writes a `SHA256SUMS` beside them — it is what the release workflow runs:

```sh
php -d extension=./terrarium.so tools/precompile-guests.php out/ tests/wasm/*.wasm
php -d extension=./terrarium.so tools/precompile-guests.php --fuel out-fuel/ tests/wasm/*.wasm
```

```php
// deployed (e.g. in /var/task alongside the .so)
$t = new Terrarium\Terrarium(__DIR__ . '/guest.cwasm', precompiled: true);
```

Measured on the TypeScript guest (release build, 4 CPUs), the first
construction in a fresh process: **~1.6 s** from wasm with an empty module
cache, **~0.33 s** from wasm with a warm module cache, and **~0.11 s** from its
artifact, which needs no cache at all.

Three rules:

- **Generate it with the extension build that will load it.** An artifact is
  bound to that exact Wasmtime version, target and configuration; anything else
  is refused with a `Terrarium\Exception`. Regenerate it whenever you upgrade
  the extension — treat it as a build output, not a checked-in file.
- **Pass the same `fuel` setting on both ends.** Fuel metering is compiled in,
  so an artifact built with `fuel:` set loads only into a Runtime with `fuel:`
  set, and vice versa. The budget itself, `maxStack`, `memoryLimit` and
  `timeoutMs` are free to differ.
- **Precompile for the architecture, not the build machine.** By default
  `precompile()` targets the baseline CPU of the architecture (`portable:
  true`), because an artifact records the CPU features it was compiled to use
  and a host lacking one refuses it: a CI runner with AVX-512 would otherwise
  produce an artifact a plainer Lambda host cannot load. Pass `portable: false`
  only when the machine that precompiles is the machine that runs.
- **An artifact is native code — trust it like the `.so`.** Wasmtime does not
  validate it, and it runs *outside* the sandbox with the host's authority. It
  must come from your own pipeline, never from user input. Nothing is
  auto-detected: `precompiled: true` is your explicit statement about those
  bytes, and loading wasm with it (or an artifact without it) is refused.

Artifacts are larger than the wasm they came from (the TypeScript guest: 29 MB
→ 45 MB), so budget for the package size.

The same trust applies to Wasmtime's on-disk module cache
(`$XDG_CACHE_HOME/wasmtime`), which the extension enables on every engine: it
also holds native code, so it must not be writable by anyone you would not let
replace the `.so`. A deployment that loads artifacts never needs that cache.

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

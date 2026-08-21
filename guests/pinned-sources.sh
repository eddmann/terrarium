#!/usr/bin/env bash
# Pinned third-party sources, and the checksums that make the pins mean
# something. Sourced by guests/quickjs/build.sh and guests/typescript/build.sh.
#
# The two guests share the quickjs-ng pin because the TypeScript guest embeds
# the compiler as qjsc BYTECODE, and the bytecode format is version-locked
# (v0.15.1 emitted BC_VERSION 26, v0.16.2 emits 27) — so the pin lives here
# once rather than being duplicated and drifting.
#
# ## Why checksums, and what each one covers
#
# The fixtures are claimed to be byte-for-byte reproducible on a pinned
# toolchain. That claim is only as good as the INPUTS: a pinned version string
# says which release was asked for, not which bytes arrived. So every fetch is
# verified after the fact, and a mismatch is a hard failure rather than a
# warning — a build from unexpected sources must not silently produce a
# "reproducible" artifact.
#
#   npm tarballs      the .tgz is content-addressed by the registry and stable
#                     forever, so its sha256 is pinned directly.
#
#   quickjs-ng        fetched EITHER from the GitHub archive tarball OR by
#                     `git clone --branch <tag>` (proxies commonly 403 the
#                     codeload redirect). A generated archive tarball is not
#                     guaranteed byte-stable across GitHub's own tooling, so
#                     pinning its .tar.gz hash would be pinning the wrong
#                     thing. Instead the extracted TREE is verified — the
#                     sha256 of a sorted "<sha256>  <name>" manifest over every
#                     top-level .c/.h, which is exactly the set the build
#                     compiles, and which both fetch paths must agree on.
#                     The git path additionally asserts the tag resolves to the
#                     pinned COMMIT before .git is dropped, so that path is
#                     verified twice, independently.
#
# ## Updating a pin
#
# Change the version, run the build, and it will fail with the digest it
# actually saw; verify that against upstream and paste it in. There is no
# "update the checksum automatically" path on purpose.

QJS_VERSION="${QJS_VERSION:-v0.16.2}"
# `git rev-parse v0.16.2^{commit}` at github.com/quickjs-ng/quickjs.
QJS_COMMIT="${QJS_COMMIT:-1ab8676f4b6d6d669baeb5f21790fb9734636a20}"
# Manifest digest over the tree's top-level *.c / *.h (see qjs_tree_digest).
QJS_SOURCE_SHA256="${QJS_SOURCE_SHA256:-3b1d1cadd997b5b0513721709f28c0bea1e653bc0bdfbd3c578e123bfce29594}"

TS_VERSION="${TS_VERSION:-6.0.3}"
TS_SHA256="${TS_SHA256:-33cd0ee1beaa8c9e9d15a9da836c62ddea4c34a42d7c2d349dbc80d94165d22a}"
TBS_VERSION="${TBS_VERSION:-0.9.0}"
TBS_SHA256="${TBS_SHA256:-4af7fbeb6f098cdebaea7804d0e0ad8bfef2cf80c69cfdaac9b588a0ceba837f}"

# sha256, portably: coreutils on Linux, shasum on macOS.
sha256_stdin() {
    if command -v sha256sum >/dev/null 2>&1; then
        sha256sum | cut -d' ' -f1
    elif command -v shasum >/dev/null 2>&1; then
        shasum -a 256 | cut -d' ' -f1
    else
        echo "no sha256 tool found (need sha256sum or shasum)" >&2
        exit 1
    fi
}

sha256_file() { sha256_stdin < "$1"; }

# Fail loudly, and say what to do about it.
verify_sha256() {
    local file="$1" expected="$2" label="$3" actual
    actual="$(sha256_file "$file")"
    if [ "$actual" != "$expected" ]; then
        cat >&2 <<EOF
checksum mismatch for $label

  expected: $expected
  actual:   $actual
  file:     $file

Refusing to build: the fixture is only reproducible if its inputs are the
pinned ones. If you changed the version, verify the new digest against
upstream and update guests/pinned-sources.sh.
EOF
        exit 1
    fi
    echo "  sha256 ok: $label"
}

# The digest of a quickjs-ng tree: sorted "<sha256>  <name>" lines over every
# top-level .c/.h, hashed. Path-independent and fetch-method-independent, so
# the tarball and the git clone are held to the same value.
qjs_tree_digest() {
    (
        cd "$1"
        # shellcheck disable=SC2012  # names are plain ASCII; sorted for stability
        ls ./*.c ./*.h 2>/dev/null | sed 's|^\./||' | LC_ALL=C sort | while read -r f; do
            printf '%s  %s\n' "$(sha256_file "$f")" "$f"
        done
    ) | sha256_stdin
}

verify_qjs_tree() {
    local dir="$1" actual
    actual="$(qjs_tree_digest "$dir")"
    if [ "$actual" != "$QJS_SOURCE_SHA256" ]; then
        cat >&2 <<EOF
quickjs-ng source digest mismatch ($QJS_VERSION)

  expected: $QJS_SOURCE_SHA256
  actual:   $actual
  tree:     $dir

This is the sha256 of a sorted manifest over the tree's top-level *.c/*.h —
the exact sources the build compiles. Refusing to build: the fixture would not
be the reproducible one. If you moved the pin, verify the new digest and update
guests/pinned-sources.sh.
EOF
        exit 1
    fi
    echo "  source digest ok: quickjs-ng $QJS_VERSION"
}

# Fetch quickjs-ng $QJS_VERSION into $1 (a directory that must not exist yet),
# by tarball with a git-clone fallback, and verify what arrived.
fetch_quickjs() {
    local dest="$1"
    [ -d "$dest" ] && return 0

    echo "Fetching quickjs-ng $QJS_VERSION ..."
    rm -rf "$dest.partial"
    mkdir -p "$dest.partial"
    if curl -fsSL "https://github.com/quickjs-ng/quickjs/archive/refs/tags/${QJS_VERSION}.tar.gz" \
        | tar xz -C "$dest.partial" --strip-components=1; then
        echo "  fetched the release tarball"
    else
        echo "  tarball fetch failed, falling back to git clone ..."
        rm -rf "$dest.partial"
        git clone --quiet --depth 1 --branch "$QJS_VERSION" \
            https://github.com/quickjs-ng/quickjs "$dest.partial"
        # Verify the tag resolves to the pinned commit BEFORE dropping .git:
        # an independent check on this path, since a tag can be moved.
        local head
        head="$(git -C "$dest.partial" rev-parse HEAD)"
        if [ "$head" != "$QJS_COMMIT" ]; then
            echo "quickjs-ng $QJS_VERSION resolved to $head, expected $QJS_COMMIT" >&2
            exit 1
        fi
        echo "  commit ok: $head"
        rm -rf "$dest.partial/.git"
    fi

    verify_qjs_tree "$dest.partial"
    mv "$dest.partial" "$dest"
}

# Fetch and verify an npm package tarball into $1 (extracted, strip 1).
fetch_npm() {
    local dest="$1" name="$2" version="$3" expected="$4"
    [ -d "$dest" ] && return 0

    echo "Fetching $name $version ..."
    curl -fsSL "https://registry.npmjs.org/${name}/-/${name}-${version}.tgz" -o "$dest.tgz"
    verify_sha256 "$dest.tgz" "$expected" "$name@$version"
    mkdir -p "$dest.partial"
    tar xz -C "$dest.partial" --strip-components=1 -f "$dest.tgz"
    mv "$dest.partial" "$dest"
}

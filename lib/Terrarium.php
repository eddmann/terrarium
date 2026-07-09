<?php

declare(strict_types=1);

namespace Terrarium;

require_once __DIR__ . '/TypeInference.php';

/**
 * Terrarium — expose a typed PHP SDK to a sandboxed guest language.
 *
 * You write capabilities as ordinary PHP closures and `register()` them; an
 * untrusted guest program (JavaScript, TypeScript, or Python) runs inside a
 * WebAssembly sandbox and calls them directly by their registered names.
 * WebAssembly is the hard, in-process isolation boundary: a bug in the guest's
 * language engine cannot reach the host.
 *
 *   $wasm = new Terrarium('boa_guest.wasm', timeoutMs: 500, memoryLimit: 32 << 20);
 *   $wasm->register('user.fetch', fn (int $id): array => [...]);
 *   $roles = $wasm->eval('user.fetch(42).roles.length');
 *   echo $wasm->types('dts');   // a .d.ts for the registered SDK ('pyi' for Python)
 *
 * This is the single, uniform API. The guest's language is decided purely by
 * which `*_guest.wasm` you load (Boa for JS, QuickJS-ng, RustPython for Python);
 * the host extension is language-agnostic. There is no synthetic root: a
 * capability registered as `user.fetch` is reached as `user.fetch(...)` in the
 * guest, and `types()` infers that shape from the registered closures'
 * signatures (+ PHPDoc) — see TypeInference.
 *
 * It wraps the low-level `TerrariumRuntime` engine primitive provided by the Rust
 * extension; the inference and `eval()` ergonomics live here in PHP.
 */
final class Terrarium
{
    use TypeInference;

    /**
     * Names the guest preludes use for the bridge itself -- registering one
     * would clobber the machinery that dispatches every capability call.
     */
    private const RESERVED_NAMES = ['__host', '_hostcall', '__ns', '_NS', '__TerrariumNs'];

    /**
     * Top-level names that shadow a builtin in at least one bundled guest
     * (JS `console`/`Math`/`JSON`/`globalThis`, the Python `print` the prelude
     * routes to output capture). Registering one works -- there is no synthetic
     * root, so the name becomes a guest global -- but it hides the builtin, so
     * it warrants a warning rather than silence.
     */
    private const SHADOW_PRONE_NAMES = ['console', 'print', 'Math', 'JSON', 'globalThis'];

    private Runtime $rt;

    /**
     * Load a guest from a `.wasm` file. Limits default to unbounded; pass
     * non-zero values to contain resource abuse. `isolated: true` runs each
     * `eval()` in a fresh wasm instance; the default reuses one (cheaper, and it
     * keeps engine-internal state such as the TypeScript compiler warm). Guests
     * run each eval in a fresh runtime either way, so guest program globals do
     * not carry across evals in either mode.
     */
    public function __construct(
        string $path,
        ?int $memoryLimit = null,
        ?int $timeoutMs = null,
        ?int $maxStack = null,
        ?int $fuel = null,
        bool $isolated = false,
    ) {
        $bytes = @file_get_contents($path);
        if ($bytes === false) {
            throw new \RuntimeException("cannot read guest wasm: $path");
        }
        $this->rt = new Runtime(
            $bytes,
            memoryLimit: $memoryLimit,
            timeoutMs: $timeoutMs,
            maxStack: $maxStack,
            fuel: $fuel,
            isolated: $isolated,
        );
    }

    /**
     * Expose a PHP callable to the guest under its dotted name, reached as
     * `<dotted.name>(...)` (no synthetic root). The allowlist of registered names
     * is the entire trust boundary. Types are inferred from the signature (+
     * PHPDoc) and surfaced by `types()`.
     */
    public function register(string $name, callable $fn): void
    {
        // Every dotted segment becomes a guest identifier (a global, an
        // attribute, a property), so each must be one. This also rejects the
        // bridge-reserved `$`-prefixed names ($out, $names, $error).
        $parts = explode('.', $name);
        foreach ($parts as $part) {
            if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $part)) {
                throw new \InvalidArgumentException(
                    "invalid capability name '$name': every dotted segment must be an identifier"
                );
            }
        }
        if (in_array($parts[0], self::RESERVED_NAMES, true)) {
            throw new \InvalidArgumentException(
                "invalid capability name '$name': '{$parts[0]}' is reserved by the guest bridge"
            );
        }
        if (in_array($parts[0], self::SHADOW_PRONE_NAMES, true)) {
            trigger_error(
                "capability '$name' shadows the guest builtin '{$parts[0]}' (no synthetic root: top-level names become guest globals)",
                E_USER_WARNING
            );
        }
        $this->inferTypes($name, $fn);
        $this->rt->register($name, $fn);
        // Keep the reserved `$dts` capability current: a type-aware guest (the
        // TypeScript guest) checks submitted source against this declaration.
        $this->rt->setTypes($this->dts());
    }

    /**
     * Evaluate guest source and return its result marshaled to a PHP value. A
     * guest-side error (a thrown JS exception, a Python traceback) is raised as
     * a TerrariumGuestException whose message reads `Type: message (line N)`.
     *
     * Anything the guest writes with `console.log` (JS) or `print` (Python) is
     * captured; read it with `output()` after the call.
     */
    public function eval(string $source): mixed
    {
        return $this->rt->eval($source);
    }

    /**
     * Statically validate guest source WITHOUT running it. Returns every
     * diagnostic as `{message, type?, line?}`; an empty array means the source
     * passed. Nothing executes: no capability can fire and `output()` is
     * untouched.
     *
     * The depth is the strongest the guest's language offers: the TypeScript
     * guest type-checks against the registered SDK (and ignores `@ts-nocheck` —
     * an explicit check asks for the diagnostics); the JS, Python, and PHP
     * guests report syntax/compile errors (`[]` means "compiles", not
     * "correct" — their type story stays in the editor via `types()`).
     *
     * @return list<array{message: string, type?: string, line?: int}>
     */
    public function check(string $source): array
    {
        return $this->rt->check($source);
    }

    /**
     * The guest output (`console.log` / `print`) captured during the most recent
     * `eval`, lines joined by "\n". Preserved even when that `eval` threw, so
     * output printed before a crash is still readable.
     */
    public function output(): string
    {
        return $this->rt->output();
    }

    /**
     * The generated type declaration for the registered SDK, inferred from the
     * registered closures: `'dts'` for a TypeScript `.d.ts`, `'pyi'` for a
     * Python stub, `'php'` for a PHP stub (the PHP guest's view).
     */
    public function types(string $format = 'dts'): string
    {
        return match ($format) {
            'dts'  => $this->dts(),
            'pyi'  => $this->pyi(),
            'php'  => $this->php(),
            default => throw new \InvalidArgumentException("unknown types format: $format (use 'dts', 'pyi', or 'php')"),
        };
    }

    /** The registered capability names, sorted (the audit surface). */
    public function manifest(): array
    {
        return $this->rt->manifest();
    }

    /**
     * Grant a live PHP object to the guest as an opaque handle. The object never
     * crosses into the sandbox; the guest can only pass the handle back to a
     * capability, which calls `resolve()`.
     */
    public function grant(mixed $resource): int
    {
        return $this->rt->grant($resource);
    }

    /** Resolve a handle back to the live PHP object (used inside capabilities). */
    public function resolve(int $handle): mixed
    {
        return $this->rt->resolve($handle);
    }

    /** Release a granted handle. Returns whether it existed. */
    public function revoke(int $handle): bool
    {
        return $this->rt->revoke($handle);
    }

    /**
     * Drop the persistent shared instance so the next `eval()` re-instantiates
     * the guest (re-warming engine state such as the TypeScript compiler). No-op
     * in isolated mode. Returns whether one existed.
     */
    public function reset(): bool
    {
        return $this->rt->reset();
    }
}

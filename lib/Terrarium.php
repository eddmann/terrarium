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
     *
     * `syncOnly: true` asks a compiling guest to reject asynchronous and
     * generator syntax outright. No guest drains a job queue, so an `await`
     * never resumes; the TypeScript guest turns that into a compile error that
     * names the construct and the synchronous alternative, at the source line.
     * It is a host constraint rather than an author preference, so it holds
     * even under `// @ts-nocheck`. Guests without a compiler accept the option
     * and ignore it — they fail loudly at run time instead (see `eval()`).
     *
     * `typeArgumentSchemas: ['ctx.model', 'ctx.agent']` asks a compiling guest
     * to derive a JSON Schema from the single type argument of every call to
     * those callees, and to return them from `analyze()`. Nothing else changes:
     * `eval()` and `check()` behave exactly as before, and a call to a listed
     * callee written WITHOUT a type argument is untouched.
     *
     * @param list<string>|null $typeArgumentSchemas
     */
    public function __construct(
        string $path,
        ?int $memoryLimit = null,
        ?int $timeoutMs = null,
        ?int $maxStack = null,
        ?int $fuel = null,
        bool $isolated = false,
        bool $syncOnly = false,
        ?array $typeArgumentSchemas = null,
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
        $options = [];
        if ($syncOnly) {
            $options['sync_only'] = true;
        }
        if ($typeArgumentSchemas !== null && $typeArgumentSchemas !== []) {
            // A misspelt option would otherwise extract nothing, in silence.
            foreach ($typeArgumentSchemas as $callee) {
                if (!is_string($callee) || !preg_match('/^[A-Za-z_][A-Za-z0-9_]*(\.[A-Za-z_][A-Za-z0-9_]*)*$/', $callee)) {
                    throw new \InvalidArgumentException(
                        'invalid typeArgumentSchemas entry: every callee must be a dotted identifier chain, e.g. "ctx.model"'
                    );
                }
            }
            $options['type_argument_schemas'] = array_values($typeArgumentSchemas);
        }
        if ($options !== []) {
            $this->rt->setCompileOptions($options);
        }
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
     *
     * A program that cannot finish because it is asynchronous fails loudly
     * rather than half-running: on the QuickJS-based guests (JavaScript,
     * TypeScript) an eval that yields a Promise, or that leaves callbacks
     * queued, raises a TerrariumGuestException of type `AsyncIncomplete` —
     * there is no event loop to resume it. Output printed before that point is
     * preserved, as with any other guest error.
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
     * With `syncOnly: true`, the TypeScript guest also lists EVERY async or
     * generator construct as a `TSSyncOnly` diagnostic, ahead of the type
     * diagnostics and regardless of `@ts-nocheck`.
     *
     * @return list<array{message: string, type?: string, line?: int}>
     */
    public function check(string $source): array
    {
        return $this->rt->check($source);
    }

    /**
     * The same static pass as `check()`, with everything the guest was asked to
     * extract alongside the diagnostics:
     *
     *     ['diagnostics' => [...], 'schemas' => [...]]
     *
     * `diagnostics` is byte-for-byte what `check()` returns. `schemas` is empty
     * unless the guest was constructed with `typeArgumentSchemas:`, in which
     * case the TypeScript guest returns one entry per matched call:
     *
     *     ['ordinal' => 0, 'callee' => 'ctx.agent', 'line' => 1, 'schema' => '{"type":"object",…}']
     *
     * `schema` is canonical JSON TEXT — fixed key order, no whitespace — so the
     * same source always yields byte-identical bytes to bake, store, or hash.
     * `ordinal` is the call's 0-based index among matched calls in source order,
     * and is the ONLY identity offered: a line:column would move every time the
     * file is reformatted, while the ordinal survives renaming, rewrapping and
     * commenting. A matched call whose type argument has no JSON Schema form
     * still consumes its ordinal — it appears in `diagnostics` as a
     * `TSSchemaError` carrying that ordinal — so one bad call cannot renumber
     * the others.
     *
     * `line` is not a second identity but a runtime bridge: the 1-based line of
     * the CALL's start (the same convention the diagnostics use), carried beside
     * `schema` and never inside it, for consumers whose compiled artifact is
     * immutable per version and must therefore key their baked schemas by line.
     * Entries are sorted by start position, so `line` is non-decreasing and two
     * matched calls on one line share it.
     *
     * Nothing executes, exactly as with `check()`.
     *
     * @return array{
     *     diagnostics: list<array{message: string, type?: string, line?: int, ordinal?: int}>,
     *     schemas: list<array{ordinal: int, callee: string, line: int, schema: string}>
     * }
     */
    public function analyze(string $source): array
    {
        return $this->rt->analyze($source);
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

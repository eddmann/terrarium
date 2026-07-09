<?php
// Type inference in isolation: the SDK's types are derived from the PHP callables
// themselves (Reflection + PHPDoc) and emitted as TypeScript (.d.ts) and Python
// (.pyi) for whoever writes the guest code. Pure PHP — no guest/extension needed,
// which is why this drives the TypeInference trait directly.

declare(strict_types=1);

require __DIR__ . '/_harness.php';
use Terrarium\Terrarium;
use Terrarium\TypeInference;
use Terrarium\Exception as TerrariumException;
use Terrarium\TrapException;
use Terrarium\TimeoutException;
use Terrarium\MemoryException;
use Terrarium\GuestException;

/** A bare host carrying just the inference trait, for testing in isolation. */
final class TypeHost
{
    use TypeInference;

    public function reg(string $name, callable $fn): void
    {
        $this->inferTypes($name, $fn);
    }
}

echo "TypeScript (.d.ts) inference\n";
check('scalars + param names from reflection', function () {
    $h = new TypeHost();
    $h->reg('add', fn (int $a, string $b): bool => true);
    contains($h->dts(), 'declare const add: (a: number, b: string) => boolean;');
});
check('nullable -> "T | null"', function () {
    $h = new TypeHost();
    $h->reg('f', fn (?int $x): ?string => null);
    contains($h->dts(), 'declare const f: (x: number | null) => string | null;');
});
check('union types', function () {
    $h = new TypeHost();
    $h->reg('f', fn (int|string $v): int => 1);
    $d = $h->dts();
    // PHP normalises union order, so accept either.
    if (!str_contains($d, '(v: number | string) => number')
        && !str_contains($d, '(v: string | number) => number')) {
        throw new RuntimeException("union not rendered:\n$d");
    }
});
check('array element type from PHPDoc', function () {
    $h = new TypeHost();
    $h->reg('sum', /** @param int[] $xs */ fn (array $xs): int => 0);
    contains($h->dts(), 'declare const sum: (xs: number[]) => number;');
});
check('object shape (nested + optional key)', function () {
    $h = new TypeHost();
    $h->reg('user.fetch',
        /** @return array{name: string, meta: array{active: bool, score?: float}} */
        fn (int $id): array => []);
    contains($h->dts(), 'declare const user: {');
    contains($h->dts(), '(id: number) => { name: string; meta: { active: boolean; score?: number } }');
});
check('list of shapes (array{...}[])', function () {
    $h = new TypeHost();
    $h->reg('search', /** @return array{id: int}[] */ fn (): array => []);
    contains($h->dts(), '() => { id: number }[]');
});
check('namespaces nest from dotted names', function () {
    $h = new TypeHost();
    $h->reg('a.b.c', fn (): int => 1);
    $d = $h->dts();
    contains($d, 'declare const a: {');
    contains($d, 'readonly b: {');
    contains($d, 'readonly c: () => number;');
});
check('a flat name is a top-level declaration (no synthetic root)', function () {
    $h = new TypeHost();
    $h->reg('ping', fn (): int => 1);
    $d = $h->dts();
    contains($d, 'declare const ping: () => number;');
    // No synthetic `php` (or any) root wraps the surface.
    if (str_contains($d, 'declare const php:')) {
        throw new RuntimeException("unexpected synthetic root:\n$d");
    }
});

echo "\nPython (.pyi) inference\n";
check('scalars + nullable -> Optional', function () {
    $h = new TypeHost();
    $h->reg('f', fn (?int $x): string => '');
    contains($h->pyi(), 'def f(x: Optional[int]) -> str: ...');
});
check('array element -> list[T]', function () {
    $h = new TypeHost();
    $h->reg('sum', /** @param int[] $xs */ fn (array $xs): int => 0);
    contains($h->pyi(), 'def sum(xs: list[int]) -> int: ...');
});
check('object shape -> TypedDict with NotRequired', function () {
    $h = new TypeHost();
    $h->reg('user.fetch',
        /** @return array{name: string, score?: float} */
        fn (int $id): array => []);
    $p = $h->pyi();
    contains($p, 'from typing import Any, Callable, Optional, NotRequired, TypedDict');
    contains($p, '(TypedDict):');
    contains($p, 'name: str');
    contains($p, 'score: NotRequired[float]');
});

echo "\nPHP (.php stub) inference -- the PHP guest's view\n";
check('a namespace becomes a class; the top-level proxy a @var', function () {
    $h = new TypeHost();
    $h->reg('math.add', fn (int $a, int $b): int => 0);
    $p = $h->php();
    contains($p, 'final class Terrarium_Math');
    contains($p, 'public function add(int $a, int $b): int {}');
    contains($p, '/** @var Terrarium_Math $math */');
});
check('a flat capability becomes a Closure-typed variable', function () {
    $h = new TypeHost();
    $h->reg('ping', fn (): string => '');
    contains($h->php(), '/** @var \Closure(): string $ping */');
});
check('nested namespaces become typed properties', function () {
    $h = new TypeHost();
    $h->reg('api.v1.hello', fn (string $n): string => '');
    $p = $h->php();
    contains($p, 'final class Terrarium_Api_V1');
    contains($p, 'public Terrarium_Api_V1 $v1;');
});
check('object shapes ride in docblocks over a native array hint', function () {
    $h = new TypeHost();
    $h->reg('user.fetch',
        /** @return array{name: string, score?: float} */
        fn (int $id): array => []);
    $p = $h->php();
    contains($p, '@return array{name: string, score?: float}');
    contains($p, 'public function fetch(int $id): array {}');
});
check('the docblock summary flows into the stub', function () {
    $h = new TypeHost();
    $h->reg('user.fetch',
        /**
         * Fetches a user by their ID.
         * @return array{name: string}
         */
        fn (int $id): array => []);
    contains($h->php(), 'Fetches a user by their ID.');
});

echo "\nPHPDoc descriptions flow into the type defs\n";
check('dts() emits a JSDoc comment from the docblock summary', function () {
    $h = new TypeHost();
    $h->reg('user.fetch',
        /**
         * Fetches a user by their ID.
         * @return array{name: string}
         */
        fn (int $id): array => []);
    contains($h->dts(), '/** Fetches a user by their ID. */');
});
check('pyi() emits a comment from the docblock summary', function () {
    $h = new TypeHost();
    $h->reg('user.fetch',
        /**
         * Fetches a user by their ID.
         * @return array{name: string}
         */
        fn (int $id): array => []);
    contains($h->pyi(), '# Fetches a user by their ID.');
});
check('a capability with no summary gets no comment', function () {
    $h = new TypeHost();
    $h->reg('ping', /** @return string */ fn (): string => 'pong');
    $d = $h->dts();
    if (str_contains($d, '/**')) {
        throw new RuntimeException("unexpected JSDoc comment:\n$d");
    }
});

summary();

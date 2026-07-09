<?php
// Types inferred from the PHP signatures themselves — no hand-written type
// strings. Reflection covers scalars/nullable/unions/params; PHPDoc fills the
// gaps PHP can't express, including rich object shapes (array{...}).
//
// register() records each closure's inferred type model, so types('dts') and
// types('pyi') describe the SDK a guest author would code against.
//
//   php -d extension=target/release/libterrarium.so examples/inferred_types.php

declare(strict_types=1);

require __DIR__ . '/../lib/Terrarium.php';
use Terrarium\Terrarium;
use Terrarium\Exception as TerrariumException;
use Terrarium\TrapException;
use Terrarium\TimeoutException;
use Terrarium\MemoryException;
use Terrarium\GuestException;

$wasm = new Terrarium(__DIR__ . '/../tests/wasm/quickjs_guest.wasm', timeoutMs: 2000);

// Just type-hinted PHP closures — the signature is the schema.
$wasm->register('math.add', fn (int $a, int $b): int => $a + $b);

$wasm->register('math.sum',
    /** @param int[] $xs */
    fn (array $xs): int => array_sum($xs));

$wasm->register('maybe.len', fn (?string $s): ?int => $s === null ? null : strlen($s));

// Rich object shapes via PHPDoc — nested records and optional keys. The leading
// docblock prose becomes a JSDoc comment (.d.ts) / `#` line (.pyi) in the output.
$wasm->register('user.fetch',
    /**
     * Fetch a user record by ID, including roles and metadata.
     * @return array{name: string, roles: string[], meta: array{active: bool, score?: float}}
     */
    fn (int $id): array => ['name' => "user-$id", 'roles' => ['admin'], 'meta' => ['active' => true]]);

$wasm->register('user.search',
    /**
     * Search users by a free-text query, newest first.
     * @param array{q: string, limit?: int} $query
     * @return array{id: int, name: string}[]
     */
    fn (array $query): array => [['id' => 1, 'name' => 'Ada']]);

echo "=== inferred php.d.ts (TypeScript) ===\n";
echo $wasm->types('dts');

echo "\n=== inferred php.pyi (Python; object shapes -> TypedDicts) ===\n";
echo $wasm->types('pyi');

echo "\n=== and it still runs (QuickJS) ===\n";
echo 'math.add(2,3) + math.sum([1,2,3]) = ',
    $wasm->eval('math.add(2, 3) + math.sum([1, 2, 3])'), "\n";
var_dump($wasm->eval('user.fetch(7)'));

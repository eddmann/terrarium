<?php
// TypeScript, checked and run inside the sandbox. The real TypeScript compiler
// runs *inside* the wasm guest and checks every eval against the .d.ts
// generated from the SDK you registered — the type environment IS the
// capability environment. A bad call is rejected with the TS diagnostic and
// line BEFORE any guest code executes; a clean one is stripped
// (whitespace-preserving, so runtime error lines match the TS source) and run.
//
//   php -d extension=target/release/libterrarium.so examples/typescript.php

declare(strict_types=1);

require __DIR__ . '/../lib/Terrarium.php';
use Terrarium\Terrarium;
use Terrarium\Exception as TerrariumException;
use Terrarium\TrapException;
use Terrarium\TimeoutException;
use Terrarium\MemoryException;
use Terrarium\GuestException;

$ts = new Terrarium(__DIR__ . '/../tests/wasm/typescript_guest.wasm', timeoutMs: 10000);

// Plain, typed PHP — the signature is the schema, and the schema is enforced.
$ts->register('user.fetch',
    /**
     * Fetch a user by ID.
     * @return array{name: string, roles: string[]}
     */
    fn (int $id): array => ['name' => 'Ada', 'roles' => ['admin', 'dev']]);

echo "--- the .d.ts the checker enforces (generated from the PHP signatures) ---\n";
echo $ts->types('dts'), "\n";

echo "--- a wrong call is rejected before execution ---\n";
try {
    $ts->eval('user.fetch("42")');
} catch (GuestException $e) {
    echo "rejected: ", $e->getMessage(), "\n\n";
}

echo "--- a shape mistake is caught the same way ---\n";
try {
    $ts->eval('const emails: string[] = user.fetch(42).emails;');
} catch (GuestException $e) {
    echo "rejected: ", $e->getMessage(), "\n\n";
}

echo "--- check(): every diagnostic as data, nothing executed ---\n";
foreach ($ts->check('const u = user.fetch("42"); const n: number = u.name;') as $d) {
    printf("  %s (line %d): %s\n", $d['type'], $d['line'], $d['message']);
}
echo "\n";

echo "--- a correct, typed program checks and runs ---\n";
echo $ts->eval(<<<'TS'
    const u = user.fetch(42);
    const roles: string[] = u.roles;
    console.log("fetched", u.name);
    `${u.name}: ${roles.join(", ")}`
    TS), "\n";
echo "guest printed: ", $ts->output(), "\n";

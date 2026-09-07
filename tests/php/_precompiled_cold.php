<?php

declare(strict_types=1);

// Time ONE construct in a fresh process, for 14_precompiled.php's cold-start
// comparison. Run as a subprocess with XDG_CACHE_HOME pointed at an empty
// directory, so the Wasmtime on-disk module cache cannot help either side.
// Not matched by the Makefile's `[0-9]*.php` glob.
//
//   php -d extension=... _precompiled_cold.php <guest path> <0|1 precompiled>

require_once __DIR__ . '/../../lib/Terrarium.php';

[, $path, $precompiled] = $argv;

$elapsed = -hrtime(true);
$rt = new Terrarium\Terrarium($path, precompiled: $precompiled === '1');
$elapsed += hrtime(true);

// Prove the guest actually runs, not merely that the module loaded.
printf("%.1f %s\n", $elapsed / 1e6, $rt->eval('1 + 1') === 2 ? 'ok' : 'BAD');

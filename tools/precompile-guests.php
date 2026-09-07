<?php
// Precompile guest .wasm files into artifacts for the extension build that is
// running this script (`Terrarium\Runtime::precompile()`), one `.cwasm` per
// guest, plus a SHA256SUMS over the outputs and their inputs.
//
//   php -d extension=/path/to/terrarium.so tools/precompile-guests.php \
//       [--fuel] [--native] <out-dir> <guest.wasm>...
//
// The release workflow runs this inside the Bref build image, against the
// freshly built Lambda .so, so the shipped artifacts match that .so exactly.
// Artifacts are bound to the fuel setting they were compiled with, so a
// release ships one set without fuel metering and one with (`--fuel`).
// `--native` targets the CPU of this machine instead of the architecture's
// baseline; the default is portable so the artifact loads on any host of the
// same architecture (see docs/install.md, "Precompiling for deployment").

declare(strict_types=1);

$args = array_slice($argv, 1);
$fuel = false;
$portable = true;
while ($args !== [] && str_starts_with($args[0], '--')) {
    switch (array_shift($args)) {
        case '--fuel':
            $fuel = true;
            break;
        case '--native':
            $portable = false;
            break;
        default:
            fwrite(STDERR, "unknown option\n");
            exit(2);
    }
}
if (count($args) < 2) {
    fwrite(STDERR, "usage: precompile-guests.php [--fuel] [--native] <out-dir> <guest.wasm>...\n");
    exit(2);
}
if (!class_exists(Terrarium\Runtime::class)) {
    fwrite(STDERR, "the terrarium extension is not loaded (php -d extension=...)\n");
    exit(1);
}

$out = array_shift($args);
if (!is_dir($out) && !mkdir($out, 0o755, true)) {
    fwrite(STDERR, "cannot create $out\n");
    exit(1);
}

$sums = [];
foreach ($args as $path) {
    $wasm = file_get_contents($path);
    if ($wasm === false) {
        fwrite(STDERR, "cannot read $path\n");
        exit(1);
    }
    $name = preg_replace('/\.wasm$/', '', basename($path)) . '.cwasm';
    $started = hrtime(true);
    $artifact = Terrarium\Runtime::precompile(
        $wasm,
        fuel: $fuel ? 1 : null,
        portable: $portable,
    );
    $seconds = (hrtime(true) - $started) / 1e9;

    // Prove the artifact loads and runs under the same settings before it ships.
    $rt = new Terrarium\Runtime($artifact, fuel: $fuel ? PHP_INT_MAX : null, precompiled: true);
    unset($rt);

    file_put_contents("$out/$name", $artifact);
    $sums[] = hash('sha256', $artifact) . "  $name";
    $sums[] = hash('sha256', $wasm) . "  " . basename($path) . " (input)";
    printf(
        "%-28s %6.1f MB -> %6.1f MB  %5.1fs%s%s\n",
        basename($path),
        strlen($wasm) / 1048576,
        strlen($artifact) / 1048576,
        $seconds,
        $fuel ? '  fuel' : '',
        $portable ? '' : '  native',
    );
}
file_put_contents("$out/SHA256SUMS", implode("\n", $sums) . "\n");

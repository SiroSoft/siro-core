<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$tracked = trim((string) shell_exec('git ls-files'));
$trackedFiles = $tracked === '' ? [] : preg_split('/\R/', $tracked);
$trackedFiles = array_values(array_filter($trackedFiles ?: [], static fn (string $file): bool => $file !== ''));

$forbiddenTracked = array_values(array_filter($trackedFiles, static function (string $file): bool {
    return preg_match('/(^|\/)(\.env(?:\.|$)|.*\.(log|sqlite|db|phar))$/i', $file) === 1
        || preg_match('/(^|\/)(storage|coverage|rate_limit|tools\/.*\/(storage|results))($|\/)/i', $file) === 1;
}));

$requiredTools = [
    $root . '/tools/README.md',
    $root . '/tools/release/build-phar.php',
];
$missingTools = array_values(array_filter($requiredTools, static fn (string $file): bool => !is_file($file)));

if ($forbiddenTracked !== []) {
    fwrite(STDERR, "Tracked release-ineligible files found:\n");
    foreach ($forbiddenTracked as $file) {
        fwrite(STDERR, "- {$file}\n");
    }
}

if ($missingTools !== []) {
    fwrite(STDERR, "Required release tools are missing:\n");
    foreach ($missingTools as $file) {
        fwrite(STDERR, "- {$file}\n");
    }
}

if ($forbiddenTracked !== [] || $missingTools !== []) {
    exit(1);
}

fwrite(STDOUT, "Release hygiene check passed\n");

<?php

declare(strict_types=1);

$strict = in_array('--strict', $argv ?? [], true);
$withProdDoctor = in_array('--with-prod-doctor', $argv ?? [], true);

$steps = [
    ['name' => 'Composer audit', 'cmd' => 'composer audit --no-interaction'],
    ['name' => 'PHPStan (Level Max)', 'cmd' => 'php vendor/bin/phpstan analyse --level=max --no-progress --memory-limit=1G'],
    ['name' => 'PHPUnit', 'cmd' => 'php vendor/bin/phpunit --no-progress'],
];

if ($strict) {
    $steps = array_merge($steps, [
        ['name' => 'Psalm Taint Analysis', 'cmd' => 'php vendor/bin/psalm --taint-analysis --no-progress --show-info=false --php-version=8.2'],
        ['name' => 'Fuzz Tests', 'cmd' => 'php scripts/fuzz.php'],
        ['name' => 'Chaos Tests', 'cmd' => 'php scripts/chaos-test.php'],
        ['name' => 'Mutation Tests (≥80% MSI)', 'cmd' => 'php vendor/bin/infection --min-msi=80 --threads=4 --no-progress'],
        ['name' => 'Benchmark Regression', 'cmd' => 'php scripts/benchmark-ci.php'],
        ['name' => 'SBOM Generation', 'cmd' => 'php scripts/generate-sbom.php'],
        ['name' => 'Load Test', 'cmd' => 'php scripts/loadtest.php'],
    ]);
}

if ($withProdDoctor) {
    $steps[] = ['name' => 'Production Doctor', 'cmd' => 'php siro doctor --prod'];
}

$failures = 0;

// Version-consistency gate: Console::VERSION is the single source of truth.
// Guards against drift like APP_VERSION=0.28.1 while VERSION=1.0.0.
$versionGate = function (): bool {
    $root = dirname(__DIR__);
    $consoleFile = $root . '/Console.php';
    $content = is_file($consoleFile) ? (string) file_get_contents($consoleFile) : '';
    if (!preg_match("/const VERSION = '([^']+)'/", $content, $m)) {
        fwrite(STDERR, "[FAIL] Version gate: cannot parse Console::VERSION\n");
        return false;
    }
    $version = $m[1];
    if (!preg_match('/^\d+\.\d+\.\d+$/', $version)) {
        fwrite(STDERR, "[FAIL] Version gate: malformed VERSION '{$version}'\n");
        return false;
    }
    $changelog = is_file($root . '/CHANGELOG.md') ? (string) file_get_contents($root . '/CHANGELOG.md') : '';
    if (!preg_match('/^## v(\d+\.\d+\.\d+)/m', $changelog, $cm) || $cm[1] !== $version) {
        fwrite(STDERR, "[FAIL] Version gate: CHANGELOG head '" . ($cm[1] ?? '?') . "' != Console::VERSION '{$version}'\n");
        return false;
    }
    foreach (['.env.siro', '.env.bench'] as $envFile) {
        $path = $root . '/' . $envFile;
        if (!is_file($path)) {
            continue;
        }
        $env = (string) file_get_contents($path);
        if (preg_match('/^APP_VERSION=(.+)$/m', $env, $em) && trim($em[1], "\"' ") !== $version) {
            fwrite(STDERR, "[FAIL] Version gate: {$envFile} APP_VERSION '" . trim($em[1]) . "' != '{$version}'\n");
            return false;
        }
    }
    fwrite(STDOUT, "[OK] Version gate ({$version})\n");
    return true;
};

fwrite(STDOUT, "\n==> Version gate\n");
if (!$versionGate()) {
    $failures++;
}

foreach ($steps as $step) {
    fwrite(STDOUT, "\n==> {$step['name']}\n");
    passthru($step['cmd'], $code);

    if ($code !== 0) {
        $failures++;
        fwrite(STDERR, "[FAIL] {$step['name']} exited with code {$code}\n");
    } else {
        fwrite(STDOUT, "[OK] {$step['name']}\n");
    }
}

if ($failures > 0) {
    fwrite(STDERR, "\nRelease check completed with {$failures} failure(s).\n");
    exit(1);
}

fwrite(STDOUT, "\nRelease check passed.\n");
exit(0);

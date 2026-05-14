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

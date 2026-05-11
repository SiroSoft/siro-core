<?php

declare(strict_types=1);

$steps = [
    ['name' => 'Composer audit', 'cmd' => 'composer audit --no-interaction'],
    ['name' => 'PHPStan', 'cmd' => 'php vendor/bin/phpstan analyse --no-progress --memory-limit=1G'],
    ['name' => 'PHPUnit', 'cmd' => 'php vendor/bin/phpunit --no-progress'],
];

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

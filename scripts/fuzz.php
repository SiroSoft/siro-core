#!/usr/bin/env php
<?php
declare(strict_types=1);

// Fuzz test runner - runs property-based tests with random inputs
ini_set('memory_limit', '512M');
putenv('FUZZ_RUNNER=1');
passthru('php -d memory_limit=512M vendor/bin/phpunit --no-coverage tests/fuzz/', $exitCode);
exit($exitCode);

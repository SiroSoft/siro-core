#!/usr/bin/env php
<?php

/**
 * Round 2: Autoload & Class Loading Test
 */

declare(strict_types=1);

echo "========================================\n";
echo "Siro Core v0.7.5 - Round 2 Testing\n";
echo "========================================\n\n";

// Setup autoloader
spl_autoload_register(function (string $class): void {
    $prefix = 'Siro\\Core\\';
    $baseDir = __DIR__ . DIRECTORY_SEPARATOR;
    
    $len = strlen($prefix);
    if (strncmp($prefix, $class, $len) !== 0) {
        return;
    }
    
    $relativeClass = substr($class, $len);
    $file = $baseDir . str_replace('\\', '/', $relativeClass) . '.php';
    
    if (file_exists($file)) {
        require $file;
    }
});

$tests = [
    'Model' => 'Siro\Core\Model',
    'ValidationException' => 'Siro\Core\ValidationException',
    'Request' => 'Siro\Core\Request',
    'Response' => 'Siro\Core\Response',
    'Router' => 'Siro\Core\Router',
    'Validator' => 'Siro\Core\Validator',
    'Database' => 'Siro\Core\Database',
    'QueryBuilder' => 'Siro\Core\DB\QueryBuilder',
    'MigrationBaseCommand' => 'Siro\Core\Commands\MigrationBaseCommand',
    'MakeModelCommand' => 'Siro\Core\Commands\MakeModelCommand',
    'MigrateCommand' => 'Siro\Core\Commands\MigrateCommand',
    'MigrateRollbackCommand' => 'Siro\Core\Commands\MigrateRollbackCommand',
    'MakeApiCommand' => 'Siro\Core\Commands\MakeApiCommand',
];

$passed = 0;
$failed = 0;

foreach ($tests as $name => $class) {
    echo "Testing {$name}... ";
    try {
        if (class_exists($class) || trait_exists($class)) {
            echo "✅ PASS\n";
            $passed++;
        } else {
            echo "❌ FAIL - Class not found\n";
            $failed++;
        }
    } catch (Throwable $e) {
        echo "❌ ERROR: " . $e->getMessage() . "\n";
        $failed++;
    }
}

echo "\n========================================\n";
echo "Round 2 Results: {$passed} passed, {$failed} failed\n";
echo "========================================\n";

exit($failed > 0 ? 1 : 0);

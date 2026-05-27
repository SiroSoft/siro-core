<?php

declare(strict_types=1);

/**
 * Siro PHAR Builder — Build standalone siro.phar
 *
 * Usage: php scripts/build-phar.php
 * Output: dist/siro.phar
 */

$rootDir = dirname(__DIR__);
$distDir = $rootDir . DIRECTORY_SEPARATOR . 'dist';
$pharFile = $distDir . DIRECTORY_SEPARATOR . 'siro.phar';
$appDir = dirname($rootDir) . DIRECTORY_SEPARATOR . 'SiroPHP';

if (!extension_loaded('phar')) {
    echo "Error: phar extension is required.\n";
    exit(1);
}

if (ini_get('phar.readonly')) {
    echo "Error: phar.readonly must be Off in php.ini\n";
    echo "  php -d phar.readonly=0 scripts/build-phar.php\n";
    exit(1);
}

echo "Building Siro PHAR...\n";

// Clean
@unlink($pharFile);
@unlink($pharFile . '.gz');

if (!is_dir($distDir)) {
    mkdir($distDir, 0755, true);
}

// Collect files from siro-core
$coreFiles = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($rootDir, RecursiveDirectoryIterator::SKIP_DOTS)
);

$phar = new Phar($pharFile, 0, 'siro.phar');
$phar->setSignatureAlgorithm(Phar::SHA256);

$count = 0;
foreach ($coreFiles as $file) {
    $relative = substr($file->getPathname(), strlen($rootDir) + 1);
    // Skip vendor, tests, storage, scripts (except build scripts), .git, etc
    if (preg_match('#^(vendor|tests|storage|\.git|node_modules|dist)/#', $relative)) {
        continue;
    }
    if (preg_match('#\.(md|neon|xml|dist|txt|ps1|sh|lock)$#', $relative)) {
        continue;
    }
    if ($relative === 'phpunit.xml' || $relative === 'phpcs.xml' || $relative === 'psalm.xml' || $relative === 'psalm-baseline.xml' || $relative === 'phpdoc.dist.xml' || $relative === 'depfile.yaml') {
        continue;
    }
    $phar->addFile($file->getPathname(), $relative);
    $count++;
}
echo "  Added {$count} core files\n";

// Add skeleton files from SiroPHP (for `siro new`)
if (is_dir($appDir)) {
    $skeletonFiles = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($appDir, RecursiveDirectoryIterator::SKIP_DOTS)
    );

    $skelCount = 0;
    foreach ($skeletonFiles as $file) {
        $relative = 'skeleton/' . substr($file->getPathname(), strlen($appDir) + 1);
        // Exclude vendor, storage/logs/traces, .git
        if (preg_match('#^(skeleton/)?(vendor|\.git|storage/logs|storage/benchmark|storage/sbom|node_modules)/#', $relative)) {
            continue;
        }
        if (preg_match('#\.(md|lock)$#', $relative)) {
            continue;
        }
        $phar->addFile($file->getPathname(), $relative);
        $skelCount++;
    }
    echo "  Added {$skelCount} skeleton files\n";
} else {
    echo "  Warning: SiroPHP skeleton not found at {$appDir}\n";
}

// Set stub (entry point)
$stub = <<<'STUB'
#!/usr/bin/env php
<?php

declare(strict_types=1);

Phar::mapPhar('siro.phar');

$basePath = __FILE__;

// Autoload from PHAR
require 'phar://' . $basePath . '/vendor/autoload.php';

use Siro\Core\Console;

$console = new Console($basePath);
exit($console->run($argv));

__HALT_COMPILER();
STUB;

$phar->setStub($stub);

// Compress
$phar->compressFiles(Phar::GZ);
echo "  Compressed with GZ\n";

$size = filesize($pharFile);
echo "\n✅ siro.phar built successfully!\n";
echo "   Size: " . number_format($size / 1024, 1) . " KB\n";
echo "   Path: {$pharFile}\n";
echo "   SHA256: " . hash_file('sha256', $pharFile) . "\n";

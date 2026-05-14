#!/usr/bin/env php
<?php
declare(strict_types=1);

$basePath = dirname(__DIR__);
$docsDir = $basePath . DIRECTORY_SEPARATOR . 'docs' . DIRECTORY_SEPARATOR . 'api';

if (!is_dir($docsDir)) {
    mkdir($docsDir, 0775, true);
}

$configFile = $basePath . DIRECTORY_SEPARATOR . 'phpdoc.dist.xml';
if (!is_file($configFile)) {
    fwrite(STDERR, "Error: phpdoc.dist.xml not found at $configFile\n");
    exit(1);
}

$phpdocBin = $basePath . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR . 'bin' . DIRECTORY_SEPARATOR . 'phpdoc';

if (is_file($phpdocBin)) {
    echo "Generating API documentation with phpDocumentor...\n";
    passthru("php \"$phpdocBin\" --config=\"$configFile\" 2>&1", $exitCode);
    if ($exitCode === 0) {
        echo "API documentation generated: $docsDir\n";
    } else {
        fwrite(STDERR, "phpDocumentor failed with exit code $exitCode\n");
        exit($exitCode);
    }
} else {
    echo "[INFO] phpDocumentor not installed. Run: composer require --dev phpdocumentor/phpdoc\n";
    echo "[INFO] Falling back to PHPDoc summary...\n";

    $excludeDirs = ['vendor', 'tests', 'scripts', 'storage', 'docs', '.phpdoc'];
    $filter = function (\SplFileInfo $file) use ($excludeDirs): bool {
        foreach ($excludeDirs as $ex) {
            if (str_starts_with($file->getPathname(), $basePath . DIRECTORY_SEPARATOR . $ex)) return false;
        }
        return $file->getExtension() === 'php';
    };

    $files = new RecursiveCallbackFilterIterator(
        new RecursiveDirectoryIterator($basePath, RecursiveDirectoryIterator::SKIP_DOTS),
        $filter
    );
    $it = new RecursiveIteratorIterator($files);

    $classCount = 0;
    $methodCount = 0;
    $docblockCount = 0;

    foreach ($it as $file) {
        $content = @file_get_contents($file->getPathname());
        if ($content === false || $content === '') continue;
        $tokens = @token_get_all($content);
        if (!is_array($tokens)) continue;
        $depth = 0;
        foreach ($tokens as $i => $token) {
            if (!is_array($token)) continue;
            if ($token[0] === T_DOC_COMMENT) {
                $docblockCount++;
            }
            if (in_array($token[0], [T_CLASS, T_INTERFACE, T_TRAIT, T_ENUM], true)) {
                $next = $tokens[$i + 1] ?? null;
                if (is_array($next) && $next[0] === T_WHITESPACE) {
                    $next = $tokens[$i + 2] ?? null;
                }
                if (is_array($next) && in_array($next[0], [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true)) {
                    $classCount++;
                }
            }
            if (in_array($token[0], [T_FUNCTION, T_ARROW], true)) {
                $next = $tokens[$i + 1] ?? null;
                if (is_array($next) && $next[0] === T_WHITESPACE) {
                    $next = $tokens[$i + 2] ?? null;
                }
                if (is_array($next) && $next[0] === T_STRING) {
                    $methodCount++;
                }
            }
        }
    }

    $summary = [
        'generated' => date('c'),
        'php_version' => PHP_VERSION,
        'classes' => $classCount,
        'methods' => $methodCount,
        'docblocks' => $docblockCount,
    ];

    file_put_contents(
        $docsDir . DIRECTORY_SEPARATOR . 'summary.json',
        json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
    );

    echo "PHPDoc Summary:\n";
    echo "  Classes/Interfaces: $classCount\n";
    echo "  Methods:           $methodCount\n";
    echo "  DocBlocks:         $docblockCount\n";
    echo "  Summary saved to:  docs/api/summary.json\n";
}

exit(0);

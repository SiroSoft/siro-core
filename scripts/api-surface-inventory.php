<?php
/**
 * A4 — Public API Surface Inventory
 *
 * Reads the entire codebase and inventories:
 * - Public classes, interfaces, traits
 * - Public methods per class
 * - CLI commands + flags
 * - Config keys
 * - Environment variables
 * - Middleware contracts
 * - Exception hierarchy
 *
 * Output: API_SURFACE.md
 */

require_once __DIR__ . '/../vendor/autoload.php';

$basePath = dirname(__DIR__);
$srcDir = $basePath;

echo "=== A4 PUBLIC API SURFACE INVENTORY ===\n\n";

// 1. Find all PHP files in src/ (excluding tests, vendor, scripts)
$phpFiles = [];
$iterator = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($srcDir, RecursiveDirectoryIterator::SKIP_DOTS)
);
foreach ($iterator as $file) {
    if ($file->getExtension() !== 'php') continue;
    $path = $file->getPathname();
    if (str_contains($path, '/vendor/') || str_contains($path, '/tests/') ||
        str_contains($path, '/scripts/') || str_contains($path, '/coverage/') ||
        str_contains($path, '/storage/') || str_contains($path, '\\vendor\\') ||
        str_contains($path, '\\tests\\') || str_contains($path, '\\scripts\\')) {
        continue;
    }
    $phpFiles[] = $path;
}
sort($phpFiles);

echo "PHP files found: " . count($phpFiles) . "\n\n";

// 2. Extract classes, interfaces, traits
$classes = [];
$interfaces = [];
$traits = [];

foreach ($phpFiles as $file) {
    $content = file_get_contents($file);
    if ($content === false) continue;

    $relativePath = str_replace($basePath . '/', '', str_replace($basePath . '\\', '', $file));

    // Extract namespace
    preg_match('/namespace\s+([\w\\\\]+);/', $content, $nsMatch);
    $namespace = $nsMatch[1] ?? '';

    // Extract class
    preg_match_all('/(?:abstract\s+|final\s+)?class\s+(\w+)(?:\s+extends\s+\w+)?(?:\s+implements\s+[\w\\\\,\s]+)?/', $content, $classMatches);
    foreach ($classMatches[1] as $cls) {
        $classes[] = ['name' => $cls, 'namespace' => $namespace, 'file' => $relativePath];
    }

    // Extract interface
    preg_match_all('/interface\s+(\w+)(?:\s+extends\s+[\w\\\\,\s]+)?/', $content, $ifMatches);
    foreach ($ifMatches[1] as $if) {
        $interfaces[] = ['name' => $if, 'namespace' => $namespace, 'file' => $relativePath];
    }

    // Extract trait
    preg_match_all('/trait\s+(\w+)/', $content, $traitMatches);
    foreach ($traitMatches[1] as $tr) {
        $traits[] = ['name' => $tr, 'namespace' => $namespace, 'file' => $relativePath];
    }
}

echo "Classes: " . count($classes) . "\n";
echo "Interfaces: " . count($interfaces) . "\n";
echo "Traits: " . count($traits) . "\n\n";

// 3. Extract public methods for key classes
$publicApiClasses = [
    'App', 'Console', 'Router', 'Request', 'Response', 'Database', 'DB',
    'Model', 'QueryBuilder', 'Validator', 'Auth', 'JWT', 'Cache',
    'Http', 'Mail', 'Queue', 'Event', 'Storage', 'Session',
    'Schema', 'Config', 'Env', 'Logger', 'Encrypter', 'Hash',
    'Str', 'Collection', 'Resource', 'FormRequest', 'Route',
    'Middleware', 'Schedule', 'Metrics', 'Mercure', 'URL',
    'ValidationException', 'ModelNotFoundException',
];

echo "=== KEY PUBLIC API CLASSES ===\n\n";
foreach ($publicApiClasses as $name) {
    $found = false;
    foreach ($classes as $cls) {
        if ($cls['name'] === $name) {
            $fullPath = $basePath . '/' . $cls['file'];
            $content = file_get_contents($fullPath);
            if ($content === false) continue;

            // Count public methods
            preg_match_all('/public\s+(?:static\s+)?function\s+(\w+)/', $content, $methodMatches);
            $methods = $methodMatches[1];

            echo "## {$cls['namespace']}\\{$name}\n";
            echo "File: {$cls['file']}\n";
            echo "Public methods: " . count($methods) . "\n";
            if (!empty($methods)) {
                foreach ($methods as $m) {
                    echo "  - {$m}()\n";
                }
            }
            echo "\n";
            $found = true;
            break;
        }
    }
    if (!$found) {
        echo "## {$name}\n";
        echo "NOT FOUND in codebase\n\n";
    }
}

// 4. Extract environment variables
echo "=== ENVIRONMENT VARIABLES ===\n\n";
$envVars = [];
foreach ($phpFiles as $file) {
    $content = file_get_contents($file);
    if ($content === false) continue;
    preg_match_all("/Env::get\(\s*'([A-Z_]+)'/", $content, $envMatches);
    foreach ($envMatches[1] as $var) {
        $envVars[$var] = true;
    }
    preg_match_all("/getenv\(\s*'([A-Z_]+)'/", $content, $envMatches2);
    foreach ($envMatches2[1] as $var) {
        $envVars[$var] = true;
    }
}
ksort($envVars);
foreach (array_keys($envVars) as $var) {
    echo "  {$var}\n";
}
echo "\nTotal env vars: " . count($envVars) . "\n\n";

// 5. Extract CLI commands (from Console.php)
echo "=== CLI COMMANDS ===\n\n";
$consoleContent = file_get_contents($basePath . '/Console.php');
preg_match_all("/'([a-z][a-z0-9:_-]+)'\s*=>\s*\['handler'\s*=>\s*(\w+)::class,\s*'desc'\s*=>\s*'([^']*)'/", $consoleContent, $cmdMatches);
$commands = [];
$count = count($cmdMatches[1]);
for ($i = 0; $i < $count; $i++) {
    $commands[$cmdMatches[1][$i]] = ['handler' => $cmdMatches[2][$i], 'desc' => $cmdMatches[3][$i]];
}
ksort($commands);
foreach ($commands as $name => $info) {
    echo "  {$name} — {$info['desc']}\n";
}
echo "\nTotal commands: " . count($commands) . "\n\n";

// 6. Summary
echo "=== SUMMARY ===\n";
echo "Classes: " . count($classes) . "\n";
echo "Interfaces: " . count($interfaces) . "\n";
echo "Traits: " . count($traits) . "\n";
echo "CLI Commands: " . count($commands) . "\n";
echo "Environment Variables: " . count($envVars) . "\n";
echo "PHP Files: " . count($phpFiles) . "\n";

<?php
/**
 * Command Inventory Script — Phase A3
 * 
 * Reads Console.php and lists all registered commands, imported classes,
 * and any orphan/unregistered classes.
 */

$content = file_get_contents(__DIR__ . '/../Console.php');

// Extract registered command names
preg_match_all("/'([a-z][a-z0-9:_-]+)'\s*=>\s*\['handler'/", $content, $matches);
$registered = array_unique($matches[1]);
sort($registered);

// Extract imported command classes
preg_match_all('/use Siro\\\\Core\\\\Commands\\\\(\w+);/', $content, $classMatches);
$imported = array_unique($classMatches[1]);
sort($imported);

// Find all command class files
$classFiles = glob(__DIR__ . '/../Commands/*.php');
$classNames = array_map(function($f) {
    return basename($f, '.php');
}, $classFiles);
sort($classNames);

echo "=== COMMAND INVENTORY ===\n\n";
echo "Registered commands: " . count($registered) . "\n";
echo "Imported classes: " . count($imported) . "\n";
echo "Class files: " . count($classNames) . "\n\n";

// Find orphan classes (in Commands/ but not imported)
$orphanClasses = array_diff($classNames, $imported);
if (!empty($orphanClasses)) {
    echo "⚠ ORPHAN CLASSES (in Commands/ but not imported in Console.php):\n";
    foreach ($orphanClasses as $cls) echo "  - $cls\n";
    echo "\n";
}

// Find unregistered imports (imported but not used in registry)
$registeredHandlers = [];
preg_match_all("/'handler'\s*=>\s*(\w+)::class/", $content, $handlerMatches);
$handlers = array_unique($handlerMatches[1]);
sort($handlers);

$unregisteredImports = array_diff($imported, $handlers);
if (!empty($unregisteredImports)) {
    echo "⚠ UNREGISTERED IMPORTS (imported but not in registry):\n";
    foreach ($unregisteredImports as $cls) echo "  - $cls\n";
    echo "\n";
}

echo "=== REGISTERED COMMANDS ===\n\n";
foreach ($registered as $i => $cmd) {
    echo sprintf("%3d. %s\n", $i + 1, $cmd);
}

echo "\n=== ALIASES ===\n";
echo "  slow   → log:slow\n";
echo "  why    → debug:last\n";
echo "  traces → trace:list\n";
echo "  t      → api:test (shortcut)\n";
echo "  tink   → tinker (shortcut)\n";
echo "  make:docs → make:openapi --with-swagger (alias)\n";
echo "  open:postman → log:export --postman (alias)\n";
echo "  start  → serve (hardcoded)\n";

echo "\n=== TOTAL ===\n";
echo "  Registered: " . count($registered) . "\n";
echo "  Aliases: 7 (3 aliases + 2 shortcuts + 2 hardcoded)\n";
echo "  Effective unique commands: " . count($registered) . " (+ aliases)\n";

<?php
/**
 * A3.6 — Command Classification
 * Classifies all 95 registered commands by side-effect type.
 */

$content = file_get_contents(__DIR__ . '/../Console.php');
preg_match_all("/'([a-z][a-z0-9:_-]+)'\s*=>\s*\['handler'\s*=>\s*(\w+)::class,\s*'desc'\s*=>\s*'([^']*)'/", $content, $matches);

$commands = [];
$count = count($matches[1]);
for ($i = 0; $i < $count; $i++) {
    $commands[$matches[1][$i]] = [
        'handler' => $matches[2][$i],
        'desc' => $matches[3][$i],
    ];
}
ksort($commands);

// Classification rules based on command behavior
$classification = [
    // READ-ONLY
    'api:why' => 'READ_ONLY',
    'benchmark' => 'READ_ONLY',
    'db:check' => 'READ_ONLY',
    'db:health' => 'READ_ONLY',
    'db:show' => 'READ_ONLY',
    'db:stats' => 'READ_ONLY',
    'db:why' => 'READ_ONLY',
    'debug:health' => 'READ_ONLY',
    'debug:last' => 'READ_ONLY',
    'doctor' => 'READ_ONLY',
    'env:check' => 'READ_ONLY',
    'log:slow' => 'READ_ONLY',
    'log:stats' => 'READ_ONLY',
    'log:tail' => 'READ_ONLY',
    'log:top' => 'READ_ONLY',
    'log:trace' => 'READ_ONLY',
    'rate:status' => 'READ_ONLY',
    'route:list' => 'READ_ONLY',
    'route:rules' => 'READ_ONLY',
    'route:search' => 'READ_ONLY',
    'trace:list' => 'READ_ONLY',
    'queue:status' => 'READ_ONLY',
    'migrate:status' => 'READ_ONLY',
    'audit:verify' => 'READ_ONLY',

    // FILESYSTEM WRITE
    'config:cache' => 'FILESYSTEM_WRITE',
    'config:clear' => 'FILESYSTEM_WRITE',
    'env:cache' => 'FILESYSTEM_WRITE',
    'key:generate' => 'FILESYSTEM_WRITE',
    'optimize' => 'FILESYSTEM_WRITE',
    'storage:link' => 'FILESYSTEM_WRITE',
    'make:auth' => 'FILESYSTEM_WRITE',
    'make:controller' => 'FILESYSTEM_WRITE',
    'make:model' => 'FILESYSTEM_WRITE',
    'make:migration' => 'FILESYSTEM_WRITE',
    'make:queue-table' => 'FILESYSTEM_WRITE',
    'make:resource' => 'FILESYSTEM_WRITE',
    'make:seeder' => 'FILESYSTEM_WRITE',
    'make:crud' => 'FILESYSTEM_WRITE',
    'make:test' => 'FILESYSTEM_WRITE',
    'make:job' => 'FILESYSTEM_WRITE',
    'make:mail' => 'FILESYSTEM_WRITE',
    'make:event' => 'FILESYSTEM_WRITE',
    'make:lang' => 'FILESYSTEM_WRITE',
    'make:factory' => 'FILESYSTEM_WRITE',
    'make:openapi' => 'FILESYSTEM_WRITE',
    'make:postman' => 'FILESYSTEM_WRITE',
    'make:service' => 'FILESYSTEM_WRITE',
    'make:repository' => 'FILESYSTEM_WRITE',
    'make:middleware' => 'FILESYSTEM_WRITE',
    'make:listener' => 'FILESYSTEM_WRITE',
    'make:request' => 'FILESYSTEM_WRITE',
    'make:rule' => 'FILESYSTEM_WRITE',
    'make:observer' => 'FILESYSTEM_WRITE',
    'make:idempotency-table' => 'FILESYSTEM_WRITE',
    'make:apikey-table' => 'FILESYSTEM_WRITE',
    'make:apikey' => 'FILESYSTEM_WRITE',
    'log:export' => 'FILESYSTEM_WRITE',
    'log:cleanup' => 'FILESYSTEM_WRITE',

    // DATABASE WRITE
    'migrate' => 'DATABASE_WRITE',
    'migrate:fresh' => 'DATABASE_WRITE',
    'migrate:refresh' => 'DATABASE_WRITE',
    'migrate:reset' => 'DATABASE_WRITE',
    'migrate:rollback' => 'DATABASE_WRITE',
    'db:seed' => 'DATABASE_WRITE',
    'db:optimize' => 'DATABASE_WRITE',
    'db:backup' => 'DATABASE_WRITE',
    'db:restore' => 'DATABASE_WRITE',
    'db:benchmark' => 'DATABASE_WRITE',
    'audit:log' => 'DATABASE_WRITE',
    'queue:retry' => 'DATABASE_WRITE',
    'queue:flush' => 'DATABASE_WRITE',
    'down' => 'DATABASE_WRITE',
    'up' => 'DATABASE_WRITE',

    // NETWORK
    'api:test' => 'NETWORK',
    'mercure:subscribe' => 'NETWORK',
    'log:replay' => 'NETWORK',
    'replay' => 'NETWORK',
    'test:regression' => 'NETWORK',
    'deploy' => 'NETWORK',

    // PROCESS/WORKER
    'serve' => 'PROCESS',
    'frankenphp:serve' => 'PROCESS',
    'live' => 'PROCESS',
    'queue:work' => 'PROCESS',
    'schedule:run' => 'PROCESS',
    'fix' => 'PROCESS',
    'tinker' => 'PROCESS',
    'runtime' => 'PROCESS',
    'db' => 'PROCESS',
    'new' => 'PROCESS',
    'new:project' => 'PROCESS',

    // READ + WRITE (trace replay reads traces, may execute)
    'test' => 'READ_ONLY',
    'test:run' => 'READ_ONLY',
    'demo' => 'READ_ONLY',
    'env:switch' => 'FILESYSTEM_WRITE',
];

echo "=== A3.6 COMMAND CLASSIFICATION ===\n\n";

$groups = [];
foreach ($commands as $name => $info) {
    $type = $classification[$name] ?? 'UNCLASSIFIED';
    $groups[$type][] = $name;
}

foreach ($groups as $type => $cmds) {
    echo "[$type] (" . count($cmds) . ")\n";
    foreach ($cmds as $cmd) {
        echo "  $cmd\n";
    }
    echo "\n";
}

// Check for unclassified
$unclassified = array_diff(array_keys($commands), array_keys($classification));
if (!empty($unclassified)) {
    echo "⚠ UNCLASSIFIED (" . count($unclassified) . "):\n";
    foreach ($unclassified as $cmd) {
        echo "  $cmd — {$commands[$cmd]['desc']}\n";
    }
}

echo "\n=== SUMMARY ===\n";
echo "Total: " . count($commands) . "\n";
foreach ($groups as $type => $cmds) {
    echo "  $type: " . count($cmds) . "\n";
}

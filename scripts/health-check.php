#!/usr/bin/env php
<?php
declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

$basePath = $argv[1] ?? (defined('BASE_PATH') && is_string(BASE_PATH) ? BASE_PATH : getcwd());
$format = $argv[2] ?? 'cli';

$storageDir = rtrim($basePath, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'storage';

$checks = [
    'php_version' => ['label' => 'PHP Version', 'pass' => version_compare(PHP_VERSION, '8.2.0', '>='), 'detail' => PHP_VERSION],
    'ext_pdo' => ['label' => 'Extension: PDO', 'pass' => extension_loaded('pdo'), 'detail' => ''],
    'ext_json' => ['label' => 'Extension: JSON', 'pass' => extension_loaded('json'), 'detail' => ''],
    'ext_mbstring' => ['label' => 'Extension: mbstring', 'pass' => extension_loaded('mbstring'), 'detail' => ''],
    'ext_openssl' => ['label' => 'Extension: OpenSSL', 'pass' => extension_loaded('openssl'), 'detail' => ''],
];

if (class_exists('\Siro\Core\Env')) {
    $envPath = $basePath . DIRECTORY_SEPARATOR . '.env';
    $checks['env_file'] = ['label' => '.env File', 'pass' => is_file($envPath), 'detail' => ''];

    $jwt = (string) \Siro\Core\Env::get('JWT_SECRET', '');
    $checks['jwt_secret'] = ['label' => 'JWT_SECRET', 'pass' => strlen($jwt) >= 32, 'detail' => strlen($jwt) >= 32 ? 'configured' : 'missing'];
}

$checks['storage'] = ['label' => 'Storage Writable', 'pass' => is_dir($storageDir) && is_writable($storageDir), 'detail' => ''];

$frameworkDir = $storageDir . DIRECTORY_SEPARATOR . 'framework';
$checks['framework_writable'] = ['label' => 'Framework Cache Dir', 'pass' => is_dir($frameworkDir) && is_writable($frameworkDir), 'detail' => ''];

$logDir = $storageDir . DIRECTORY_SEPARATOR . 'logs';
$checks['logs_writable'] = ['label' => 'Logs Dir Writable', 'pass' => is_dir($logDir) && is_writable($logDir), 'detail' => ''];

$dbConfig = $basePath . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'database.php';
if (is_file($dbConfig)) {
    try {
        $config = (array) require $dbConfig;
        \Siro\Core\Database::configure($config);
        $pdo = \Siro\Core\Database::connection();
        $pdo->query('SELECT 1');
        $checks['database'] = ['label' => 'Database', 'pass' => true, 'detail' => 'connected'];
    } catch (\Throwable) {
        $checks['database'] = ['label' => 'Database', 'pass' => false, 'detail' => 'connection failed'];
    }
} else {
    $checks['database'] = ['label' => 'Database', 'pass' => true, 'detail' => 'no config (optional)'];
}

$allPass = true;
$results = [];
foreach ($checks as $key => $c) {
    $results[$key] = ['status' => $c['pass'] ? 'ok' : 'fail', 'detail' => $c['detail']];
    if (!$c['pass']) $allPass = false;
}

if ($format === 'json') {
    echo json_encode([
        'status' => $allPass ? 'ok' : 'fail',
        'version' => PHP_VERSION,
        'checks' => $results,
        'timestamp' => date('c'),
    ]);
    exit($allPass ? 0 : 1);
}

echo "=== Siro Health Check ===\n";
echo "PHP: " . PHP_VERSION . "\n\n";
foreach ($checks as $c) {
    $symbol = $c['pass'] ? 'PASS' : 'FAIL';
    echo "  [$symbol] {$c['label']}";
    if ($c['detail'] !== '') echo " ({$c['detail']})";
    echo "\n";
}
echo "\n" . ($allPass ? 'All checks passed' : 'Some checks failed') . "\n";
exit($allPass ? 0 : 1);

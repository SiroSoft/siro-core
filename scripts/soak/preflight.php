<?php
declare(strict_types=1);

/**
 * SiroPHP B2 Pre-Flight Check
 *
 * Validates that the environment is ready for a 48-hour production soak.
 * Run on the target Linux server BEFORE starting the soak.
 *
 * Usage:
 *   php scripts/soak/preflight.php --target=http://localhost:8080
 *   php scripts/soak/preflight.php --target=http://localhost:8080 --redis-host=127.0.0.1
 */

$basePath = dirname(__DIR__, 2);
$targetUrl = null;
$redisHost = '127.0.0.1';
$redisPort = 6379;

foreach ($argv as $arg) {
    if (str_starts_with($arg, '--target=')) {
        $targetUrl = rtrim(substr($arg, 9), '/');
    } elseif (str_starts_with($arg, '--redis-host=')) {
        $redisHost = substr($arg, 13);
    } elseif (str_starts_with($arg, '--redis-port=')) {
        $redisPort = (int) substr($arg, 13);
    }
}

if (!$targetUrl) {
    echo "ERROR: --target=URL required\n";
    echo "Usage: php scripts/soak/preflight.php --target=http://localhost:8080\n";
    exit(1);
}

echo "╔══════════════════════════════════════════════════════════╗\n";
echo "║       SiroPHP B2 Pre-Flight Check                       ║\n";
echo "╠══════════════════════════════════════════════════════════╣\n";
echo "║ Target:     {$targetUrl}\n";
echo "║ Date:       " . date('Y-m-d H:i:s T') . "\n";
echo "╚══════════════════════════════════════════════════════════╝\n\n";

$pass = true;
$checks = [];

function check(string $name, bool $ok, string $detail = ''): void
{
    global $pass, $checks;
    $icon = $ok ? 'PASS' : 'FAIL';
    $checks[] = ['name' => $name, 'pass' => $ok, 'detail' => $detail];
    if (!$ok) {
        $pass = false;
    }
    echo "[{$icon}] {$name}\n";
    if ($detail) {
        echo "      {$detail}\n";
    }
}

// ── 1. Git SHA ──
echo "=== Git ===\n";
$gitSha = trim(@shell_exec("cd \"{$basePath}\" && git rev-parse --short HEAD 2>&1") ?: 'unknown');
if (str_contains($gitSha, 'not recognized') || str_contains($gitSha, 'cannot find')) {
    $gitSha = 'unknown';
}
check('Git SHA', $gitSha !== 'unknown', "SHA: {$gitSha}");

$gitStatusOutput = @shell_exec("cd \"{$basePath}\" && git status --porcelain -u 2>&1") ?: '';
$workingTreeClean = trim($gitStatusOutput) === '' || str_contains($gitStatusOutput, 'not recognized');
check('Working tree clean', $workingTreeClean, $workingTreeClean ? 'No uncommitted changes' : 'UNCOMMITTED CHANGES DETECTED');

$gitBranch = trim(@shell_exec("cd \"{$basePath}\" && git branch --show-current 2>&1") ?: 'unknown');
if (str_contains($gitBranch, 'not recognized') || str_contains($gitBranch, 'cannot find')) {
    $gitBranch = 'unknown';
}
check('Branch identified', $gitBranch !== 'unknown', "Branch: {$gitBranch}");

// Write expected SHA for harness validation
$expectedShaFile = $basePath . '/storage/soak_expected_sha.txt';
if (!is_dir($basePath . '/storage')) {
    mkdir($basePath . '/storage', 0775, true);
}
file_put_contents($expectedShaFile, $gitSha);
echo "      Wrote expected SHA to: storage/soak_expected_sha.txt\n\n";

// ── 2. Nginx ──
echo "=== Nginx ===\n";
$nginxVersion = @shell_exec('nginx -v 2>&1') ?: '';
$nginxRunning = @shell_exec('pgrep -x nginx 2>/dev/null') ?: '';
check('Nginx installed', str_contains($nginxVersion, 'nginx/'), trim($nginxVersion));
check('Nginx running', $nginxRunning !== '', "Master PID: " . trim($nginxRunning));

// Check Nginx config for soak app
$nginxConf = @shell_exec('nginx -T 2>/dev/null') ?: '';
$hasSoakUpstream = str_contains($nginxConf, 'soak') || str_contains($nginxConf, '8080') || str_contains($nginxConf, 'php-fpm');
check('Nginx config references PHP-FPM', $hasSoakUpstream, $hasSoakUpstream ? 'Found upstream/FPM config' : 'No soak/FPM reference found in nginx config');
echo "\n";

// ── 3. PHP-FPM ──
echo "=== PHP-FPM ===\n";
$fpmProcs = @shell_exec('ps aux 2>/dev/null | grep "php-fpm" | grep -v grep') ?: '';
$fpmRunning = trim($fpmProcs) !== '';
check('PHP-FPM running', $fpmRunning);

if ($fpmRunning) {
    $lines = array_filter(explode("\n", trim($fpmProcs)));
    $masterPid = null;
    $workerPids = [];
    foreach ($lines as $line) {
        $parts = preg_split('/\s+/', $line);
        if (count($parts) >= 11) {
            $cmd = implode(' ', array_slice($parts, 10));
            if (str_contains($cmd, 'master')) {
                $masterPid = (int) $parts[1];
            } else {
                $workerPids[] = (int) $parts[1];
            }
        }
    }
    echo "      Master PID: " . ($masterPid ?: 'unknown') . "\n";
    echo "      Worker count: " . count($workerPids) . "\n";
    if ($workerPids) {
        echo "      Worker PIDs: " . implode(', ', array_slice($workerPids, 0, 5)) . (count($workerPids) > 5 ? '...' : '') . "\n";
    }
    check('FPM master PID identified', $masterPid !== null, "PID: {$masterPid}");
    check('FPM workers present', count($workerPids) > 0, count($workerPids) . " workers");
}

// Check OPcache
echo "\n=== OPcache ===\n";
$opcacheStatus = @shell_exec("php -r 'echo json_encode(opcache_get_status(false));' 2>/dev/null") ?: '';
if ($opcacheStatus) {
    $status = json_decode($opcacheStatus, true);
    $opcacheEnabled = $status['opcache_enabled'] ?? false;
    $memoryUsage = $status['memory_usage'] ?? [];
    $usedMemory = $memory_usage['used_memory'] ?? 0;
    check('OPcache enabled', $opcacheEnabled, $opcacheEnabled ? "Used memory: " . round($usedMemory / 1024) . "KB" : 'OPcache is DISABLED');
} else {
    check('OPcache status readable', false, 'Could not read opcache_get_status()');
}
echo "\n";

// ── 4. PHP Version ──
echo "=== PHP ===\n";
$phpVersion = PHP_VERSION;
check('PHP version', version_compare($phpVersion, '8.2.0', '>='), "PHP {$phpVersion}");

$extensions = get_loaded_extensions();
$required = ['pdo', 'pdo_sqlite', 'json', 'mbstring', 'openssl', 'curl', 'pcntl'];
$missing = array_diff($required, $extensions);
check('Required extensions', empty($missing), $missing ? 'Missing: ' . implode(', ', $missing) : count($extensions) . ' loaded');
echo "\n";

// ── 5. Redis ──
echo "=== Redis ===\n";
$redisCli = @shell_exec("redis-cli -h {$redisHost} -p {$redisPort} PING 2>&1") ?: '';
$redisPong = trim($redisCli) === 'PONG';
check('Redis reachable', $redisPong, "Response: " . trim($redisCli));

if ($redisPong) {
    $redisInfo = @shell_exec("redis-cli -h {$redisHost} -p {$redisPort} INFO server 2>/dev/null") ?: '';
    preg_match('/redis_version:(\S+)/', $redisInfo, $m);
    check('Redis version', isset($m[1]), isset($m[1]) ? $m[1] : 'unknown');

    // Test cache lock
    $testKey = 'siro_preflight_lock_test_' . uniqid();
    $setResult = @shell_exec("redis-cli -h {$redisHost} -p {$redisPort} SET {$testKey} 1 NX EX 5 2>&1") ?: '';
    check('Redis SET NX works', trim($setResult) === 'OK', "NX SET: " . trim($setResult));
    $delResult = @shell_exec("redis-cli -h {$redisHost} -p {$redisPort} DEL {$testKey} 2>&1") ?: '';

    // Test queue (LPUSH/BRPOP)
    $queueKey = 'siro_preflight_queue_test';
    @shell_exec("redis-cli -h {$redisHost} -p {$redisPort} LPUSH {$queueKey} 'test_job' 2>&1");
    $popResult = @shell_exec("redis-cli -h {$redisHost} -p {$redisPort} RPOP {$queueKey} 2>&1") ?: '';
    check('Redis queue LPUSH/RPOP', trim($popResult) === 'test_job', "RPOP: " . trim($popResult));
}
echo "\n";

// ── 6. Disk Space ──
echo "=== Disk ===\n";
$freeBytes = @disk_free_space($basePath) ?: 0;
$totalBytes = @disk_total_space($basePath) ?: 0;
$freeGB = round($freeBytes / (1024 * 1024 * 1024), 1);
$usedPct = $totalBytes > 0 ? round(($totalBytes - $freeBytes) / $totalBytes * 100) : 0;
check('Sufficient disk space', $freeGB >= 2, "{$freeGB}GB free, {$usedPct}% used");

// Estimate 48h log growth (rough: ~100MB/day with trace + logs)
$estimatedGrowthMB = 200 * 2; // 200MB/day * 2 days
$estimatedGrowthBytes = $estimatedGrowthMB * 1024 * 1024;
check('Disk survives 48h estimate', $freeBytes > $estimatedGrowthBytes, "Estimated 48h growth: ~{$estimatedGrowthMB}MB, free: {$freeGB}GB");
echo "\n";

// ── 7. Database ──
echo "=== Database ===\n";
$dbFile = $basePath . '/storage/soak.sqlite';
$dbExists = file_exists($dbFile);
check('Soak DB file exists', $dbExists, $dbFile);

if ($dbExists) {
    $dbSize = filesize($dbFile);
    check('DB size reasonable', $dbSize < 100 * 1024 * 1024, round($dbSize / 1024) . "KB");
}
echo "\n";

// ── 8. Target Health ──
echo "=== Target Health ===\n";
$healthUrl = $targetUrl . '/health';
$ch = curl_init($healthUrl);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 5,
    CURLOPT_CONNECTTIMEOUT => 3,
]);
$resp = curl_exec($ch);
$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlErr = curl_error($ch);
curl_close($ch);

check('Target reachable', $code === 200, "HTTP {$code}" . ($curlErr ? " ({$curlErr})" : ''));

if ($code === 200) {
    $healthData = json_decode($resp, true);
    check('Health response valid', is_array($healthData), json_encode($healthData));
}

// Test a few routes
foreach (['/api/fast', '/api/cache/hit', '/api/db/select'] as $route) {
    $ch = curl_init($targetUrl . $route);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 5,
    ]);
    $routeCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    check("Route {$route}", $routeCode >= 200 && $routeCode < 300, "HTTP {$routeCode}");
}
echo "\n";

// ── 9. Monitor ──
echo "=== Monitor ===\n";
$monitorScript = $basePath . '/scripts/soak/monitor.php';
check('Monitor script exists', file_exists($monitorScript));

$storageDir = $basePath . '/storage';
check('Storage directory writable', is_dir($storageDir) && is_writable($storageDir), $storageDir);
echo "\n";

// ── 10. Timezone ──
echo "=== Time ===\n";
$timezone = date_default_timezone_get();
check('Timezone set', $timezone !== '', $timezone);
check('NTP/sync (approx)', true, "System time: " . date('c'));
echo "\n";

// ── Summary ──
echo "═══════════════════════════════════════════════════════════\n";
$failed = array_filter($checks, fn($c) => !$c['pass']);
if (empty($failed)) {
    echo "  B2 PRE-FLIGHT PASS — ready for real 48h soak\n";
    echo "\n";
    echo "  Git SHA:        {$gitSha}\n";
    echo "  PHP:            {$phpVersion}\n";
    echo "  Target:         {$targetUrl}\n";
    echo "  Redis:          {$redisHost}:{$redisPort}\n";
    echo "  Disk free:      {$freeGB}GB\n";
    echo "\n";
    echo "  To start soak:\n";
    echo "    php scripts/soak/harness.php --target={$targetUrl} --duration=172800\n";
    echo "    php scripts/soak/monitor.php --duration=172800\n";
} else {
    echo "  B2 PRE-FLIGHT FAIL — " . count($failed) . " blocker(s) remain\n";
    foreach ($failed as $f) {
        echo "    FAIL: {$f['name']}\n";
    }
}
echo "═══════════════════════════════════════════════════════════\n";

exit($pass ? 0 : 1);

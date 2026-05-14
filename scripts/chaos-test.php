#!/usr/bin/env php
<?php
declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

$basePath = dirname(__DIR__);

// Backup and restore helper
$backups = [];
function backupConfig(string $key): void { global $backups; $backups[$key] = $_ENV[$key] ?? null; }
function restoreConfig(string $key): void { global $backups; if (array_key_exists($key, $backups)) { $_ENV[$key] = $backups[$key]; unset($backups[$key]); } }

echo "=== Siro Chaos Engineering Tests ===\n\n";

$results = ['passed' => 0, 'failed' => 0, 'skipped' => 0];
$exitCode = 0;

function assertChaos(string $name, callable $fn): void {
    global $results, $exitCode;
    try {
        $result = $fn();
        if ($result === true) {
            echo "  PASS  $name\n";
            $results['passed']++;
        } else {
            echo "  FAIL  $name" . ($result !== false ? " — $result" : '') . "\n";
            $results['failed']++;
            $exitCode = 1;
        }
    } catch (\Throwable $e) {
        echo "  FAIL  $name — " . $e->getMessage() . "\n";
        $results['failed']++;
        $exitCode = 1;
    }
}

assertChaos('Session save after destroy', function (): bool {
    $session = new \Siro\Core\Session();
    $session->start();
    $session->set('test_key', 'chaos_value');
    $session->save();
    $session->destroy();
    $session->start('chaos_test_invalid_id_that_should_not_exist');
    $val = $session->get('test_key');
    return $val === null ? true : 'Session data leaked after destroy';
});

assertChaos('Validator with embedded null bytes', function (): bool {
    $result = \Siro\Core\Validator::make(
        ['name' => "test\x00user.php"],
        ['name' => 'required|string|min:3']
    );
    return is_array($result) ? true : 'Validator threw on null bytes';
});

assertChaos('Cache miss returns null', function (): bool {
    $result = \Siro\Core\Cache::get('__chaos_nonexistent_' . bin2hex(random_bytes(8)));
    return $result === null ? true : 'Cache miss did not return null';
});

assertChaos('Logger with PII in message', function (): bool {
    try {
        \Siro\Core\Logger::debug('User password=super_secret_123 token=abc123');
        return true;
    } catch (\Throwable) {
        return 'Logger threw on PII data';
    }
});

assertChaos('Encrypter with binary payload', function (): bool {
    $original = random_bytes(1024);
    $encrypted = \Siro\Core\Encrypter::encrypt($original);
    $decrypted = \Siro\Core\Encrypter::decrypt($encrypted);
    return hash_equals($original, $decrypted) ? true : 'Encrypt/decrypt mismatch on binary data';
});

assertChaos('Event dispatch with null payload', function (): bool {
    try {
        \Siro\Core\Event::emit('chaos.null_test', null);
        return true;
    } catch (\Throwable $e) {
        return 'Event dispatch threw on null: ' . $e->getMessage();
    }
});

assertChaos('Config get with deep dot notation', function (): bool {
    $result = \Siro\Core\Config::get('nonexistent.deeply.nested.key');
    return $result === null ? true : 'Config dot notation returned non-null for missing key';
});

echo "\n=== Results: {$results['passed']} passed, {$results['failed']} failed, {$results['skipped']} skipped ===\n";
exit($exitCode);

<?php

declare(strict_types=1);

namespace Siro\Core;

/**
 * Immutable, tamper-evident audit trail.
 *
 * Appends entries to a JSONL file in storage/logs/audit/. Each entry is
 * HMAC-SHA256-signed over (previous entry hash + entry payload). Verifying
 * the chain detects any modification, insertion, or deletion of entries.
 *
 * Chain structure:
 *   entry[0].prev_hash = sha256 of a per-file genesis secret
 *   entry[n].prev_hash = entry[n-1].hash
 *   entry[n].hash       = hmac(secret, prev_hash + canonical(entry))
 *
 * @package Siro\Core
 */
final class Audit
{
    private const GENESIS = 'siro-audit-genesis';

    /** @var array<string, string> */
    private static array $secretCache = [];

    /** @var string|null Override for tests — when set, used instead of BASE_PATH. */
    private static ?string $basePathOverride = null;

    public static function setBasePath(string $path): void
    {
        self::$basePathOverride = rtrim($path, DIRECTORY_SEPARATOR);
    }

    public static function resetBasePath(): void
    {
        self::$basePathOverride = null;
    }

    private static function basePath(): string
    {
        if (self::$basePathOverride !== null) {
            return self::$basePathOverride;
        }
        return defined('BASE_PATH') && is_string(BASE_PATH)
            ? BASE_PATH
            : (getcwd() ?: '.');
    }

    private static function auditDir(): string
    {
        $dir = rtrim(self::basePath(), DIRECTORY_SEPARATOR)
            . DIRECTORY_SEPARATOR . 'storage'
            . DIRECTORY_SEPARATOR . 'logs'
            . DIRECTORY_SEPARATOR . 'audit';
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        return $dir;
    }

    private static function currentFile(): string
    {
        return self::auditDir() . DIRECTORY_SEPARATOR . 'audit-' . date('Y-m-d') . '.jsonl';
    }

    /**
     * Resolve the HMAC signing secret from environment, with a random fallback
     * that is persisted so the chain stays verifiable across requests.
     */
    private static function secret(): string
    {
        $base = self::basePath();
        if (isset(self::$secretCache[$base])) {
            return self::$secretCache[$base];
        }

        $env = Env::get('AUDIT_HMAC_SECRET', '');
        if (is_string($env) && $env !== '') {
            self::$secretCache[$base] = $env;
            return $env;
        }

        $keyFile = self::auditDir() . DIRECTORY_SEPARATOR . '.secret';
        $secret = '';
        if (is_file($keyFile)) {
            $secret = trim((string) file_get_contents($keyFile));
        }
        if ($secret === '') {
            $secret = bin2hex(random_bytes(32));
            @file_put_contents($keyFile, $secret . PHP_EOL, LOCK_EX);
        }
        self::$secretCache[$base] = $secret;
        return $secret;
    }

    /**
     * Append an audit entry to the tamper-evident trail.
     *
     * @param string $action   e.g. 'auth.login', 'user.update', 'order.delete'
     * @param array<string, mixed> $context
     * @return array<string, mixed>|null the stored entry, or null on failure
     */
    public static function log(string $action, array $context = []): ?array
    {
        $file = self::currentFile();
        $prevHash = self::lastHash($file);

        $entry = [
            'index' => self::nextIndex($file),
            'time' => date('c'),
            'action' => $action,
            'actor' => self::resolveActor($context),
            'context' => $context,
        ];

        $canonical = self::canonicalize($entry);
        $entry['prev_hash'] = $prevHash;
        $entry['hash'] = hash_hmac('sha256', $prevHash . $canonical, self::secret());

        $line = json_encode($entry, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($line === false) {
            return null;
        }

        $written = @file_put_contents($file, $line . PHP_EOL, FILE_APPEND | LOCK_EX);
        if ($written === false) {
            return null;
        }

        return $entry;
    }

    /**
     * Verify the integrity of the audit chain for a given file (or today's).
     * Returns ['ok' => true] on success, or detailed failure info.
     *
     * @return array{ok: bool, file: string, entries: int, broken?: int, reason?: string}
     */
    public static function verify(?string $file = null): array
    {
        $file = $file ?? self::currentFile();
        if (!is_file($file)) {
            return ['ok' => true, 'file' => $file, 'entries' => 0];
        }

        $lines = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($lines === false) {
            return ['ok' => false, 'file' => $file, 'entries' => 0, 'reason' => 'unreadable'];
        }

        $prevHash = hash('sha256', self::GENESIS);
        $count = 0;
        $secret = self::secret();

        foreach ($lines as $line) {
            $entry = json_decode((string) $line, true);
            if (!is_array($entry)) {
                return ['ok' => false, 'file' => $file, 'entries' => $count, 'broken' => $count + 1, 'reason' => 'invalid json'];
            }
            $storedHash = is_string($entry['hash'] ?? null) ? $entry['hash'] : '';
            $storedPrev = is_string($entry['prev_hash'] ?? null) ? $entry['prev_hash'] : '';

            if (!hash_equals($storedPrev, $prevHash)) {
                return ['ok' => false, 'file' => $file, 'entries' => $count, 'broken' => $count + 1, 'reason' => 'chain broken (prev_hash mismatch)'];
            }

            $hashFields = ['index', 'time', 'action', 'actor', 'context'];
            $payload = [];
            foreach ($hashFields as $f) {
                $payload[$f] = $entry[$f] ?? null;
            }
            $canonical = self::canonicalize($payload);
            $expected = hash_hmac('sha256', $prevHash . $canonical, $secret);

            if (!hash_equals($expected, $storedHash)) {
                return ['ok' => false, 'file' => $file, 'entries' => $count, 'broken' => $count + 1, 'reason' => 'tampered (hash mismatch)'];
            }

            $prevHash = $storedHash;
            $count++;
        }

        return ['ok' => true, 'file' => $file, 'entries' => $count];
    }

    /**
     * List all audit files.
     *
     * @return array<int, string>
     */
    public static function files(): array
    {
        $files = glob(rtrim(self::auditDir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'audit-*.jsonl');
        return $files !== false ? $files : [];
    }

    /**
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    private static function resolveActor(array $context): array
    {
        $actor = $context['actor'] ?? null;
        if (is_array($actor)) {
            return ['type' => 'custom', 'id' => null, 'ip' => '', 'data' => $actor];
        }
        $ip = isset($context['ip']) && is_string($context['ip']) ? $context['ip'] : '';
        return [
            'type' => is_string($actor) && $actor !== '' ? $actor : 'system',
            'id' => null,
            'ip' => $ip,
        ];
    }

    /**
     * Canonical deterministic serialization so the hash is stable across runs.
     *
     * @param array<string, mixed> $data
     */
    private static function canonicalize(array $data): string
    {
        return (string) json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    private static function lastHash(string $file): string
    {
        if (!is_file($file)) {
            return hash('sha256', self::GENESIS);
        }
        $lines = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($lines === false || $lines === []) {
            return hash('sha256', self::GENESIS);
        }
        $last = trim((string) $lines[count($lines) - 1]);
        $decoded = json_decode($last, true);
        if (!is_array($decoded)) {
            return hash('sha256', self::GENESIS);
        }
        return is_string($decoded['hash'] ?? null) ? $decoded['hash'] : hash('sha256', self::GENESIS);
    }

    private static function nextIndex(string $file): int
    {
        if (!is_file($file)) {
            return 1;
        }
        $lines = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($lines === false || $lines === []) {
            return 1;
        }
        $last = trim((string) $lines[count($lines) - 1]);
        $decoded = json_decode($last, true);
        if (!is_array($decoded)) {
            return 1;
        }
        $lastIdx = is_numeric($decoded['index'] ?? null) ? (int) $decoded['index'] : 0;
        return $lastIdx + 1;
    }
}

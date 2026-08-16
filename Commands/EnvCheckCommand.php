<?php

declare(strict_types=1);

namespace Siro\Core\Commands;

use Siro\Core\Env;

/**
 * Check environment configuration — shows MySQL connection info when DB_CONNECTION=mysql.
 */
final class EnvCheckCommand implements \Siro\Core\Commands\CommandInterface {
    use CommandSupport;

    public function __construct(private readonly string $basePath)
    {
    }

    /** @var array<string, string> */
    private array $requiredConfig = [
        'APP_NAME' => 'Application name',
        'APP_ENV' => 'Application environment (production/testing/local)',
        'APP_DEBUG' => 'Debug mode (true/false)',
        'JWT_SECRET' => 'JWT signing secret (min 32 chars)',
        'DB_CONNECTION' => 'Database driver (mysql/pgsql/sqlite)',
    ];

    /** @param array<int, string> $args */
    public function run(array $args): int
    {
        Env::load($this->basePath . DIRECTORY_SEPARATOR . '.env');

        $envPath = $this->basePath . DIRECTORY_SEPARATOR . '.env';
        $envExists = is_file($envPath);

        $passed = 0;
        $failed = 0;

        $this->write('Environment Check');
        $this->write('');

        // Check .env file
        if (!$envExists) {
            $this->write('  [FAIL] .env file not found at: ' . $envPath);
            $failed++;
            return 1;
        }
        $this->write('  [OK]   .env file exists');
        $passed++;

        // Parse .env directly for sensitive keys that are excluded from cache
        $envValues = self::parseEnvRaw($envPath);

        // Check required configs
        foreach ($this->requiredConfig as $key => $description) {
            $value = $envValues[$key] ?? Env::get($key, '');
            if ($value === '' || $value === null) {
                $this->write('  [FAIL] ' . $key . ' is not set (' . $description . ')');
                $failed++;
                continue;
            }

            // Specific checks
            if ($key === 'JWT_SECRET' && strlen((string) $value) < 32) {
                $this->write('  [FAIL] ' . $key . ' is too weak (min 32 chars, currently ' . strlen((string) $value) . ')');
                $failed++;
                continue;
            }

            if ($key === 'JWT_SECRET') {
                $lower = strtolower((string) $value);
                if (str_contains($lower, 'change_this') || str_contains($lower, 'your_secret')) {
                    $this->write('  [FAIL] ' . $key . ' looks like a placeholder. Generate a real secret with: php siro key:generate');
                    $failed++;
                    continue;
                }
            }

            if ($key === 'APP_DEBUG') {
                $appEnv = strtolower((string) Env::get('APP_ENV', 'production'));
                $debug = strtolower((string) $value);
                if ($appEnv === 'production' && $debug === 'true') {
                    $this->write('  [FAIL] APP_DEBUG must be false in production environment');
                    $failed++;
                    continue;
                }
            }

            $this->write('  [OK]   ' . $key . ' = ' . $value);
            $passed++;
        }

        // Check PHP extensions
        $this->write('');
        $this->write('PHP Extensions:');
        $extensions = ['pdo', 'json', 'mbstring', 'pdo_mysql', 'pdo_sqlite', 'redis'];
        foreach ($extensions as $ext) {
            if (extension_loaded($ext)) {
                $this->write('  [OK]   ' . $ext);
                $passed++;
            } else {
                $this->write('  [WARN] ' . $ext . ' not loaded (optional for some drivers)');
                $failed++;
            }
        }

        // Check database version
        $dbDriver = Env::get('DB_CONNECTION', '');
        if ($dbDriver === 'mysql') {
            $this->write('');
            $this->write('Database:');
            try {
                $stmt = \Siro\Core\Database::connection()->query('SELECT VERSION() AS v');
                if ($stmt === false) {
                    $this->write('  [WARN] Could not query MySQL version');
                    $failed++;
                } else {
                    $row = $stmt->fetch(\PDO::FETCH_ASSOC);
                    $version = is_array($row) && isset($row['v']) && is_scalar($row['v']) ? (string) $row['v'] : '';
                    if (version_compare($version, '8.0', '<')) {
                        $this->write('  [FAIL] MySQL ' . $version . ' — 5.x does not support JSON columns. Upgrade to MySQL 8.0+');
                        $failed++;
                    } else {
                        $this->write('  [OK]   MySQL ' . $version . ' (JSON column supported)');
                        $passed++;
                    }
                }
            } catch (\Throwable $e) {
                $this->write('  [WARN] Could not connect to MySQL: ' . $e->getMessage());
                $failed++;
            }
        }

        // Check storage writable
        $this->write('');
        $this->write('Storage:');
        $storageDirs = ['storage/cache', 'storage/logs'];
        foreach ($storageDirs as $dir) {
            $fullPath = $this->basePath . DIRECTORY_SEPARATOR . $dir;
            if (!is_dir($fullPath)) {
                @mkdir($fullPath, 0775, true);
            }
            if (is_writable($fullPath)) {
                $this->write('  [OK]   ' . $dir . ' is writable');
                $passed++;
            } else {
                $this->write('  [FAIL] ' . $dir . ' is not writable');
                $failed++;
            }
        }

        $this->write('');
        $this->write('Results: ' . $passed . ' passed, ' . $failed . ' warnings/issues');

        return $failed > 0 ? 1 : 0;
    }

    /** @return array<string, string> */
    private static function parseEnvRaw(string $path): array
    {
        $values = [];
        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($lines === false) return $values;
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) continue;
            $pos = strpos($line, '=');
            if ($pos === false) continue;
            $key = trim(substr($line, 0, $pos));
            $value = trim(substr($line, $pos + 1));
            if ($key !== '') $values[$key] = $value;
        }
        return $values;
    }
}

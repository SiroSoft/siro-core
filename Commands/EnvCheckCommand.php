<?php

declare(strict_types=1);

namespace Siro\Core\Commands;

use Siro\Core\Env;

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

        // Check required configs
        foreach ($this->requiredConfig as $key => $description) {
            $value = Env::get($key, '');
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
        $extensions = ['pdo', 'json', 'mbstring', 'pdo_mysql', 'pdo_sqlite'];
        foreach ($extensions as $ext) {
            if (extension_loaded($ext)) {
                $this->write('  [OK]   ' . $ext);
                $passed++;
            } else {
                $this->write('  [WARN] ' . $ext . ' not loaded (optional for some drivers)');
                $failed++;
            }
        }

        // Check database version (MySQL 5.x không hỗ trợ JSON column)
        $this->write('');
        $this->write('Database:');
        $dbDriver = Env::get('DB_CONNECTION', '');
        if ($dbDriver === 'mysql') {
            try {
                $stmt = \Siro\Core\Database::connection()->query('SELECT VERSION() AS v');
                if ($stmt === false) {
                    $this->write('  [WARN] Không thể query MySQL version');
                    $failed++;
                } else {
                    $row = $stmt->fetch(\PDO::FETCH_ASSOC);
                    $version = is_array($row) && isset($row['v']) && is_scalar($row['v']) ? (string) $row['v'] : '';
                    if (version_compare($version, '8.0', '<')) {
                        $this->write('  [FAIL] MySQL ' . $version . ' — phiên bản 5.x không hỗ trợ JSON column. Nâng cấp lên MySQL 8.0+');
                        $failed++;
                    } else {
                        $this->write('  [OK]   MySQL ' . $version . ' (hỗ trợ JSON column)');
                        $passed++;
                    }
                }
            } catch (\Throwable $e) {
                $this->write('  [WARN] Không thể kết nối MySQL: ' . $e->getMessage());
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
}

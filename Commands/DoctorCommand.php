<?php

declare(strict_types=1);

namespace Siro\Core\Commands;

use Siro\Core\Env;

final class DoctorCommand implements \Siro\Core\Commands\CommandInterface {
    use CommandSupport;

    public function __construct(private readonly string $basePath)
    {
    }

    /**
 * Environment health check.
 *
 * Verifies PHP version, extensions, .env config, JWT strength,
 * storage permissions, and database connectivity.
 *
 * @package Siro\Core\Commands
 * @param array<int, string> $args
 */
    public function run(array $args): int
    {
        $isProd = in_array('--prod', $args, true);

        Env::load($this->basePath . DIRECTORY_SEPARATOR . '.env');

        $title = $isProd ? 'SiroPHP Production Doctor' : 'SiroPHP Environment Doctor';
        $this->write($title . "\n");
        $this->write(str_repeat('=', strlen($title)) . "\n\n");

        $allPassed = true;

        // Check PHP Version
        $phpVersion = PHP_VERSION;
        $phpOk = version_compare($phpVersion, '8.2.0', '>=');
        $this->printCheck('PHP Version', $phpVersion . ' (>= 8.2 required)', $phpOk);
        if (!$phpOk) $allPassed = false;

        if ($isProd) {
            $phpProdOk = version_compare($phpVersion, '8.3.0', '>=');
            if ($phpProdOk) {
                $this->write("    ℹ️  ✅ Production-ready version (>= 8.3)\n");
            } else {
                $this->write("    ⚠️  ⚠️  Consider upgrading to PHP 8.3+ for production\n");
            }
        }

        // Check Required Extensions
        $extensions = [
            'pdo' => 'PDO (database abstraction)',
            'json' => 'JSON support',
            'mbstring' => 'Multibyte string support',
            'openssl' => 'OpenSSL (encryption)',
        ];

        foreach ($extensions as $ext => $desc) {
            $loaded = extension_loaded($ext);
            $this->printCheck("Extension: {$ext}", $desc, $loaded);
            if (!$loaded) $allPassed = false;
        }

        // Check PDO Drivers
        $dbConnection = strtolower((string) Env::get('DB_CONNECTION', 'mysql'));
        $pdoDrivers = [
            'mysql' => ['pdo_mysql', 'MySQL driver'],
            'pgsql' => ['pdo_pgsql', 'PostgreSQL driver'],
            'sqlite' => ['pdo_sqlite', 'SQLite driver'],
        ];

        if (isset($pdoDrivers[$dbConnection])) {
            [$driver, $desc] = $pdoDrivers[$dbConnection];
            $driverLoaded = extension_loaded($driver);
            $this->printCheck("PDO Driver: {$driver}", $desc . " (for {$dbConnection})", $driverLoaded);
            if (!$driverLoaded) $allPassed = false;
        } else {
            $this->printCheck("PDO Driver", "Unknown driver: {$dbConnection}", false);
            $allPassed = false;
        }

        // Check .env file
        $envExists = is_file($this->basePath . DIRECTORY_SEPARATOR . '.env');
        $this->printCheck('.env File', 'Configuration file exists', $envExists);
        if (!$envExists) $allPassed = false;

        // Check JWT_SECRET
        $jwtSecret = (string) Env::get('JWT_SECRET', '');
        $jwtOk = strlen($jwtSecret) >= 32 && !str_contains(strtolower($jwtSecret), 'change_this');
        $this->printCheck('JWT_SECRET', strlen($jwtSecret) >= 32 ? 'Configured (' . strlen($jwtSecret) . ' chars)' : 'Not configured or too short', $jwtOk);
        if (!$jwtOk) $allPassed = false;

        if ($isProd) {
            $appEnv = strtolower((string) Env::get('APP_ENV', 'production'));
            $appDebug = strtolower((string) Env::get('APP_DEBUG', 'false'));
            $envOk = $appEnv === 'production' && $appDebug === 'false';
            $this->printCheck('APP_ENV', $appEnv . ' (should be production)', $appEnv === 'production');
            if ($appEnv !== 'production') $allPassed = false;
            $this->printCheck('APP_DEBUG', $appDebug . ' (should be false)', $appDebug === 'false');
            if ($appDebug !== 'false') $allPassed = false;

            $httpsOk = Env::get('APP_URL', '') !== '' && str_starts_with((string) Env::get('APP_URL', ''), 'https://');
            $this->printCheck('HTTPS', Env::get('APP_URL', 'not set'), $httpsOk);
            if (!$httpsOk) $allPassed = false;

            $corsOk = Env::get('CORS_ALLOWED_ORIGINS', '') !== '';
            $this->printCheck('CORS', $corsOk ? Env::get('CORS_ALLOWED_ORIGINS', '') : 'Not configured', $corsOk);
            if (!$corsOk) {
                $this->write("    ⚠️  Set CORS_ALLOWED_ORIGINS to prevent unauthorized access\n");
            }
        }

        // Check Storage Writable
        $storageDir = $this->basePath . DIRECTORY_SEPARATOR . 'storage';
        $storageWritable = is_dir($storageDir) && is_writable($storageDir);
        $this->printCheck('Storage Directory', 'Writable', $storageWritable);
        if (!$storageWritable) $allPassed = false;

        // Check Log Files
        $logDir = $storageDir . DIRECTORY_SEPARATOR . 'logs';
        if (is_dir($logDir)) {
            $logFiles = ['error.log', 'slow.log'];
            foreach ($logFiles as $logFile) {
                $logPath = $logDir . DIRECTORY_SEPARATOR . $logFile;
                $exists = file_exists($logPath);
                $this->printCheck("Log File: {$logFile}", $exists ? 'Exists' : 'Missing', $exists);
                if (!$exists) $allPassed = false;
            }

            // Check log directory is NOT inside public/
            $publicDir = $this->basePath . DIRECTORY_SEPARATOR . 'public';
            $logInsidePublic = str_starts_with(realpath($logDir) ?: $logDir, rtrim(realpath($publicDir) ?: $publicDir, DIRECTORY_SEPARATOR));
            $this->printCheck('Logs outside public/', $logInsidePublic ? '⚠ INSIDE public/' : 'OK (outside)', !$logInsidePublic);
            if ($logInsidePublic) $allPassed = false;

            // Check .htaccess protection (Apache)
            $htaccess = $logDir . DIRECTORY_SEPARATOR . '.htaccess';
            $htaccessOk = file_exists($htaccess) && str_contains((string) file_get_contents($htaccess), 'Deny from all');
            $this->printCheck('Log Protection (.htaccess/Apache)', $htaccessOk ? 'Protected' : 'Missing', $htaccessOk);
            if (!$htaccessOk) $allPassed = false;

            // Check for Nginx-style protection (suggest if missing)
            $nginxConfig = $publicDir . DIRECTORY_SEPARATOR . 'nginx-log-protection.conf';
            if (!file_exists($nginxConfig) && $isProd) {
                $this->printCheck('Log Protection (Nginx config)', 'Missing (recommended)', false);
                $allPassed = false;
                $this->write('     Suggestion: Add to nginx config: location /storage/logs/ { deny all; }');
            }
        } else {
            $this->printCheck('Log Directory', 'storage/logs does not exist', false);
            $allPassed = false;
        }

        if ($isProd) {
            $traceDir = $storageDir . DIRECTORY_SEPARATOR . 'logs' . DIRECTORY_SEPARATOR . 'traces';
            $traceWritable = is_dir($traceDir) && is_writable($traceDir);
            $this->printCheck('Traces Directory', $traceWritable ? 'Writable' : 'Missing/Not writable', $traceWritable);
            if (!$traceWritable) $allPassed = false;

            $cacheDir = $storageDir . DIRECTORY_SEPARATOR . 'cache';
            if (is_dir($cacheDir)) {
                $cacheWritable = is_writable($cacheDir);
                $this->printCheck('Cache Directory', $cacheWritable ? 'Writable' : 'Not writable', $cacheWritable);
                if (!$cacheWritable) $allPassed = false;
            }
        }

        // Check Database Connection (required in production)
        $this->write("\nDatabase Connection Test:\n");
        try {
            /** @var array<string, mixed> $config */
            $config = require $this->basePath . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'database.php';
            \Siro\Core\Database::configure($config);
            $pdo = \Siro\Core\Database::connection();
            $pdo->query('SELECT 1');
            $this->write("  ✅ Database connection successful\n");
        } catch (\Throwable $e) {
            $msg = "  " . ($isProd ? '❌' : '⚠️') . "  Database connection failed: " . $e->getMessage() . "\n";
            $this->write($msg);
            if ($isProd) {
                $this->write("     ❌ Database is REQUIRED in production mode\n");
                $allPassed = false;
            } else {
                $this->write("     (This is OK if database server is not running locally)\n");
            }
        }

        // Final verdict
        $this->write("\n" . str_repeat('=', 50) . "\n");
        if ($allPassed) {
            $this->write("✅ All checks passed! System is " . ($isProd ? 'production' : 'development') . " ready.\n");
            return 0;
        } else {
            $this->write("❌ Some checks failed. Please fix the issues above.\n");
            return 1;
        }
    }

    private function printCheck(string $name, string $detail, bool $passed): void
    {
        $symbol = $passed ? '✅' : '❌';
        $status = $passed ? 'PASS' : 'FAIL';
        $this->write(sprintf("  %s %-25s %s - %s\n", $symbol, $name . ':', $status, $detail));
    }
}

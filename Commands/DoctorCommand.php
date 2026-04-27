<?php

declare(strict_types=1);

namespace Siro\Core\Commands;

use Siro\Core\Env;

final class DoctorCommand
{
    use CommandSupport;

    public function __construct(private readonly string $basePath)
    {
    }

    /** @param array<int, string> $args */
    public function run(array $args): int
    {
        unset($args);

        Env::load($this->basePath . DIRECTORY_SEPARATOR . '.env');

        $this->write("SiroPHP Environment Doctor\n");
        $this->write("==========================\n");

        $allPassed = true;

        // Check PHP Version
        $phpVersion = PHP_VERSION;
        $phpOk = version_compare($phpVersion, '8.2.0', '>=');
        $this->printCheck('PHP Version', $phpVersion . ' (>= 8.2 required)', $phpOk);
        if (!$phpOk) $allPassed = false;

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

        // Check Storage Writable
        $storageDir = $this->basePath . DIRECTORY_SEPARATOR . 'storage';
        $storageWritable = is_dir($storageDir) && is_writable($storageDir);
        $this->printCheck('Storage Directory', 'Writable', $storageWritable);
        if (!$storageWritable) $allPassed = false;

        // Check Log Files
        $logDir = $storageDir . DIRECTORY_SEPARATOR . 'logs';
        if (is_dir($logDir)) {
            $logFiles = ['request.log', 'error.log', 'slow.log'];
            foreach ($logFiles as $logFile) {
                $logPath = $logDir . DIRECTORY_SEPARATOR . $logFile;
                $exists = file_exists($logPath);
                $this->printCheck("Log File: {$logFile}", $exists ? 'Exists' : 'Missing', $exists);
                if (!$exists) $allPassed = false;
            }
        } else {
            $this->printCheck('Log Directory', 'storage/logs does not exist', false);
            $allPassed = false;
        }

        // Check Database Connection (optional)
        $this->write("\nDatabase Connection Test:");
        try {
            /** @var array<string, mixed> $config */
            $config = require $this->basePath . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'database.php';
            \Siro\Core\Database::configure($config);
            $pdo = \Siro\Core\Database::connection();
            $pdo->query('SELECT 1');
            $this->write("  ✅ Database connection successful\n");
        } catch (\Throwable $e) {
            $this->write("  ⚠️  Database connection failed: " . $e->getMessage() . "\n");
            $this->write("     (This is OK if database server is not running locally)\n");
        }

        // Final verdict
        $this->write("\n" . str_repeat('=', 50) . "\n");
        if ($allPassed) {
            $this->write("✅ All checks passed! Your environment is ready.\n");
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

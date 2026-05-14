#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * SAST Security Linter - checks for dangerous patterns in PHP code.
 * Zero external dependencies, runs anywhere PHP is available.
 */
final class SastLinter
{
    private int $errors = 0;
    private int $warnings = 0;

    /** @var array<string, array{pattern:string, severity:string, message:string}> */
    private array $rules = [];

    public function __construct()
    {
        $this->addRule('eval', '/\beval\s*\(/', 'ERROR', 'eval() detected - potential RCE');
        $this->addRule('extract', '/\bextract\s*\(/', 'ERROR', 'extract() detected - variable injection risk');
        $this->addRule('unserialize', '/\bunserialize\s*\(/', 'ERROR', 'unserialize() detected - potential RCE');
        $this->addRule('create_function', '/\bcreate_function\s*\(/', 'ERROR', 'create_function() detected - use closures instead');
        $this->addRule('preg_match_e', '/preg_replace\s*\(.*\/[eemsx]*e[emsx]*"/', 'ERROR', 'preg_replace /e modifier detected - RCE risk');
        $this->addRule('system', '/\bsystem\s*\(/', 'WARNING', 'system() detected - potential command injection');
        $this->addRule('exec', '/\bexec\s*\(/', 'WARNING', 'exec() detected - potential command injection');
        $this->addRule('shell_exec', '/\bshell_exec\s*\(/', 'WARNING', 'shell_exec() detected - potential command injection');
        $this->addRule('passthru', '/\bpassthru\s*\(/', 'WARNING', 'passthru() detected - potential command injection');
        $this->addRule('phpinfo', '/\bphpinfo\s*\(/', 'WARNING', 'phpinfo() detected - information disclosure');
        $this->addRule('error_reporting_off', '/error_reporting\s*\(\s*0\s*\)/', 'WARNING', 'error_reporting(0) hides errors - use proper logging');
        $this->addRule('var_dump', '/\bvar_dump\s*\(/', 'WARNING', 'var_dump() detected - debug code in production?');
    }

    private function addRule(string $id, string $pattern, string $severity, string $message): void
    {
        $this->rules[$id] = ['pattern' => $pattern, 'severity' => $severity, 'message' => $message];
    }

    public function scanDirectory(string $dir): void
    {
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir));
        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $this->scanFile($file->getPathname());
            }
        }
    }

    public function scanFile(string $path): void
    {
        $content = file_get_contents($path);
        if ($content === false) {
            return;
        }

        foreach ($this->rules as $id => $rule) {
            if (preg_match($rule['pattern'], $content, $matches, PREG_OFFSET_CAPTURE)) {
                $line = substr_count(substr($content, 0, $matches[0][1]), "\n") + 1;
                $severity = $rule['severity'];
                $msg = $rule['message'];

                if ($severity === 'ERROR') {
                    $this->errors++;
                    echo "[ERROR] {$path}:{$line} - {$msg}\n";
                } else {
                    $this->warnings++;
                    echo "[WARNING] {$path}:{$line} - {$msg}\n";
                }
            }
        }
    }

    public function getErrorCount(): int { return $this->errors; }
    public function getWarningCount(): int { return $this->warnings; }
}

// Run the linter
$linter = new SastLinter();
$linter->scanDirectory(__DIR__ . '/../');

echo "\n=== SAST Linter Summary ===\n";
echo "Errors: {$linter->getErrorCount()}\n";
echo "Warnings: {$linter->getWarningCount()}\n";

exit($linter->getErrorCount() > 0 ? 1 : 0);

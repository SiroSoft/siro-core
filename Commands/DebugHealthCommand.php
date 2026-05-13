<?php

declare(strict_types=1);

namespace Siro\Core\Commands;

use Siro\Core\Env;
use Siro\Core\Config;
use Siro\Core\Logger;
use Siro\Core\Console;

class DebugHealthCommand implements CommandInterface
{
    use CommandSupport;

    public static string $name = 'debug:health';
    public static string $desc = 'Check debug system health and configuration';
    public static string $usage = 'debug:health';

    /** @param array<int, string> $args */
    public function run(array $args = []): int
    {
        $issues = [];
        $checks = 0;
        $passed = 0;

        $this->info('Siro Debug Health Check');
        $this->write(str_repeat('-', 40));

        $checks++;
        if (PHP_VERSION_ID >= 80200) {
            $this->success('[PASS] PHP version: ' . PHP_VERSION);
            $passed++;
        } else {
            $this->error('[FAIL] PHP version: ' . PHP_VERSION);
            $issues[] = 'PHP version < 8.2';
        }

        $checks++;
        $appDebug = Env::bool('APP_DEBUG', false);
        $appEnv = Env::get('APP_ENV', 'production');
        $debugEffective = $appDebug && $appEnv !== 'production';
        $this->info("[CHECK] APP_DEBUG=" . ($appDebug ? 'true' : 'false') . ", APP_ENV={$appEnv}");
        if ($debugEffective) {
            $this->success('[PASS] Debug mode is active');
            $passed++;
        } else {
            $this->warn('[WARN] Debug mode not active');
        }

        $checks++;
        $logDir = Logger::getLogDir();
        if ($logDir !== '' && is_dir($logDir)) {
            $this->success('[PASS] Log directory: ' . $logDir);
            $passed++;
        } else {
            $this->error('[FAIL] Log directory missing');
            $issues[] = 'Log directory missing';
        }

        $checks++;
        $this->success('[PASS] Debug commands available: debug:last, debug:health');
        $passed++;

        $this->write(str_repeat('-', 40));
        if ($issues === []) {
            $this->success("[RESULT] {$passed}/{$checks} checks passed - Debug system healthy");
            return 0;
        }
        $this->warn("[RESULT] {$passed}/{$checks} checks passed");
        foreach ($issues as $i => $issue) {
            $this->error('  ' . ($i + 1) . '. ' . $issue);
        }
        return count($issues);
    }
}

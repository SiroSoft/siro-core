<?php

declare(strict_types=1);

namespace Siro\Core\Commands;

use Siro\Core\Env;
use Siro\Core\Logger;

final class DebugHealthCommand implements CommandInterface
{
    use CommandSupport;

    public function __construct(private readonly string $basePath)
    {
    }

    /** @param array<int, string> $args */
    public function run(array $args = []): int
    {
        $issues = [];
        $checks = 0;
        $passed = 0;

        $this->write('Siro Debug Health Check');
        $this->write(str_repeat('-', 40));

        $checks++;
        if (version_compare(PHP_VERSION, '8.2.0', '<')) {
            $this->error('PHP version: ' . PHP_VERSION);
            $issues[] = 'PHP version < 8.2 (min required)';
        } else {
            $this->success('PHP version: ' . PHP_VERSION);
            $passed++;
        }

        $checks++;
        $appDebug = Env::bool('APP_DEBUG', false);
        $appEnv = Env::get('APP_ENV', 'production');
        $debugEffective = $appDebug && $appEnv !== 'production';
        $this->info("APP_DEBUG=" . ($appDebug ? 'true' : 'false') . ", APP_ENV={$appEnv}");
        if ($debugEffective) {
            $this->success('Debug mode is active');
            $passed++;
        } else {
            $this->warn('Debug mode not active');
        }

        $checks++;
        $logDir = $this->basePath . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'logs';
        if (is_dir($logDir)) {
            $this->success('Log directory: ' . $logDir);
            $passed++;
        } else {
            $this->error('Log directory: ' . $logDir . ' (not found)');
            $issues[] = 'Log directory not created yet (will be auto-created on first request)';
        }

        $checks++;
        if (class_exists(DebugLastCommand::class) && class_exists(DebugHealthCommand::class)) {
            $this->success('Debug commands available: debug:last, debug:health');
            $passed++;
        } else {
            $this->error('Debug commands not available');
            $issues[] = 'Debug command handler class missing';
        }

        $this->write(str_repeat('-', 40));
        if ($issues === []) {
            $this->success("{$passed}/{$checks} checks passed - Debug system healthy");
            return 0;
        }
        $this->warn("{$passed}/{$checks} checks passed");
        foreach ($issues as $i => $issue) {
            $this->error('  ' . ($i + 1) . '. ' . $issue);
        }
        return 1;
    }
}

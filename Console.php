<?php

declare(strict_types=1);

namespace Siro\Core;

use Siro\Core\Commands\ConfigCacheCommand;
use Siro\Core\Commands\EnvCheckCommand;
use Siro\Core\Commands\LogExportCommand;
use Siro\Core\Commands\LogReplayCommand;
use Siro\Core\Commands\LogTraceCommand;
use Siro\Core\Commands\LogTopCommand;
use Siro\Core\Commands\DebugLastCommand;
use Siro\Core\Commands\RouteSearchCommand;
use Siro\Core\Commands\MakeMailCommand;
use Siro\Core\Commands\MakeEventCommand;
use Siro\Core\Commands\MakeOpenApiCommand;
use Siro\Core\Commands\MakePostmanCommand;
use Siro\Core\Commands\MakeAuthCommand;
use Siro\Core\Commands\MakeControllerCommand;
use Siro\Core\Commands\MakeModelCommand;
use Siro\Core\Commands\KeyGenerateCommand;
use Siro\Core\Commands\MakeJobCommand;
use Siro\Core\Commands\MakeLangCommand;
use Siro\Core\Commands\MakeMigrationCommand;
use Siro\Core\Commands\MakeQueueTableCommand;
use Siro\Core\Commands\MakeResourceCommand;
use Siro\Core\Commands\MakeSeederCommand;
use Siro\Core\Commands\MigrateCommand;
use Siro\Core\Commands\QueueFlushCommand;
use Siro\Core\Commands\QueueRetryCommand;
use Siro\Core\Commands\QueueStatusCommand;
use Siro\Core\Commands\MigrateRollbackCommand;
use Siro\Core\Commands\MigrateStatusCommand;
use Siro\Core\Commands\OptimizeCommand;
use Siro\Core\Commands\QueueWorkCommand;
use Siro\Core\Commands\RouteListCommand;
use Siro\Core\Commands\ScheduleRunCommand;
use Siro\Core\Commands\SeedCommand;
use Siro\Core\Commands\ServeCommand;
use Siro\Core\Commands\StorageLinkCommand;
use Siro\Core\Commands\DoctorCommand;
use Siro\Core\Commands\DownCommand;
use Siro\Core\Commands\FixCommand;
use Siro\Core\Commands\TraceListCommand;
use Siro\Core\Commands\UpCommand;
use Siro\Core\Commands\ApiTestCommand;
use Siro\Core\Commands\MakeCrudCommand;
use Siro\Core\Commands\MakeTestCommand;
use Siro\Core\Commands\RateStatusCommand;
use Siro\Core\Commands\TestRunCommand;
use Siro\Core\Commands\EnvSwitchCommand;
use Siro\Core\Commands\SlowLogCommand;
use Siro\Core\Commands\LogCleanupCommand;
use Siro\Core\Commands\LogTailCommand;
use Siro\Core\Commands\LogStatsCommand;
use Siro\Core\Commands\MakeFactoryCommand;
use Siro\Core\Commands\MakeServiceCommand;
use Siro\Core\Commands\MakeRepositoryCommand;
use Siro\Core\Commands\DbShowCommand;
use Siro\Core\Commands\RouteRulesCommand;
use Siro\Core\Commands\LiveCommand;
use Siro\Core\Commands\DeployCommand;
use Siro\Core\Commands\NewCommand;

final class Console
{
    private const VERSION = '0.14.1';

    public function __construct(private readonly string $basePath)
    {
    }

    /** @return array<string, array{handler: class-string, desc: string, usage: string}> */
    private function commandRegistry(): array
    {
        return [
            'make:auth'       => ['handler' => MakeAuthCommand::class, 'desc' => 'Generate auth system', 'usage' => 'php siro make:auth'],
            'make:controller' => ['handler' => MakeControllerCommand::class, 'desc' => 'Generate controller', 'usage' => 'php siro make:controller <name>'],
            'make:model'      => ['handler' => MakeModelCommand::class, 'desc' => 'Generate model', 'usage' => 'php siro make:model <name>'],
            'make:migration'  => ['handler' => MakeMigrationCommand::class, 'desc' => 'Generate migration', 'usage' => 'php siro make:migration <name>'],
            'make:queue-table'=> ['handler' => MakeQueueTableCommand::class, 'desc' => 'Generate queue tables migration', 'usage' => 'php siro make:queue-table'],
            'make:resource'   => ['handler' => MakeResourceCommand::class, 'desc' => 'Generate API resource transformer', 'usage' => 'php siro make:resource <name>'],
            'make:seeder'     => ['handler' => MakeSeederCommand::class, 'desc' => 'Generate seeder', 'usage' => 'php siro make:seeder <name>'],
            'make:crud'       => ['handler' => MakeCrudCommand::class, 'desc' => 'Full CRUD scaffolding (--simple, --seed, --force)', 'usage' => 'php siro make:crud <name> [--simple] [--seed] [--force]'],
            'make:test'       => ['handler' => MakeTestCommand::class, 'desc' => 'Generate test file', 'usage' => 'php siro make:test <name>'],
            'make:job'        => ['handler' => MakeJobCommand::class, 'desc' => 'Generate job class', 'usage' => 'php siro make:job <name>'],
            'make:mail'       => ['handler' => MakeMailCommand::class, 'desc' => 'Generate mail class', 'usage' => 'php siro make:mail <name>'],
            'make:event'      => ['handler' => MakeEventCommand::class, 'desc' => 'Generate event class', 'usage' => 'php siro make:event <name>'],
            'make:lang'       => ['handler' => MakeLangCommand::class, 'desc' => 'Generate language file', 'usage' => 'php siro make:lang <locale> <file>'],
            'make:factory'    => ['handler' => MakeFactoryCommand::class, 'desc' => 'Generate factory', 'usage' => 'php siro make:factory <name>'],
            'make:openapi'    => ['handler' => MakeOpenApiCommand::class, 'desc' => 'Generate OpenAPI spec', 'usage' => 'php siro make:openapi [--with-swagger] [--tag=TAG] [--flow=auth|crud]'],
            'make:postman'    => ['handler' => MakePostmanCommand::class, 'desc' => 'Generate Postman collection', 'usage' => 'php siro make:postman [--flow=crud]'],
            'make:service'    => ['handler' => MakeServiceCommand::class, 'desc' => 'Generate service class', 'usage' => 'php siro make:service <name>'],
            'make:repository' => ['handler' => MakeRepositoryCommand::class, 'desc' => 'Generate repository class', 'usage' => 'php siro make:repository <name>'],

            'migrate'          => ['handler' => MigrateCommand::class, 'desc' => 'Run migrations', 'usage' => 'php siro migrate'],
            'migrate:rollback'  => ['handler' => MigrateRollbackCommand::class, 'desc' => 'Rollback migrations', 'usage' => 'php siro migrate:rollback [--step=N]'],
            'migrate:status'    => ['handler' => MigrateStatusCommand::class, 'desc' => 'Migration status', 'usage' => 'php siro migrate:status'],
            'db:seed'           => ['handler' => SeedCommand::class, 'desc' => 'Run seeders', 'usage' => 'php siro db:seed'],
            'db:show'           => ['handler' => DbShowCommand::class, 'desc' => 'Show table data/schema', 'usage' => 'php siro db:show <table> [--schema]'],

            'log:replay'  => ['handler' => LogReplayCommand::class, 'desc' => 'Replay request (--set, --seed)', 'usage' => 'php siro log:replay <trace_id> [--force] [--set key=val]'],
            'log:trace'   => ['handler' => LogTraceCommand::class, 'desc' => 'View trace details (--full for more)', 'usage' => 'php siro log:trace [<id>] [--status=500] [--limit=N] [--full]'],
            'log:export'  => ['handler' => LogExportCommand::class, 'desc' => 'Export trace (JSON/CSV/Postman)', 'usage' => 'php siro log:export <trace_id> --postman'],
            'log:cleanup' => ['handler' => LogCleanupCommand::class, 'desc' => 'Clean old trace files', 'usage' => 'php siro log:cleanup [--days=N] [--dry-run]'],
            'log:slow'    => ['handler' => SlowLogCommand::class, 'desc' => 'Show slow requests', 'usage' => 'php siro log:slow [--limit=N] [--min=MS]'],
            'log:tail'    => ['handler' => LogTailCommand::class, 'desc' => 'Tail log files in real-time', 'usage' => 'php siro log:tail [--type=request|error|slow] [--lines=N] [--follow|-f]'],
            'log:stats'   => ['handler' => LogStatsCommand::class, 'desc' => 'Request statistics with charts', 'usage' => 'php siro log:stats [--days=N]'],
            'log:top'     => ['handler' => LogTopCommand::class, 'desc' => 'Top slowest APIs by total time', 'usage' => 'php siro log:top [--limit=N] [--min=MS]'],
            'debug:last'  => ['handler' => DebugLastCommand::class, 'desc' => 'Show last request details', 'usage' => 'php siro debug:last'],

            'test'          => ['handler' => TestRunCommand::class, 'desc' => 'Run PHPUnit test suite', 'usage' => 'php siro test'],
            'api:test'      => ['handler' => ApiTestCommand::class, 'desc' => 'Test API (--loop, --as=admin/guest)', 'usage' => 'php siro api:test <method> <path> [field:value...] [--as=admin|guest] [--loop=N]'],

            'queue:work'    => ['handler' => QueueWorkCommand::class, 'desc' => 'Process queue jobs', 'usage' => 'php siro queue:work [--daemon]'],
            'queue:retry'   => ['handler' => QueueRetryCommand::class, 'desc' => 'Retry failed jobs', 'usage' => 'php siro queue:retry <id|all>'],
            'queue:flush'   => ['handler' => QueueFlushCommand::class, 'desc' => 'Clear failed jobs', 'usage' => 'php siro queue:flush'],
            'queue:status'  => ['handler' => QueueStatusCommand::class, 'desc' => 'Queue statistics', 'usage' => 'php siro queue:status'],

            'schedule:run'  => ['handler' => ScheduleRunCommand::class, 'desc' => 'Run scheduled tasks', 'usage' => 'php siro schedule:run'],

            'serve'        => ['handler' => ServeCommand::class, 'desc' => 'Start dev server', 'usage' => 'php siro serve [--port=8080]'],
            'live'         => ['handler' => LiveCommand::class, 'desc' => 'Live reload dev server', 'usage' => 'php siro live [--port=9090]'],
            'deploy'       => ['handler' => DeployCommand::class, 'desc' => 'Deploy application', 'usage' => 'php siro deploy [--init]'],
            'storage:link' => ['handler' => StorageLinkCommand::class, 'desc' => 'Create storage symlink', 'usage' => 'php siro storage:link'],

            'key:generate'  => ['handler' => KeyGenerateCommand::class, 'desc' => 'Generate JWT secret', 'usage' => 'php siro key:generate'],
            'config:cache'  => ['handler' => ConfigCacheCommand::class, 'desc' => 'Cache config', 'usage' => 'php siro config:cache'],
            'optimize'      => ['handler' => OptimizeCommand::class, 'desc' => 'Optimize for production', 'usage' => 'php siro optimize'],
            'env:check'     => ['handler' => EnvCheckCommand::class, 'desc' => 'Check environment', 'usage' => 'php siro env:check'],
            'env:switch'    => ['handler' => EnvSwitchCommand::class, 'desc' => 'Switch environment', 'usage' => 'php siro env:switch <env>'],
            'doctor'        => ['handler' => DoctorCommand::class, 'desc' => 'System health check (--prod)', 'usage' => 'php siro doctor [--prod]'],
            'fix'           => ['handler' => FixCommand::class, 'desc' => 'Watch code changes & auto-replay last test', 'usage' => 'php siro fix'],
            'down'          => ['handler' => DownCommand::class, 'desc' => 'Enable maintenance mode', 'usage' => 'php siro down [--message="..."] [--retry=60] [--allow=ip1,ip2]'],
            'up'            => ['handler' => UpCommand::class, 'desc' => 'Disable maintenance mode', 'usage' => 'php siro up'],
            'route:list'    => ['handler' => RouteListCommand::class, 'desc' => 'List all routes', 'usage' => 'php siro route:list'],
            'route:search'  => ['handler' => RouteSearchCommand::class, 'desc' => 'Search routes by keyword', 'usage' => 'php siro route:search <keyword>'],
            'route:rules'   => ['handler' => RouteRulesCommand::class, 'desc' => 'Show validation rules', 'usage' => 'php siro route:rules'],
            'trace:list'    => ['handler' => TraceListCommand::class, 'desc' => 'List recent traces (--limit=N)', 'usage' => 'php siro trace:list [--limit=20]'],
            'rate:status'   => ['handler' => RateStatusCommand::class, 'desc' => 'Rate limit dashboard', 'usage' => 'php siro rate:status'],
            'new'           => ['handler' => NewCommand::class, 'desc' => 'Create new project from skeleton', 'usage' => 'php siro new <name>'],
        ];
    }

    private function aliases(): array
    {
        return [
            'slow' => 'log:slow',
        ];
    }

    public function run(array $argv): int
    {
        $command = trim($argv[1] ?? '');
        $args = array_slice($argv, 2);

        if ($command === '--version' || $command === '-V') {
            $this->write('SiroPHP v' . self::VERSION);
            return 0;
        }

        if ($command === '' || in_array($command, ['-h', '--help', 'help'], true)) {
            $this->printHelp();
            return 0;
        }

        if ($command === 'list') {
            $this->printList();
            return 0;
        }

        $aliases = $this->aliases();
        if (isset($aliases[$command])) {
            $command = $aliases[$command];
        }

        if ($command === 'make:docs') {
            $command = 'make:openapi';
            $args[] = '--with-swagger';
        }

        // open:postman <trace_id> → log:export <trace_id> --postman
        if ($command === 'open:postman') {
            $command = 'log:export';
            $args[] = '--postman';
        }

        $registry = $this->commandRegistry();

        if (!isset($registry[$command])) {
            return $this->unknownCommand($command, $registry);
        }

        if (in_array('--help', $args, true) || in_array('-h', $args, true)) {
            $this->printCommandHelp($command, $registry[$command]);
            return 0;
        }

        /** @var string $handlerClass */
        $handlerClass = $registry[$command]['handler'];
        return (new $handlerClass($this->basePath))->run($args);
    }

    private function printHelp(): void
    {
        $this->write('SiroPHP v' . self::VERSION . ' - PHP Micro-Framework for API Development');
        $this->write('');
        $this->write('Usage:');
        $this->write('  php siro <command> [options]');
        $this->write('');
        $this->write('  php siro list                Show all available commands');
        $this->write('  php siro <command> --help    Show help for a specific command');
        $this->write('  php siro --version           Show version');
        $this->write('');

        $groups = $this->groupedCommands();
        foreach ($groups as $group => $commands) {
            $this->write('  ' . $group . ':');
            foreach ($commands as $cmd => $desc) {
                $this->write('    ' . str_pad($cmd, 22, ' ') . $desc);
            }
            $this->write('');
        }
    }

    private function printList(): void
    {
        $this->write('SiroPHP v' . self::VERSION . ' - Available Commands');
        $this->write(str_repeat('=', 60));
        $this->write('');

        $registry = $this->commandRegistry();
        $groups = $this->groupedCommands();
        foreach ($groups as $group => $commands) {
            $this->write('  ' . $group . ':');
            $this->write('');
            foreach ($commands as $cmd => $desc) {
                $usage = $registry[$cmd]['usage'] ?? 'php siro ' . $cmd;
                $line = '    ' . str_pad($cmd, 22, ' ') . $usage;
                $aliases = '';
                $aliasMap = $this->aliases();
                foreach ($aliasMap as $alias => $target) {
                    if ($target === $cmd) {
                        $aliases = ' (alias: ' . $alias . ')';
                    }
                }
                $this->write($line . $aliases);
            }
            $this->write('');
        }

        $this->write('Run "php siro <command> --help" for detailed usage.');
    }

    private function printCommandHelp(string $command, array $info): void
    {
        $this->write('SiroPHP v' . self::VERSION);
        $this->write('');
        $this->write('  ' . $command . ' - ' . $info['desc']);
        $this->write('');
        $this->write('  Usage:');
        $this->write('    ' . $info['usage']);
        $this->write('');
        $this->write('  Run "php siro list" to see all commands.');
    }

    private function groupedCommands(): array
    {
        $registry = $this->commandRegistry();
        $groups = [
            'Make / Generate'    => ['make:auth', 'make:controller', 'make:model', 'make:migration',
                'make:queue-table', 'make:resource', 'make:seeder', 'make:crud', 'make:test',
                'make:job', 'make:mail', 'make:event', 'make:lang', 'make:factory',
                'make:service', 'make:repository',
                'make:openapi', 'make:postman'],
            'New Project'        => ['new'],
            'Database'           => ['migrate', 'migrate:rollback', 'migrate:status', 'db:seed', 'db:show'],
            'Logs'               => ['log:trace', 'log:replay', 'log:export', 'log:cleanup', 'log:slow', 'log:tail', 'log:stats', 'log:top', 'debug:last'],
            'Test'               => ['test', 'api:test'],
            'Queue & Schedule'   => ['queue:work', 'queue:retry', 'queue:flush', 'queue:status', 'schedule:run'],
            'Server & Deploy'    => ['serve', 'live', 'deploy', 'storage:link'],
            'System & Config'    => ['key:generate', 'config:cache', 'optimize', 'env:check',
                'env:switch', 'doctor', 'fix', 'down', 'up', 'trace:list', 'route:list', 'route:search', 'route:rules', 'rate:status'],
        ];

        $result = [];
        foreach ($groups as $group => $cmds) {
            $entries = [];
            foreach ($cmds as $cmd) {
                if (isset($registry[$cmd])) {
                    $entries[$cmd] = $registry[$cmd]['desc'];
                }
            }
            if ($entries !== []) {
                $result[$group] = $entries;
            }
        }
        return $result;
    }

    private function unknownCommand(string $command, array $registry): int
    {
        $this->write("Unknown command: \033[31m{$command}\033[0m");
        $this->write('');

        $suggestions = [];
        foreach ($registry as $cmd => $info) {
            $lev = levenshtein($command, $cmd);
            if ($lev > 0 && $lev <= 3) {
                $suggestions[] = $cmd;
            }
            if (str_starts_with($cmd, $command) || str_starts_with($command, $cmd)) {
                $suggestions[] = $cmd;
            }
        }
        $suggestions = array_unique($suggestions);
        $suggestions = array_slice($suggestions, 0, 5);

        if ($suggestions !== []) {
            $this->write('Did you mean?');
            foreach ($suggestions as $s) {
                $this->write("  \033[33m{$s}\033[0m");
            }
            $this->write('');
        }

        $this->write('Run "php siro list" to see all commands.');
        return 1;
    }

    private function write(string $line): void
    {
        echo $line . PHP_EOL;
    }
}

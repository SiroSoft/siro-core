<?php

declare(strict_types=1);

namespace Siro\Core;

use Siro\Core\Commands\ConfigCacheCommand;
use Siro\Core\Commands\ConfigClearCommand;
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
use Siro\Core\Commands\EnvCacheCommand;
use Siro\Core\Commands\FixCommand;
use Siro\Core\Commands\ReplayCommand;
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
use Siro\Core\Commands\DebugHealthCommand;
use Siro\Core\Commands\MakeServiceCommand;
use Siro\Core\Commands\MakeRepositoryCommand;
use Siro\Core\Commands\DbShowCommand;
use Siro\Core\Commands\RouteRulesCommand;
use Siro\Core\Commands\LiveCommand;
use Siro\Core\Commands\DeployCommand;
use Siro\Core\Commands\NewCommand;
use Siro\Core\Commands\NewProjectCommand;
use Siro\Core\Commands\MakeIdempotencyTableCommand;
use Siro\Core\Commands\MakeApiKeysTableCommand;
use Siro\Core\Commands\MakeApiKeyCommand;
use Siro\Core\Commands\MakeMiddlewareCommand;
use Siro\Core\Commands\MakeListenerCommand;
use Siro\Core\Commands\TestCommand;
use Siro\Core\Commands\BenchmarkCommand;
use Siro\Core\Commands\FrankenphpServeCommand;
use Siro\Core\Commands\MigrateFreshCommand;
use Siro\Core\Commands\TinkerCommand;

final class Console
{
    public const VERSION = '0.28.1';

    /** @var array<string, array{handler: class-string, desc: string, usage: string}> */
    private static array $appCommands = [];

    /**
     * Register a command from app code.
     *
     * @param class-string $handlerClass
     */
    public static function registerCommand(string $name, string $handlerClass, string $description = ''): void
    {
        self::$appCommands[$name] = [
            'handler' => $handlerClass,
            'desc' => $description,
            'usage' => 'php siro ' . $name,
        ];
    }

    /**
     * Bulk register commands from app code.
     *
     * @param array<string, array{handler: class-string, desc?: string, usage?: string}> $commands
     */
    public static function registerCommands(array $commands): void
    {
        foreach ($commands as $name => $config) {
            $handler = $config['handler'];
            $desc = $config['desc'] ?? '';
            self::registerCommand($name, $handler, $desc);
        }
    }

    public static function getVersion(): string
    {
        return self::VERSION;
    }

    public function __construct(private readonly string $basePath)
    {
        $envFile = $this->basePath . DIRECTORY_SEPARATOR . '.env';
        if (file_exists($envFile)) {
            Env::load($envFile);
        }
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
            'make:middleware'  => ['handler' => MakeMiddlewareCommand::class, 'desc' => 'Generate middleware class', 'usage' => 'php siro make:middleware <name>'],
            'make:listener'    => ['handler' => MakeListenerCommand::class, 'desc' => 'Generate event listener', 'usage' => 'php siro make:listener <name>'],
            'make:idempotency-table' => ['handler' => MakeIdempotencyTableCommand::class, 'desc' => 'Create idempotency table', 'usage' => 'php siro make:idempotency-table'],
            'make:apikey-table' => ['handler' => MakeApiKeysTableCommand::class, 'desc' => 'Create API keys table', 'usage' => 'php siro make:apikey-table'],
            'make:apikey' => ['handler' => MakeApiKeyCommand::class, 'desc' => 'Generate API key', 'usage' => 'php siro make:apikey <name> [scopes] [expires_days]'],
            'benchmark' => ['handler' => BenchmarkCommand::class, 'desc' => 'Performance benchmark', 'usage' => 'php siro benchmark [--iterations=N] [--json]'],

            'migrate'          => ['handler' => MigrateCommand::class, 'desc' => 'Run migrations', 'usage' => 'php siro migrate'],
            'migrate:fresh'    => ['handler' => MigrateFreshCommand::class, 'desc' => 'Drop all tables and re-run all migrations', 'usage' => 'php siro migrate:fresh [--seed]'],
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
            'debug:last'    => ['handler' => DebugLastCommand::class, 'desc' => 'Show why last request failed (alias: why)', 'usage' => 'php siro debug:last'],
            'debug:health'  => ['handler' => DebugHealthCommand::class, 'desc' => 'Check debug system health and configuration', 'usage' => 'php siro debug:health'],

            'test:run'      => ['handler' => TestRunCommand::class, 'desc' => 'Run PHPUnit test suite (legacy)', 'usage' => 'php siro test:run'],
            'api:test'      => ['handler' => ApiTestCommand::class, 'desc' => 'Test API (--loop, --as=admin/guest)', 'usage' => 'php siro api:test <method> <path> [field:value...] [--as=admin|guest] [--loop=N]'],

            'queue:work'    => ['handler' => QueueWorkCommand::class, 'desc' => 'Process queue jobs', 'usage' => 'php siro queue:work [--daemon] [--workers=N]'],
            'queue:retry'   => ['handler' => QueueRetryCommand::class, 'desc' => 'Retry failed jobs', 'usage' => 'php siro queue:retry <id|all>'],
            'queue:flush'   => ['handler' => QueueFlushCommand::class, 'desc' => 'Clear failed jobs', 'usage' => 'php siro queue:flush'],
            'queue:status'  => ['handler' => QueueStatusCommand::class, 'desc' => 'Queue statistics', 'usage' => 'php siro queue:status'],

            'schedule:run'  => ['handler' => ScheduleRunCommand::class, 'desc' => 'Run scheduled tasks', 'usage' => 'php siro schedule:run'],

            'serve'             => ['handler' => ServeCommand::class, 'desc' => 'Start dev server (php -S)', 'usage' => 'php siro serve [--port=8080]'],
            'frankenphp:serve'  => ['handler' => FrankenphpServeCommand::class, 'desc' => 'Start FrankenPHP production server (--docker)', 'usage' => 'php siro frankenphp:serve [--docker] [--port=80]'],
            'live'         => ['handler' => LiveCommand::class, 'desc' => 'Live reload dev server', 'usage' => 'php siro live [--port=9090]'],
            'deploy'       => ['handler' => DeployCommand::class, 'desc' => 'Deploy application', 'usage' => 'php siro deploy [--init]'],
            'storage:link' => ['handler' => StorageLinkCommand::class, 'desc' => 'Create storage symlink', 'usage' => 'php siro storage:link'],

            'key:generate'  => ['handler' => KeyGenerateCommand::class, 'desc' => 'Generate JWT secret', 'usage' => 'php siro key:generate'],
            'config:cache'  => ['handler' => ConfigCacheCommand::class, 'desc' => 'Cache config', 'usage' => 'php siro config:cache'],
            'config:clear'  => ['handler' => ConfigClearCommand::class, 'desc' => 'Clear cached config and routes', 'usage' => 'php siro config:clear'],
            'env:cache'     => ['handler' => EnvCacheCommand::class, 'desc' => 'Cache env vars', 'usage' => 'php siro env:cache'],
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
            'replay'        => ['handler' => ReplayCommand::class, 'desc' => 'Replay last trace (or by id)', 'usage' => 'php siro replay [trace_id] [--edit] [--diff]'],
            'tinker'        => ['handler' => TinkerCommand::class, 'desc' => 'Interactive PHP playground in app context', 'usage' => 'php siro tinker'],
            'test'          => ['handler' => TestCommand::class, 'desc' => 'Run tests (--filter=, --suite=, --coverage)', 'usage' => 'php siro test [--filter=name] [--suite=Unit] [--coverage]'],
            'new'           => ['handler' => NewCommand::class, 'desc' => 'Create new project from skeleton', 'usage' => 'php siro new <name>'],
            'new:project'   => ['handler' => NewProjectCommand::class, 'desc' => 'Create project via composer', 'usage' => 'php siro new:project <name>'],
        ];
    }

    /** @return array<string, string> */
    private function aliases(): array
    {
        return [
            'slow'   => 'log:slow',
            'why'    => 'debug:last',
            'traces' => 'trace:list',
        ];
    }

    /** @param array<int, string> $argv */
    public function run(array $argv): int
    {
        $command = isset($argv[1]) ? trim($argv[1]) : '';
        $args = array_slice($argv, 2);

        if (extension_loaded('pcntl')) {
            $shutdown = function (int $signal): void {
                $name = match ($signal) {
                    SIGTERM => 'SIGTERM',
                    SIGINT => 'SIGINT',
                    default => "signal($signal)",
                };
                fwrite(STDERR, "\nReceived $name. Shutting down gracefully...\n");
                App::shutdown();
                exit(0);
            };
            pcntl_signal(SIGTERM, $shutdown);
            pcntl_signal(SIGINT, $shutdown);
        }

        if ($command === '--version' || $command === '-V') {
            $this->write('SiroPHP v' . self::VERSION);
            return 0;
        }

        // php siro → show core workflow
        if ($command === '') {
            $this->printWorkflow();
            return 0;
        }

        // Alias: 't' → 'api:test', etc.
        $shortcuts = ['t' => 'api:test', 'tink' => 'tinker'];
        if (isset($shortcuts[$command])) {
            $command = $shortcuts[$command];
        }

        if (in_array($command, ['-h', '--help', 'help'], true)) {
            $this->printHelp();
            return 0;
        }

        if ($command === 'list') {
            if (in_array('--raw', $args, true)) {
                $this->printRawList();
                return 0;
            }
            $this->printList();
            return 0;
        }

        // Alias resolution
        $aliases = $this->aliases();
        if (isset($aliases[$command])) {
            $command = $aliases[$command];
        }

        if ($command === 'make:docs') {
            $command = 'make:openapi';
            $args[] = '--with-swagger';
        }

        if ($command === 'open:postman') {
            $command = 'log:export';
            $args[] = '--postman';
        }

        $registry = array_merge($this->commandRegistry(), self::$appCommands);

        if ($command === 'start') {
            return $this->printStart();
        }

        if (!isset($registry[$command])) {
            return $this->unknownCommand($command, $registry);
        }

        if (in_array('--help', $args, true) || in_array('-h', $args, true)) {
            $this->printCommandHelp($command, $registry[$command]);
            return 0;
        }

        /** @var class-string $handlerClass */
        $handlerClass = $registry[$command]['handler'];
        $handlerInstance = new $handlerClass($this->basePath);
        if (!$handlerInstance instanceof \Siro\Core\Commands\CommandInterface) {
            throw new \RuntimeException("Command {$command} must implement CommandInterface");
        }
        return $handlerInstance->run($args);
    }

    private function printWorkflow(): void
    {
        $this->write('');
        $this->write('  ⚡ SiroPHP v' . self::VERSION);
        $this->write('  ' . str_repeat('-', 50));
        $this->write('');
        $this->write('  Core Workflow:');
        $this->write('    make:crud     Create a full CRUD API in 2 seconds');
        $this->write('    serve         Start development server');
        $this->write('    api:test      Test any API endpoint from CLI');
        $this->write('    why           Why did the last request fail?');
        $this->write('    fix           Watch code changes & auto-replay');
        $this->write('    tinker        Interactive PHP playground');
        $this->write('    replay        Replay any past request');
        $this->write('    traces        Browse recent request traces');
        $this->write('');
        $this->write('  Quick start:');
        $this->write('    php siro start');
        $this->write('');
        $this->write('  All commands:');
        $this->write('    php siro list');
        $this->write('    php siro <command> --help');
        $this->write('  ' . str_repeat('-', 50));
        $this->write('');
    }

    private function printStart(): int
    {
        $this->write('');
        $this->write('  🚀 START — SiroPHP Quick Onboarding');
        $this->write('  ' . str_repeat('=', 58));
        $this->write('');
        $this->write('  1. Generate your first CRUD:');
        $this->write('     $ php siro make:crud products');
        $this->write('     $ php siro make:crud orders --with-rbac  (RBAC version)');
        $this->write('');
        $this->write('  2. Run migration:');
        $this->write('     $ php siro migrate');
        $this->write('');
        $this->write('  3. Start dev server:');
        $this->write('     $ php siro serve');
        $this->write('');
        $this->write('  4. Test your API:');
        $this->write('     $ php siro t GET /api/products');
        $this->write('     $ php siro t POST /api/products --body name=Laptop');
        $this->write('');
        $this->write('  5. Debug when it fails:');
        $this->write('     $ php siro why');
        $this->write('     $ php siro log:trace <trace_id>');
        $this->write('     $ php siro replay <trace_id>');
        $this->write('');
        $this->write('  6. Fix & auto-replay:');
        $this->write('     $ php siro fix');
        $this->write('');
        $this->write('  7. Browse traces:');
        $this->write('     $ php siro traces');
        $this->write('');
        $this->write('  8. Generate API docs:');
        $this->write('     $ php siro make:openapi --with-swagger');
        $this->write('');
        $this->write('  Learn more: cat ONBOARDING.md');
        $this->write('  ' . str_repeat('=', 58));
        return 0;
    }

    private function printHelp(): void
    {
        $this->write('');
  $this->write('  ⚡ SiroPHP v' . self::VERSION . ' — PHP Micro-Framework');
    $this->write('  --------------------------------------------------');
    $this->write('');
    $this->write('  Usage:');
    $this->write('    php siro <command> [options]');
    $this->write('    php siro list                  All 72 commands');
        $this->write('    php siro <command> --help      Command details');
        $this->write('    php siro --version             Version info');
        $this->write('');

        $layers = [
            '🎯 Core Workflow' => ['make:crud', 'serve', 'api:test', 'why', 'fix', 'replay', 'trace:list'],
            '🔧 Daily Dev'     => ['make:controller', 'make:model', 'make:migration', 'make:test', 'make:seeder',
                                    'make:service', 'make:repository', 'make:auth', 'migrate', 'db:seed', 'test', 'route:list'],
            '📦 Advanced'      => ['make:job', 'make:mail', 'make:event', 'make:listener', 'make:lang', 'make:factory', 'make:openapi', 'make:postman',
                                    'queue:work', 'queue:status', 'schedule:run', 'deploy', 'optimize', 'config:cache',
                                    'down', 'up', 'log:trace', 'log:replay', 'log:slow', 'debug:last'],
            '⚙️ System'        => ['key:generate', 'doctor', 'env:check', 'env:switch', 'route:search', 'route:rules',
                                    'rate:status', 'db:show', 'migrate:rollback', 'migrate:status', 'log:export',
                                    'log:cleanup', 'log:tail', 'log:stats', 'log:top', 'storage:link', 'live', 'new',
                                    'benchmark'],
        ];

        foreach ($layers as $layer => $cmds) {
            $this->write('  ' . $layer . ':');
            $this->write('    ' . implode(', ', $cmds));
            $this->write('');
        }
    }

    private function printList(): void
    {
        $this->write('');
        $this->write('  ⚡ SiroPHP v' . self::VERSION . ' — 72 Commands');
        $this->write('  ' . str_repeat('=', 60));
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

        $this->write('Run "php siro <command> --help" for command details.');
    }

    private function printRawList(): void
    {
        $registry = array_merge($this->commandRegistry(), self::$appCommands);
        foreach ($registry as $cmd => $info) {
            $this->write($cmd);
        }
    }

    /** @param array{handler: class-string, desc: string, usage: string} $info */
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

    /** @return array<string, array<string, string>> */
    private function groupedCommands(): array
    {
        $registry = $this->commandRegistry();

        $groups = [
            'Make / Generate'    => 'make:',
            'New Project'        => ['new'],
            'Database'           => ['migrate', 'migrate:', 'db:'],
            'Logs'               => ['log:', 'debug:'],
            'Test'               => ['test', 'test:', 'api:test'],
            'Queue & Schedule'   => ['queue:', 'schedule:'],
            'Server & Deploy'    => ['serve', 'live', 'deploy', 'storage:link', 'frankenphp:'],
        ];

        $fallbackGroup = 'System & Config';

        $result = [];
        $mapped = [];

        foreach ($groups as $group => $prefixes) {
            $prefixes = (array) $prefixes;
            $entries = [];
            foreach ($registry as $cmd => $info) {
                foreach ($prefixes as $prefix) {
                    if (str_starts_with($cmd, $prefix)) {
                        $entries[$cmd] = $info['desc'];
                        $mapped[$cmd] = true;
                        break;
                    }
                }
            }
            if ($entries !== []) {
                $result[$group] = $entries;
            }
        }

        // Unmatched commands go to System & Config
        $remaining = [];
        foreach ($registry as $cmd => $info) {
            if (!isset($mapped[$cmd])) {
                $remaining[$cmd] = $info['desc'];
            }
        }
        if ($remaining !== []) {
            $result[$fallbackGroup] = $remaining;
        }

        return $result;
    }

    /** @param array<string, array{handler: class-string, desc: string, usage: string}> $registry */
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

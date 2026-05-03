<?php

declare(strict_types=1);

namespace Siro\Core;

use Siro\Core\Commands\ConfigCacheCommand;
use Siro\Core\Commands\EnvCheckCommand;
use Siro\Core\Commands\LogExportCommand;
use Siro\Core\Commands\LogReplayCommand;
use Siro\Core\Commands\LogTraceCommand;
use Siro\Core\Commands\MakeApiCommand;
use Siro\Core\Commands\MakeMailCommand;
use Siro\Core\Commands\MakeDocsCommand;
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
use Siro\Core\Commands\ApiTestCommand;
use Siro\Core\Commands\MakeCrudCommand;
use Siro\Core\Commands\MakeTestCommand;
use Siro\Core\Commands\RateStatusCommand;
use Siro\Core\Commands\TestRunCommand;
use Siro\Core\Commands\EnvSwitchCommand;
use Siro\Core\Commands\SlowLogCommand;
use Siro\Core\Commands\LogCleanupCommand;
use Siro\Core\Commands\MakeFactoryCommand;
use Siro\Core\Commands\DbShowCommand;
use Siro\Core\Commands\RouteRulesCommand;
use Siro\Core\Commands\LiveCommand;
use Siro\Core\Commands\DeployCommand;

/**
 * CLI command dispatcher.
 *
 * Parses the command name from argv and delegates to the
 * appropriate *Command class. Provides built-in help text.
 *
 * @package Siro\Core
 */
final class Console
{
    public function __construct(private readonly string $basePath)
    {
    }

    /** @param array<int, string> $argv */
    public function run(array $argv): int
    {
        $command = trim($argv[1] ?? '');
        $args = array_slice($argv, 2);

        if ($command === '' || in_array($command, ['-h', '--help', 'help'], true)) {
            $this->printHelp();
            return 0;
        }

        switch ($command) {
            case 'make:auth':
                return (new MakeAuthCommand($this->basePath))->run($args);
            case 'make:api':
                return (new MakeApiCommand($this->basePath))->run($args);
            case 'make:controller':
                return (new MakeControllerCommand($this->basePath))->run($args);
            case 'make:model':
                return (new MakeModelCommand($this->basePath))->run($args);
            case 'make:migration':
                return (new MakeMigrationCommand($this->basePath))->run($args);
            case 'make:queue-table':
                return (new MakeQueueTableCommand($this->basePath))->run($args);
            case 'make:resource':
                return (new MakeResourceCommand($this->basePath))->run($args);
            case 'make:seeder':
                return (new MakeSeederCommand($this->basePath))->run($args);
            case 'db:seed':
                return (new SeedCommand($this->basePath))->run($args);
            case 'schedule:run':
                return (new ScheduleRunCommand($this->basePath))->run($args);
            case 'queue:work':
                return (new QueueWorkCommand($this->basePath))->run($args);
            case 'queue:retry':
                return (new QueueRetryCommand($this->basePath))->run($args);
            case 'queue:flush':
                return (new QueueFlushCommand($this->basePath))->run($args);
            case 'queue:status':
                return (new QueueStatusCommand($this->basePath))->run($args);
            case 'make:job':
                return (new MakeJobCommand($this->basePath))->run($args);
            case 'make:lang':
                return (new MakeLangCommand($this->basePath))->run($args);
            case 'migrate':
                return (new MigrateCommand($this->basePath))->run($args);
            case 'migrate:rollback':
                return (new MigrateRollbackCommand($this->basePath))->run($args);
            case 'migrate:status':
                return (new MigrateStatusCommand($this->basePath))->run($args);
            case 'serve':
                return (new ServeCommand($this->basePath))->run($args);
            case 'storage:link':
                return (new StorageLinkCommand($this->basePath))->run($args);
            case 'key:generate':
                return (new KeyGenerateCommand($this->basePath))->run($args);
            case 'log:trace':
                return (new LogTraceCommand($this->basePath))->run($args);
            case 'log:replay':
                return (new LogReplayCommand($this->basePath))->run($args);
            case 'log:export':
                return (new LogExportCommand($this->basePath))->run($args);
            case 'make:openapi':
                return (new MakeOpenApiCommand($this->basePath))->run($args);
            case 'make:postman':
                return (new MakePostmanCommand($this->basePath))->run($args);
            case 'make:mail':
                return (new MakeMailCommand($this->basePath))->run($args);
            case 'make:docs':
                return (new MakeDocsCommand($this->basePath))->run($args);
            case 'make:event':
                return (new MakeEventCommand($this->basePath))->run($args);
            case 'config:cache':
                return (new ConfigCacheCommand($this->basePath))->run($args);
            case 'env:check':
                return (new EnvCheckCommand($this->basePath))->run($args);
            case 'optimize':
                return (new OptimizeCommand($this->basePath))->run($args);
            case 'doctor':
                return (new DoctorCommand($this->basePath))->run($args);
            case 'route:list':
                return (new RouteListCommand($this->basePath))->run($args);
            case 'make:crud':
                return (new MakeCrudCommand($this->basePath))->run($args);
            case 'make:test':
                return (new MakeTestCommand($this->basePath))->run($args);
            case 'api:test':
                return (new ApiTestCommand($this->basePath))->run($args);
            case 'rate:status':
                return (new RateStatusCommand($this->basePath))->run($args);
            case 'test':
                return (new TestRunCommand($this->basePath))->run($args);
            case 'env:switch':
                return (new EnvSwitchCommand($this->basePath))->run($args);
            case 'slow':
                return (new SlowLogCommand($this->basePath))->run($args);
            case 'log:cleanup':
                return (new LogCleanupCommand($this->basePath))->run($args);
            case 'make:factory':
                return (new MakeFactoryCommand($this->basePath))->run($args);
            case 'db:show':
                return (new DbShowCommand($this->basePath))->run($args);
            case 'route:rules':
                return (new RouteRulesCommand($this->basePath))->run($args);
            case 'live':
                return (new LiveCommand($this->basePath))->run($args);
            case 'deploy':
                return (new DeployCommand($this->basePath))->run($args);
            default:
                return $this->unknownCommand($command);
        }
    }

    private function printHelp(): void
    {
        $this->write('Siro Console');
        $this->write('Usage:');
        $this->write('  php siro make:auth');
        $this->write('  php siro make:api users');
        $this->write('  php siro make:controller UserController');
        $this->write('  php siro make:model User');
        $this->write('  php siro make:migration create_users_table');
        $this->write('  php siro make:queue-table           # Generate queue tables migration');
        $this->write('  php siro make:resource UserResource');
        $this->write('  php siro make:seeder UserSeeder');
        $this->write('  php siro migrate');
        $this->write('  php siro migrate:rollback');
        $this->write('  php siro db:seed');
        $this->write('  php siro migrate:rollback --step=1');
        $this->write('  php siro migrate:status');
        $this->write('  php siro serve');
        $this->write('  php siro log:trace <trace_id>');
        $this->write('  php siro log:trace --status=500');
        $this->write('  php siro log:replay <trace_id>');
        $this->write('  php siro log:export --format=json --output=traces.json');
        $this->write('  php siro make:openapi');
        $this->write('  php siro make:openapi --flow=auth --tag=User --path=/api');
        $this->write('  php siro make:postman');
        $this->write('  php siro make:postman --flow=crud --method=POST');
        $this->write('  php siro make:mail WelcomeMail');
        $this->write('  php siro make:event UserCreated');
        $this->write('  php siro make:docs');
        $this->write('  php siro schedule:run');
        $this->write('  php siro queue:work');
        $this->write('  php siro queue:work --daemon');
        $this->write('  php siro queue:retry all');
        $this->write('  php siro queue:retry 5');
        $this->write('  php siro queue:flush');
        $this->write('  php siro queue:status');
        $this->write('  php siro make:job SendWelcomeEmail');
        $this->write('  php siro make:lang vi messages');
        $this->write('  php siro make:lang en validation');
        $this->write('  php siro storage:link');
        $this->write('  php siro config:cache');
        $this->write('  php siro env:check');
        $this->write('  php siro optimize');
        $this->write('  php siro route:list');
        $this->write('  php siro key:generate');
        $this->write('  php siro api:test');
        $this->write('  php siro api:test POST /auth/login email=admin@test.com password=123456');
        $this->write('  php siro api:test GET /users --as=admin');
        $this->write('  php siro api:test POST /users name=John email=john@test.com --as=admin');
        $this->write('  php siro api:test --history');
        $this->write('  php siro api:test GET /users --watch');
        $this->write('  php siro api:test POST /users name=John --collection-save=myapi');
        $this->write('  php siro api:test --collection=myapi');
        $this->write('  php siro api:test --collection-list');
        $this->write('  php siro log:export <trace_id> --postman');
        $this->write('  php siro log:cleanup --days=7 --dry-run');
        $this->write('  php siro log:replay <trace_id> --force');
        $this->write('  php siro rate:status');
        $this->write('  php siro test');
        $this->write('  php siro env:switch staging');
        $this->write('  php siro slow --limit=20 --min=200');
        $this->write('  php siro api:test POST /webhook --webhook --port=9000');
        $this->write('  php siro api:test GET /api/users --cors');
        $this->write('  php siro make:crud users');
        $this->write('  php siro make:crud posts');
        $this->write('  php siro make:test UserApi');
        $this->write('  php siro make:factory User');
        $this->write('  php siro db:show users');
        $this->write('  php siro db:show users --schema');
        $this->write('  php siro route:rules');
        $this->write('  php siro live');
        $this->write('  php siro live --port=9090');
        $this->write('  php siro deploy');
        $this->write('  php siro deploy --init');
        $this->write('  php siro doctor');
    }

    private function unknownCommand(string $command): int
    {
        $this->write('Unknown command: ' . $command);
        $this->printHelp();
        return 1;
    }

    private function write(string $line): void
    {
        echo $line . PHP_EOL;
    }
}

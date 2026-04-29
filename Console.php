<?php

declare(strict_types=1);

namespace Siro\Core;

use Siro\Core\Commands\ConfigCacheCommand;
use Siro\Core\Commands\EnvCheckCommand;
use Siro\Core\Commands\LogExportCommand;
use Siro\Core\Commands\LogReplayCommand;
use Siro\Core\Commands\LogTraceCommand;
use Siro\Core\Commands\MakeApiCommand;
use Siro\Core\Commands\MakeAuthCommand;
use Siro\Core\Commands\MakeControllerCommand;
use Siro\Core\Commands\MakeModelCommand;
use Siro\Core\Commands\KeyGenerateCommand;
use Siro\Core\Commands\MakeMigrationCommand;
use Siro\Core\Commands\MakeResourceCommand;
use Siro\Core\Commands\MakeSeederCommand;
use Siro\Core\Commands\MigrateCommand;
use Siro\Core\Commands\MigrateRollbackCommand;
use Siro\Core\Commands\MigrateStatusCommand;
use Siro\Core\Commands\OptimizeCommand;
use Siro\Core\Commands\RouteListCommand;
use Siro\Core\Commands\SeedCommand;
use Siro\Core\Commands\ServeCommand;
use Siro\Core\Commands\DoctorCommand;

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
            case 'make:resource':
                return (new MakeResourceCommand($this->basePath))->run($args);
            case 'make:seeder':
                return (new MakeSeederCommand($this->basePath))->run($args);
            case 'db:seed':
                return (new SeedCommand($this->basePath))->run($args);
            case 'migrate':
                return (new MigrateCommand($this->basePath))->run($args);
            case 'migrate:rollback':
                return (new MigrateRollbackCommand($this->basePath))->run($args);
            case 'migrate:status':
                return (new MigrateStatusCommand($this->basePath))->run($args);
            case 'serve':
                return (new ServeCommand($this->basePath))->run($args);
            case 'key:generate':
                return (new KeyGenerateCommand($this->basePath))->run($args);
            case 'log:trace':
                return (new LogTraceCommand($this->basePath))->run($args);
            case 'log:replay':
                return (new LogReplayCommand($this->basePath))->run($args);
            case 'log:export':
                return (new LogExportCommand($this->basePath))->run($args);
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
        $this->write('  php siro config:cache');
        $this->write('  php siro env:check');
        $this->write('  php siro optimize');
        $this->write('  php siro route:list');
        $this->write('  php siro key:generate');
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

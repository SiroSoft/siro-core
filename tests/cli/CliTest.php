<?php

declare(strict_types=1);

namespace Siro\Core\Tests\Cli;

use PHPUnit\Framework\TestCase;
use Siro\Core\Console;

final class CliTest extends TestCase
{
    private Console $console;

    protected function setUp(): void
    {
        $this->console = new Console(dirname(__DIR__, 2));
    }

    private function getRegistry(): array
    {
        $ref = new \ReflectionClass(Console::class);
        $m = $ref->getMethod('commandRegistry');
        $m->setAccessible(true);
        return $m->invoke($this->console);
    }

    private function getGroups(): array
    {
        $ref = new \ReflectionClass(Console::class);
        $m = $ref->getMethod('groupedCommands');
        $m->setAccessible(true);
        return $m->invoke($this->console);
    }

    private function getAliases(): array
    {
        $ref = new \ReflectionClass(Console::class);
        $m = $ref->getMethod('aliases');
        $m->setAccessible(true);
        return $m->invoke($this->console);
    }

    // ==================== BASIC SANITY ====================

    public function testConsoleVersion(): void
    {
        $this->assertNotEmpty(Console::VERSION);
    }

    public function testGetVersion(): void
    {
        $this->assertEquals('0.28.0', Console::getVersion());
    }

    public function testConsoleCanBeInstantiated(): void
    {
        $this->assertInstanceOf(Console::class, $this->console);
    }

    // ==================== COMMAND REGISTRY INVENTORY ====================

    /**
     * @return array<int, array{string, string, string}>
     */
    public static function commandRegistryProvider(): array
    {
        return [
            ['make:auth', 'Generate auth system', 'php siro make:auth'],
            ['make:controller', 'Generate controller', 'php siro make:controller <name>'],
            ['make:model', 'Generate model', 'php siro make:model <name>'],
            ['make:migration', 'Generate migration', 'php siro make:migration <name>'],
            ['make:queue-table', 'Generate queue tables migration', 'php siro make:queue-table'],
            ['make:resource', 'Generate API resource transformer', 'php siro make:resource <name>'],
            ['make:seeder', 'Generate seeder', 'php siro make:seeder <name>'],
            ['make:crud', 'Full CRUD scaffolding', 'php siro make:crud <name>'],
            ['make:test', 'Generate test file', 'php siro make:test <name>'],
            ['make:job', 'Generate job class', 'php siro make:job <name>'],
            ['make:mail', 'Generate mail class', 'php siro make:mail <name>'],
            ['make:event', 'Generate event class', 'php siro make:event <name>'],
            ['make:lang', 'Generate language file', 'php siro make:lang <locale> <file>'],
            ['make:factory', 'Generate factory', 'php siro make:factory <name>'],
            ['make:openapi', 'Generate OpenAPI spec', 'php siro make:openapi'],
            ['make:postman', 'Generate Postman collection', 'php siro make:postman'],
            ['make:service', 'Generate service class', 'php siro make:service <name>'],
            ['make:repository', 'Generate repository class', 'php siro make:repository <name>'],
            ['make:middleware', 'Generate middleware class', 'php siro make:middleware <name>'],
            ['make:listener', 'Generate event listener', 'php siro make:listener <name>'],
            ['make:idempotency-table', 'Create idempotency table', 'php siro make:idempotency-table'],
            ['make:apikey-table', 'Create API keys table', 'php siro make:apikey-table'],
            ['make:apikey', 'Generate API key', 'php siro make:apikey <name>'],
            ['migrate', 'Run migrations', 'php siro migrate'],
            ['migrate:rollback', 'Rollback migrations', 'php siro migrate:rollback'],
            ['migrate:status', 'Migration status', 'php siro migrate:status'],
            ['db:seed', 'Run seeders', 'php siro db:seed'],
            ['db:show', 'Show table data/schema', 'php siro db:show <table>'],
            ['log:replay', 'Replay request', 'php siro log:replay <trace_id>'],
            ['log:trace', 'View trace details', 'php siro log:trace'],
            ['log:export', 'Export trace', 'php siro log:export <trace_id>'],
            ['log:cleanup', 'Clean old trace files', 'php siro log:cleanup'],
            ['log:slow', 'Show slow requests', 'php siro log:slow'],
            ['log:tail', 'Tail log files', 'php siro log:tail'],
            ['log:stats', 'Request statistics', 'php siro log:stats'],
            ['log:top', 'Top slowest APIs', 'php siro log:top'],
            ['debug:last', 'Show why last request failed', 'php siro debug:last'],
            ['test:run', 'Run PHPUnit test suite', 'php siro test:run'],
            ['api:test', 'Test API', 'php siro api:test'],
            ['queue:work', 'Process queue jobs', 'php siro queue:work'],
            ['queue:retry', 'Retry failed jobs', 'php siro queue:retry'],
            ['queue:flush', 'Clear failed jobs', 'php siro queue:flush'],
            ['queue:status', 'Queue statistics', 'php siro queue:status'],
            ['schedule:run', 'Run scheduled tasks', 'php siro schedule:run'],
            ['serve', 'Start dev server', 'php siro serve'],
            ['live', 'Live reload dev server', 'php siro live'],
            ['deploy', 'Deploy application', 'php siro deploy'],
            ['storage:link', 'Create storage symlink', 'php siro storage:link'],
            ['key:generate', 'Generate JWT secret', 'php siro key:generate'],
            ['config:cache', 'Cache config', 'php siro config:cache'],
            ['config:clear', 'Clear cached config and routes', 'php siro config:clear'],
            ['env:cache', 'Cache env vars', 'php siro env:cache'],
            ['optimize', 'Optimize for production', 'php siro optimize'],
            ['env:check', 'Check environment', 'php siro env:check'],
            ['env:switch', 'Switch environment', 'php siro env:switch'],
            ['doctor', 'System health check', 'php siro doctor'],
            ['fix', 'Watch code changes & auto-replay last test', 'php siro fix'],
            ['down', 'Enable maintenance mode', 'php siro down'],
            ['up', 'Disable maintenance mode', 'php siro up'],
            ['route:list', 'List all routes', 'php siro route:list'],
            ['route:search', 'Search routes by keyword', 'php siro route:search'],
            ['route:rules', 'Show validation rules', 'php siro route:rules'],
            ['trace:list', 'List recent traces', 'php siro trace:list'],
            ['rate:status', 'Rate limit dashboard', 'php siro rate:status'],
            ['replay', 'Replay last trace', 'php siro replay'],
            ['test', 'Run tests', 'php siro test'],
            ['new', 'Create new project from skeleton', 'php siro new'],
            ['new:project', 'Create project via composer', 'php siro new:project'],
            ['benchmark', 'Performance benchmark', 'php siro benchmark'],
            ['debug:health', 'Check debug system health', 'php siro debug:health'],
            ['frankenphp:serve', 'Start FrankenPHP production', 'php siro frankenphp:serve'],
        ];
    }

    public function testAllCommandsExist(): void
    {
        $this->assertCount(72, $this->getRegistry());
    }

    /**
     * @dataProvider commandRegistryProvider
     */
    public function testSpecificCommandExists(string $name, string $descPrefix, string $usagePrefix): void
    {
        $this->assertArrayHasKey($name, $this->getRegistry(), "Command '{$name}' not found in registry");
    }

    public function testMakeCommandsCount(): void
    {
        $names = array_keys($this->getRegistry());
        $make = array_filter($names, fn(string $n): bool => str_starts_with($n, 'make:'));
        $this->assertCount(23, $make);
    }

    public function testDbCommandsCount(): void
    {
        $names = array_keys($this->getRegistry());
        $db = array_filter($names, fn(string $n): bool => str_starts_with($n, 'db:') || str_starts_with($n, 'migrate'));
        $this->assertCount(5, $db);
    }

    public function testLogCommandsCount(): void
    {
        $names = array_keys($this->getRegistry());
        $log = array_filter($names, fn(string $n): bool => str_starts_with($n, 'log:') || $n === 'debug:last');
        $this->assertCount(9, $log);
    }

    public function testQueueCommandsCount(): void
    {
        $names = array_keys($this->getRegistry());
        $queue = array_filter($names, fn(string $n): bool => str_starts_with($n, 'queue:'));
        $this->assertCount(4, $queue);
    }

    // ==================== COMMAND NAME VALIDITY ====================

    public function testAllCommandNamesValidFormat(): void
    {
        foreach ($this->getRegistry() as $name => $info) {
            $this->assertMatchesRegularExpression(
                '/^[a-z][a-z0-9:_-]*$/',
                $name,
                "Command name '{$name}' has invalid format"
            );
        }
    }

    public function testAllCommandsHaveHandler(): void
    {
        foreach ($this->getRegistry() as $name => $info) {
            $this->assertArrayHasKey('handler', $info, "Command '{$name}' missing handler");
            $this->assertNotEmpty($info['handler'], "Command '{$name}' has empty handler");
        }
    }

    public function testAllCommandHandlersExist(): void
    {
        foreach ($this->getRegistry() as $name => $info) {
            $this->assertTrue(
                class_exists($info['handler']),
                "Handler class '{$info['handler']}' for command '{$name}' does not exist"
            );
        }
    }

    public function testAllCommandHandlersImplementInterface(): void
    {
        $interface = \Siro\Core\Commands\CommandInterface::class;
        foreach ($this->getRegistry() as $name => $info) {
            $handlerRef = new \ReflectionClass($info['handler']);
            $this->assertTrue(
                $handlerRef->implementsInterface($interface),
                "Handler '{$info['handler']}' for '{$name}' does not implement CommandInterface"
            );
        }
    }

    public function testAllCommandsHaveDesc(): void
    {
        foreach ($this->getRegistry() as $name => $info) {
            $this->assertArrayHasKey('desc', $info, "Command '{$name}' missing desc");
            $this->assertNotEmpty($info['desc'], "Command '{$name}' has empty desc");
        }
    }

    public function testAllCommandsHaveUsage(): void
    {
        foreach ($this->getRegistry() as $name => $info) {
            $this->assertArrayHasKey('usage', $info, "Command '{$name}' missing usage");
            $this->assertNotEmpty($info['usage'], "Command '{$name}' has empty usage");
        }
    }

    public function testAllCommandsRunMethodReturnsInt(): void
    {
        foreach ($this->getRegistry() as $name => $info) {
            $handlerRef = new \ReflectionClass($info['handler']);
            $this->assertTrue($handlerRef->hasMethod('run'), "Handler '{$info['handler']}' missing run()");
            $runMethod = $handlerRef->getMethod('run');
            $returnType = $runMethod->getReturnType();
            $this->assertNotNull($returnType, "run() in '{$info['handler']}' must have return type");
            $this->assertStringContainsString('int', $returnType->getName(), "run() in '{$info['handler']}' must return int");
        }
    }

    // ==================== GROUPED COMMANDS ====================

    public function testGroupedCommandsExist(): void
    {
        $this->assertNotEmpty($this->getGroups());
    }

    public function testExpectedGroupsExist(): void
    {
        $groups = $this->getGroups();
        $expected = ['Make / Generate', 'New Project', 'Database', 'Logs', 'Test', 'Queue & Schedule', 'Server & Deploy', 'System & Config'];
        foreach ($expected as $group) {
            $this->assertArrayHasKey($group, $groups, "Expected group '{$group}' not found");
        }
    }

    public function testGroupedCommandsSubsetOfRegistry(): void
    {
        $registry = $this->getRegistry();
        $groups = $this->getGroups();

        $groupedCmds = [];
        foreach ($groups as $entries) {
            $groupedCmds = array_merge($groupedCmds, array_keys($entries));
        }

        $ungrouped = array_diff(array_keys($registry), $groupedCmds);
        $this->assertCount(0, $ungrouped, 'All commands should be grouped; ungrouped: ' . implode(', ', array_keys($ungrouped)));
    }

    // ==================== ALIASES ====================

    public function testAliasesExist(): void
    {
        $aliases = $this->getAliases();
        $this->assertNotEmpty($aliases);
        $this->assertCount(3, $aliases);
    }

    public function testAllAliasesResolveToValidCommands(): void
    {
        $registry = $this->getRegistry();
        foreach ($this->getAliases() as $alias => $target) {
            $this->assertArrayHasKey($target, $registry, "Alias '{$alias}' -> '{$target}' points to non-existent command");
        }
    }

    public function testAliasesNotColliding(): void
    {
        $registry = $this->getRegistry();
        foreach ($this->getAliases() as $alias => $target) {
            $this->assertArrayNotHasKey($alias, $registry, "Alias '{$alias}' collides with a registered command name");
        }
    }

    // ==================== COMMAND EXECUTION ====================

    public function testRunWithVersionReturnsZero(): void
    {
        $this->assertEquals(0, $this->console->run(['siro', '--version']));
    }

    public function testRunWithHelpReturnsZero(): void
    {
        $this->assertEquals(0, $this->console->run(['siro', '--help']));
    }

    public function testRunWithListReturnsZero(): void
    {
        $this->assertEquals(0, $this->console->run(['siro', 'list']));
    }

    public function testRunWithStartReturnsZero(): void
    {
        $this->assertEquals(0, $this->console->run(['siro', 'start']));
    }

    public function testEmptyArgsReturnsZero(): void
    {
        $this->assertEquals(0, $this->console->run(['siro']));
    }

    public function testUnknownCommandReturnsOne(): void
    {
        $this->assertEquals(1, $this->console->run(['siro', 'nonexistent_command_xyz']));
    }

    public function testShortVOption(): void
    {
        $this->assertEquals(0, $this->console->run(['siro', '-V']));
    }

    public function testShortHelpOption(): void
    {
        $this->assertEquals(0, $this->console->run(['siro', '-h']));
    }

    public function testHelpForCommandReturnsZero(): void
    {
        foreach (['make:auth', 'make:crud', 'make:model', 'migrate', 'serve', 'test', 'doctor', 'benchmark', 'route:list'] as $cmd) {
            $this->assertEquals(0, $this->console->run(['siro', $cmd, '--help']), "'{$cmd} --help' failed");
        }
    }

    public function testAllMakeCommandsHaveHelp(): void
    {
        $make = array_filter(
            array_keys($this->getRegistry()),
            fn(string $n): bool => str_starts_with($n, 'make:')
        );
        foreach ($make as $cmd) {
            $this->assertEquals(0, $this->console->run(['siro', $cmd, '--help']), "'{$cmd} --help' returned non-zero");
        }
    }

    public function testAllDbCommandsHaveHelp(): void
    {
        foreach (['migrate', 'migrate:rollback', 'migrate:status', 'db:seed', 'db:show'] as $cmd) {
            $this->assertEquals(0, $this->console->run(['siro', $cmd, '--help']), "'{$cmd} --help' failed");
        }
    }

    public function testAllLogCommandsHaveHelp(): void
    {
        foreach (['log:trace', 'log:replay', 'log:export', 'log:cleanup', 'log:slow', 'log:tail', 'log:stats', 'log:top', 'debug:last'] as $cmd) {
            $this->assertEquals(0, $this->console->run(['siro', $cmd, '--help']), "'{$cmd} --help' failed");
        }
    }

    public function testAllQueueCommandsHaveHelp(): void
    {
        foreach (['queue:work', 'queue:retry', 'queue:flush', 'queue:status'] as $cmd) {
            $this->assertEquals(0, $this->console->run(['siro', $cmd, '--help']), "'{$cmd} --help' failed");
        }
    }

    public function testAllSystemCommandsHaveHelp(): void
    {
        $cmds = ['key:generate', 'config:cache', 'config:clear', 'env:cache', 'optimize',
                 'env:check', 'env:switch', 'doctor', 'fix', 'down', 'up', 'route:list',
                 'route:search', 'route:rules', 'trace:list', 'rate:status', 'replay', 'schedule:run'];
        foreach ($cmds as $cmd) {
            $this->assertEquals(0, $this->console->run(['siro', $cmd, '--help']), "'{$cmd} --help' failed");
        }
    }

    // ==================== ALIAS EXECUTION ====================

    public function testAliasSlowResolves(): void
    {
        $this->assertEquals(0, $this->console->run(['siro', 'slow', '--help']));
    }

    public function testAliasWhyResolves(): void
    {
        $this->assertEquals(0, $this->console->run(['siro', 'why', '--help']));
    }

    public function testAliasTracesResolves(): void
    {
        $this->assertEquals(0, $this->console->run(['siro', 'traces', '--help']));
    }

    public function testAliasMakeDocsResolves(): void
    {
        $this->assertEquals(0, $this->console->run(['siro', 'make:docs', '--help']));
    }

    public function testAliasTResolves(): void
    {
        $this->assertEquals(0, $this->console->run(['siro', 't', '--help']));
    }

    // ==================== LEVENSHTEIN SUGGESTIONS ====================

    public function testUnknownCommandSuggestsSimilar(): void
    {
        $this->assertEquals(1, $this->console->run(['siro', 'migratee']));
    }

    public function testUnknownCommandSuggestsStartsWith(): void
    {
        $this->assertEquals(1, $this->console->run(['siro', 'make-controller']));
    }

    // ==================== SIRO SCRIPT ====================

    public function testSiroScriptExists(): void
    {
        $siroPath = dirname(__DIR__, 3) . '/SiroPHP/siro';
        $this->assertFileExists($siroPath);
    }

    public function testSiroScriptIsValidPhp(): void
    {
        $siroPath = dirname(__DIR__, 3) . '/SiroPHP/siro';
        $content = file_get_contents($siroPath);
        $this->assertStringStartsWith('#!/usr/bin/env php', $content);
        $this->assertStringContainsString('Console', $content);
        $this->assertStringContainsString('$console->run($argv)', $content);
    }

    // ==================== COMMAND HANDLER STRUCTURE ====================

    public function testAllHandlerConstructorsAcceptBasePath(): void
    {
        foreach ($this->getRegistry() as $name => $info) {
            $handlerRef = new \ReflectionClass($info['handler']);
            $constructor = $handlerRef->getConstructor();
            if ($constructor === null) {
                continue;
            }
            $params = $constructor->getParameters();
            $this->assertNotEmpty($params, "{$info['handler']} constructor has no parameters");
            $firstParam = $params[0];
            $this->assertStringContainsString(
                'basePath',
                $firstParam->getName(),
                "First param of {$info['handler']} should be \$basePath"
            );
        }
    }

    public function testAllHandlersUseCommandSupport(): void
    {
        $interface = \Siro\Core\Commands\CommandInterface::class;
        foreach ($this->getRegistry() as $name => $info) {
            $handlerRef = new \ReflectionClass($info['handler']);
            $found = false;
            $classesToCheck = [$handlerRef];
            while ($classesToCheck !== []) {
                $class = array_shift($classesToCheck);
                $traits = $class->getTraitNames();
                if (in_array('Siro\Core\Commands\CommandSupport', $traits, true)) {
                    $found = true;
                    break;
                }
                $parent = $class->getParentClass();
                if ($parent !== false) {
                    $classesToCheck[] = $parent;
                }
                foreach ($traits as $trait) {
                    $traitRef = new \ReflectionClass($trait);
                    $classesToCheck[] = $traitRef;
                }
            }
            $this->assertTrue($found, "Handler {$info['handler']} does not have access to CommandSupport trait");
        }
    }

    // ==================== NO DEAD / DUPLICATE COMMANDS ====================

    public function testNoDuplicateCommandNames(): void
    {
        $names = array_keys($this->getRegistry());
        $this->assertCount(count($names), array_unique($names));
    }

    // ==================== COMPREHENSIVE COVERAGE ====================

    public function testAllImportHavesMatchingRegistration(): void
    {
        $consoleContent = file_get_contents(dirname(__DIR__, 2) . '/Console.php');
        preg_match_all('/^use Siro\\\\Core\\\\Commands\\\\(\w+Command)/m', $consoleContent, $imports);
        $importedClasses = $imports[1] ?? [];

        foreach ($this->getRegistry() as $info) {
            $shortName = (new \ReflectionClass($info['handler']))->getShortName();
            $this->assertContains(
                $shortName,
                $importedClasses,
                "Handler {$shortName} is registered but not imported in Console.php"
            );
        }
    }
}

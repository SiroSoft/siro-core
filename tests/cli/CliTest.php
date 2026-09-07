<?php

declare(strict_types=1);

namespace Siro\Core\Tests\Cli;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
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
        $this->assertEquals(Console::VERSION, Console::getVersion());
    }

    public function testConsoleCanBeInstantiated(): void
    {
        $this->assertInstanceOf(Console::class, $this->console);
    }    // ==================== COMMAND REGISTRY INVENTORY ====================

    /**
     * Dynamically reads registered commands from Console.php source.
     * This prevents the test from going stale when commands are added/removed.
     *
     * @return array<int, array{string, string, string}>
     */
    public static function commandRegistryProvider(): array
    {
        $consoleFile = dirname(__DIR__, 2) . '/Console.php';
        $content = file_get_contents($consoleFile);
        if ($content === false) {
            return [];
        }

        // Extract command name + description from registry
        preg_match_all(
            "/'([a-z][a-z0-9:_-]+)'\s*=>\s*\['handler'\s*=>\s*[A-Z\\w]+::class,\s*'desc'\s*=>\s*'([^']*)'/",
            $content,
            $matches
        );

        $result = [];
        $count = count($matches[1]);
        for ($i = 0; $i < $count; $i++) {
            $name = $matches[1][$i];
            $desc = $matches[2][$i];
            $result[] = [$name, $desc, 'php siro ' . $name];
        }

        return $result;
    }

    public function testAllCommandsExist(): void
    {
        // Count must match actual registered commands in Console.php
        $this->assertCount(95, $this->getRegistry());
    }

    #[DataProvider('commandRegistryProvider')]
    public function testSpecificCommandExists(string $name, string $descPrefix, string $usagePrefix): void
    {
        $this->assertArrayHasKey($name, $this->getRegistry(), "Command '{$name}' not found in registry");
    }

    public function testMakeCommandsCount(): void
    {
        $names = array_keys($this->getRegistry());
        $make = array_filter($names, fn(string $n): bool => str_starts_with($n, 'make:'));
        $this->assertCount(26, $make);
    }

    public function testDbCommandsCount(): void
    {
        $names = array_keys($this->getRegistry());
        $db = array_filter($names, fn(string $n): bool => str_starts_with($n, 'db:') || str_starts_with($n, 'migrate'));
        $this->assertCount(17, $db);
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
        if (!is_dir(dirname($siroPath))) {
            $this->markTestSkipped('SiroPHP skeleton not present');
        }
        $this->assertFileExists($siroPath);
    }

    public function testSiroScriptIsValidPhp(): void
    {
        $siroPath = dirname(__DIR__, 3) . '/SiroPHP/siro';
        if (!is_dir(dirname($siroPath))) {
            $this->markTestSkipped('SiroPHP skeleton not present');
        }
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

<?php

declare(strict_types=1);

namespace Siro\Core\Tests\Unit;

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Siro\Core\Console;

/**
 * Regression test: Console::discoverAppCommands() previously used
 * $this->commands (non-existent instance property) instead of
 * self::$appCommands (static property). This caused a PHPStan error
 * and would fatal on PHP 8.4+ where dynamic properties are removed.
 *
 * @coversNothing (tests private discovery behavior)
 */
class ConsoleAppDiscoveryTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/siro-console-test-' . uniqid();
        mkdir($this->tempDir, 0777, true);
    }

    protected function tearDown(): void
    {
        // Clean up temp directory
        $this->removeDir($this->tempDir);
    }

    public function testDiscoverAppCommandsRegistersFromAppDirectory(): void
    {
        // Create app/Console/Commands/ structure with a test command
        $commandsDir = $this->tempDir . '/app/Console/Commands';
        mkdir($commandsDir, 0777, true);

        $commandContent = <<<'PHP'
<?php

declare(strict_types=1);

namespace App\Console\Commands;

class TestDiscoveryCommand implements \Siro\Core\Commands\CommandInterface
{
    public static string $signature = 'test:discovery';
    public static string $description = 'A test command for discovery';

    public function run(array $args): int
    {
        return 0;
    }
}
PHP;

        file_put_contents($commandsDir . '/TestDiscoveryCommand.php', $commandContent);

        // Clear static state before test
        $reflection = new ReflectionClass(Console::class);
        $prop = $reflection->getProperty('appCommands');
        $prop->setAccessible(true);
        $prop->setValue(null, []);

        // Construct Console — triggers discoverAppCommands()
        $console = new Console($this->tempDir);

        // Verify the command was registered
        $registered = $prop->getValue(null);
        $this->assertArrayHasKey('test:discovery', $registered);
        $this->assertSame('App\Console\Commands\TestDiscoveryCommand', $registered['test:discovery']['handler']);
        $this->assertSame('A test command for discovery', $registered['test:discovery']['desc']);
    }

    public function testDiscoverAppCommandsSkipsNonCommandInterface(): void
    {
        $commandsDir = $this->tempDir . '/app/Console/Commands';
        mkdir($commandsDir, 0777, true);

        // File without CommandInterface
        $notACommand = <<<'PHP'
<?php
class NotACommand { public static string $signature = 'skip:me'; }
PHP;
        file_put_contents($commandsDir . '/NotACommand.php', $notACommand);

        $reflection = new ReflectionClass(Console::class);
        $prop = $reflection->getProperty('appCommands');
        $prop->setAccessible(true);
        $prop->setValue(null, []);

        $console = new Console($this->tempDir);

        $registered = $prop->getValue(null);
        $this->assertArrayNotHasKey('skip:me', $registered);
    }

    public function testDiscoverAppCommandsSkipsCollisionsWithCoreCommands(): void
    {
        $commandsDir = $this->tempDir . '/app/Console/Commands';
        mkdir($commandsDir, 0777, true);

        // Try to register a command with the same name as a core command
        $collideCommand = <<<'PHP'
<?php

declare(strict_types=1);

namespace App\Console\Commands;

class CollideMigrateCommand implements \Siro\Core\Commands\CommandInterface
{
    public static string $signature = 'migrate';
    public static string $description = 'This should not replace core migrate';

    public function run(array $args): int
    {
        return 1;
    }
}
PHP;

        file_put_contents($commandsDir . '/CollideMigrateCommand.php', $collideCommand);

        $reflection = new ReflectionClass(Console::class);
        $prop = $reflection->getProperty('appCommands');
        $prop->setAccessible(true);
        $prop->setValue(null, []);

        $console = new Console($this->tempDir);

        $registered = $prop->getValue(null);
        // Core 'migrate' should NOT be overridden by app command
        $this->assertArrayNotHasKey('migrate', $registered);
    }

    public function testDiscoverAppCommandsHandlesMissingDirectory(): void
    {
        // No app/Console/Commands directory at all
        $reflection = new ReflectionClass(Console::class);
        $prop = $reflection->getProperty('appCommands');
        $prop->setAccessible(true);
        $prop->setValue(null, []);

        // Should not throw — just return early
        $console = new Console($this->tempDir);

        $registered = $prop->getValue(null);
        $this->assertEmpty($registered);
    }

    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $items = array_diff(scandir($dir), ['.', '..']);
        foreach ($items as $item) {
            $path = $dir . DIRECTORY_SEPARATOR . $item;
            is_dir($path) ? $this->removeDir($path) : unlink($path);
        }
        rmdir($dir);
    }
}

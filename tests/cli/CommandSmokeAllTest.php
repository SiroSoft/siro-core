<?php

declare(strict_types=1);

namespace Siro\Core\Tests\Cli;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Siro\Core\Console;
use Siro\Core\Env;
use Siro\Core\Config;

/**
 * Data-driven smoke test for ALL registered CLI commands.
 *
 * Verifies every command:
 * - Boots without fatal
 * - --help exits cleanly
 * - No side effects from --help
 * - Correct command identity in help output
 *
 * Source of truth: Console::commandRegistry() via reflection.
 * No hardcoded command list — if a command is registered, it's tested.
 */
final class CommandSmokeAllTest extends TestCase
{
    private static string $tempProject;

    public static function setUpBeforeClass(): void
    {
        self::$tempProject = sys_get_temp_dir() . '/siro_smoke_all_' . bin2hex(random_bytes(4));

        $dirs = [
            'storage/framework', 'storage/logs/traces', 'storage/logs',
            'storage/cache', 'storage/app', 'storage/public',
            'routes', 'config', 'public', 'app/Controllers',
            'app/Models', 'app/Resources', 'tests/Feature', 'tests/Unit',
            'docs', 'docs/openapi',
        ];
        foreach ($dirs as $dir) {
            @mkdir(self::$tempProject . '/' . $dir, 0777, true);
        }

        file_put_contents(
            self::$tempProject . '/.env',
            'APP_NAME="SiroSmokeTest"' . "\n"
            . 'APP_ENV=testing' . "\n"
            . 'APP_DEBUG=true' . "\n"
            . 'APP_KEY=testing_app_key_for_hmac_32chars!!' . "\n"
            . 'JWT_SECRET=test_jwt_secret_key_for_smoke_tests_32chars!!' . "\n"
            . 'DB_CONNECTION=sqlite' . "\n"
            . 'DB_DATABASE=:memory:' . "\n"
            . 'APP_URL=http://localhost:8080' . "\n"
            . 'CORS_ALLOWED_ORIGINS=*' . "\n"
        );

        file_put_contents(self::$tempProject . '/config/database.php', '<?php return ["driver" => "sqlite", "database" => ":memory:"];');
        file_put_contents(self::$tempProject . '/config/app.php', '<?php return ["name" => "SiroSmokeTest", "env" => "testing"];');
        file_put_contents(self::$tempProject . '/routes/api.php', "<?php\n\$router->get('/api/health', function () { return ['success' => true]; });\n");
    }

    public static function tearDownAfterClass(): void
    {
        self::rmdir(self::$tempProject);
        Env::reset();
        Config::reset();
    }

    private static function rmdir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $items = array_diff(scandir($dir), ['.', '..']);
        foreach ($items as $item) {
            $path = $dir . '/' . $item;
            is_dir($path) ? self::rmdir($path) : unlink($path);
        }
        rmdir($dir);
    }

    /**
     * Reads the actual command registry from Console.php via reflection.
     *
     * @return array<int, array{string}>
     */
    public static function allCommandsProvider(): array
    {
        $consoleFile = dirname(__DIR__, 2) . '/Console.php';
        $content = file_get_contents($consoleFile);
        if ($content === false) {
            return [];
        }

        preg_match_all(
            "/'([a-z][a-z0-9:_-]+)'\s*=>\s*\['handler'/",
            $content,
            $matches
        );

        $commands = array_unique($matches[1]);
        sort($commands);

        return array_map(fn(string $cmd) => [$cmd], $commands);
    }

    /**
     * Global CLI commands (not registered in registry).
     *
     * @return array<int, array{string, string}>
     */
    public static function globalCommandsProvider(): array
    {
        return [
            ['', 'empty command shows workflow'],
            ['list', 'list command'],
            ['list --raw', 'list --raw'],
            ['list --json', 'list --json'],
            ['help', 'help command'],
            ['--help', '--help flag'],
            ['-h', '-h flag'],
            ['--version', '--version flag'],
            ['-V', '-V flag'],
        ];
    }

    #[DataProvider('allCommandsProvider')]
    #[Test]
    public function commandHelpSmoke(string $command): void
    {
        $console = new Console(self::$tempProject);
        $argv = ['siro', $command, '--help'];

        ob_start();
        $exitCode = $console->run($argv);
        $output = ob_get_clean();

        // Must not fatal
        $this->assertIsInt($exitCode, "Command '{$command}' must return int exit code");

        // Must exit 0 for --help
        $this->assertSame(0, $exitCode, "Command '{$command} --help' should exit 0, got: " . substr($output, 0, 200));

        // Must produce output
        $this->assertNotEmpty($output, "Command '{$command} --help' should produce output");

        // Help should not trigger file writes (no "Generated:" or "Created:" in output)
        $this->assertStringNotContainsString('Generated:', $output, "Command '{$command} --help' should not generate files");
        $this->assertStringNotContainsString('Created:', $output, "Command '{$command} --help' should not create files");

        // Must not throw exceptions
        $this->assertStringNotContainsString('Exception', $output, "Command '{$command} --help' should not throw exceptions");
        $this->assertStringNotContainsString('Fatal error', $output, "Command '{$command} --help' should not produce fatal errors");
    }

    #[DataProvider('allCommandsProvider')]
    #[Test]
    public function commandIdentityInHelp(string $command): void
    {
        $console = new Console(self::$tempProject);
        $argv = ['siro', $command, '--help'];

        ob_start();
        $console->run($argv);
        $output = ob_get_clean();

        // Help output should contain the command name (or at least the namespace)
        $commandBase = explode(':', $command)[0];
        $this->assertTrue(
            str_contains($output, $command) || str_contains($output, $commandBase),
            "Command '{$command}' help should reference itself. Output: " . substr($output, 0, 300)
        );
    }

    #[DataProvider('globalCommandsProvider')]
    #[Test]
    public function globalCommandSmoke(string $command, string $description): void
    {
        $console = new Console(self::$tempProject);
        $argv = array_filter(['siro', ...explode(' ', $command)], fn(string $s) => $s !== '');

        ob_start();
        $exitCode = $console->run($argv);
        $output = ob_get_clean();

        $this->assertIsInt($exitCode, "Global command '{$command}' must return int");
        $this->assertSame(0, $exitCode, "Global command '{$command}' should exit 0. Output: " . substr($output, 0, 300));
        $this->assertNotEmpty($output, "Global command '{$command}' should produce output");
    }

    #[Test]
    public function versionCommandShowsCurrentVersion(): void
    {
        $console = new Console(self::$tempProject);

        ob_start();
        $console->run(['siro', '--version']);
        $output = ob_get_clean();

        $this->assertStringContainsString(Console::VERSION, $output, 'Version should show current VERSION constant');
    }

    #[Test]
    public function helpShowsAllRegisteredCommands(): void
    {
        $console = new Console(self::$tempProject);

        ob_start();
        $console->run(['siro', 'help']);
        $output = ob_get_clean();

        // Help should mention key command groups
        $this->assertStringContainsString('make:', $output, 'Help should list make commands');
        $this->assertStringContainsString('migrate', $output, 'Help should list migrate commands');
        $this->assertStringContainsString('log:', $output, 'Help should list log commands');
        $this->assertStringContainsString('test', $output, 'Help should list test commands');
    }

    #[Test]
    public function listJsonIsValidJson(): void
    {
        $console = new Console(self::$tempProject);

        ob_start();
        $console->run(['siro', 'list', '--json']);
        $output = ob_get_clean();

        $decoded = json_decode($output, true);
        $this->assertIsArray($decoded, 'list --json should output valid JSON array');
        // JSON is grouped — count total commands across groups
        $totalCommands = 0;
        foreach ($decoded as $group) {
            $totalCommands += count($group['commands'] ?? []);
        }
        $this->assertGreaterThanOrEqual(90, $totalCommands, 'list --json should contain ~95 commands across groups');
    }

    #[Test]
    public function unknownCommandReturnsNonZero(): void
    {
        $console = new Console(self::$tempProject);

        ob_start();
        $exitCode = $console->run(['siro', 'nonexistent-command-xyz']);
        $output = ob_get_clean();

        $this->assertNotSame(0, $exitCode, 'Unknown command should return non-zero');
        $this->assertStringContainsString('Unknown command', $output, 'Unknown command should show error message');
    }

    #[Test]
    public function registryIntegrityCommandCount(): void
    {
        $console = new Console(self::$tempProject);
        $ref = new \ReflectionClass(Console::class);
        $m = $ref->getMethod('commandRegistry');
        $m->setAccessible(true);
        $registry = $m->invoke($console);

        // Must have exactly 95 registered commands
        $this->assertCount(95, $registry, 'Console must register exactly 95 commands');

        // All keys must be strings
        foreach ($registry as $name => $config) {
            $this->assertIsString($name, 'Command name must be string');
            $this->assertArrayHasKey('handler', $config, "Command '{$name}' must have handler");
            $this->assertArrayHasKey('desc', $config, "Command '{$name}' must have desc");
            $this->assertTrue(
                class_exists($config['handler']),
                "Command '{$name}' handler class '{$config['handler']}' must exist"
            );
        }
    }
}

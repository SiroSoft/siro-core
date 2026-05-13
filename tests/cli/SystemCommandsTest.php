<?php

declare(strict_types=1);

namespace Siro\Core\Tests\Cli;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Siro\Core\Console;
use Siro\Core\Env;
use Siro\Core\Config;

final class SystemCommandsTest extends TestCase
{
    private static string $tempProject;
    private Console $console;

    public static function setUpBeforeClass(): void
    {
        self::$tempProject = sys_get_temp_dir() . '/siro_system_test_' . bin2hex(random_bytes(4));

        $dirs = [
            'storage/framework',
            'storage/logs/traces',
            'storage/cache',
            'storage/app',
            'storage/public',
            'routes',
            'config',
            'public',
            'app/Controllers',
        ];
        foreach ($dirs as $dir) {
            if (!is_dir(self::$tempProject . '/' . $dir)) {
                mkdir(self::$tempProject . '/' . $dir, 0777, true);
            }
        }

        file_put_contents(
            self::$tempProject . '/.env',
            'APP_NAME="SiroTest"' . "\n"
            . 'APP_ENV=testing' . "\n"
            . 'APP_DEBUG=true' . "\n"
            . 'JWT_SECRET=test_jwt_secret_key_for_testing_32chars!!!' . "\n"
            . 'DB_CONNECTION=sqlite' . "\n"
            . 'APP_URL=http://localhost' . "\n"
            . 'CORS_ALLOWED_ORIGINS=*' . "\n"
        );

        file_put_contents(self::$tempProject . '/routes/api.php', "<?php\n\$router->get('/api/health', 'HealthController@index');\n");

        $dbConfig = '<?php return ['
            . "'driver' => 'sqlite',"
            . "'database' => ':memory:',"
            . "'slow_query_threshold' => 500,"
            . '];' . "\n";
        file_put_contents(self::$tempProject . '/config/database.php', $dbConfig);

        file_put_contents(self::$tempProject . '/storage/logs/error.log', '');
        file_put_contents(self::$tempProject . '/storage/logs/slow.log', '');
        file_put_contents(self::$tempProject . '/storage/logs/.htaccess', 'Deny from all');
        file_put_contents(self::$tempProject . '/storage/logs/traces/.gitkeep', '');
    }

    private static function removeJunction(string $path): void
    {
        if (DIRECTORY_SEPARATOR === '\\' && is_dir($path)) {
            exec('rmdir /Q "' . $path . '" 2>nul', $_, $code);
        }
    }

    public static function tearDownAfterClass(): void
    {
        $junction = self::$tempProject . '/public/storage';
        if (is_dir($junction)) {
            self::removeJunction($junction);
        }
        self::rmdir(self::$tempProject);

        Env::reset();
        Config::reset();
    }

    private static function cleanupProject(string $name): void
    {
        $testProject = getcwd() . '/' . $name;
        if (is_dir($testProject)) {
            self::rmdir($testProject);
        }
    }

    private static function rmdir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($files as $file) {
            if ($file->isDir()) {
                @rmdir($file->getRealPath());
            } else {
                @unlink($file->getRealPath());
            }
        }
        @rmdir($dir);
    }

    protected function setUp(): void
    {
        Env::reset();
        Config::reset();
        $this->console = new Console(self::$tempProject);
    }

    private function runCommand(string $command, array $args = []): array
    {
        $argv = array_merge(['siro', $command], $args);
        ob_start();
        $exitCode = $this->console->run($argv);
        $output = ob_get_clean();
        return [$exitCode, $output];
    }

    // ==================== VERSION ====================

    public function testLongVersionOutput(): void
    {
        [$exitCode, $output] = $this->runCommand('--version');
        $this->assertSame(0, $exitCode, '--version should exit 0');
        $this->assertStringContainsString('0.24.0', $output, 'Should show version 0.24.0');
    }

    public function testShortVersionOutput(): void
    {
        [$exitCode, $output] = $this->runCommand('-V');
        $this->assertSame(0, $exitCode, '-V should exit 0');
        $this->assertStringContainsString('0.24.0', $output, 'Should show version 0.24.0');
    }

    // ==================== HELP ====================

    public function testLongHelpOutput(): void
    {
        [$exitCode, $output] = $this->runCommand('--help');
        $this->assertSame(0, $exitCode, '--help should exit 0');
        $this->assertStringContainsString('SiroPHP', $output);
        $this->assertStringContainsString('Usage:', $output);
    }

    public function testShortHelpOutput(): void
    {
        [$exitCode, $output] = $this->runCommand('-h');
        $this->assertSame(0, $exitCode, '-h should exit 0');
        $this->assertStringContainsString('SiroPHP', $output);
    }

    public function testHelpCommandOutput(): void
    {
        [$exitCode, $output] = $this->runCommand('help');
        $this->assertSame(0, $exitCode, 'help should exit 0');
        $this->assertStringContainsString('SiroPHP', $output);
    }

    // ==================== NO ARGS ====================

    public function testNoArgsShowsWorkflow(): void
    {
        [$exitCode, $output] = $this->runCommand('');
        $this->assertSame(0, $exitCode, 'no args should exit 0');
        $this->assertStringContainsString('SiroPHP', $output);
        $this->assertStringContainsString('Workflow', $output);
    }

    // ==================== LIST ====================

    public function testListShowsCommands(): void
    {
        [$exitCode, $output] = $this->runCommand('list');
        $this->assertSame(0, $exitCode, 'list should exit 0');
        $this->assertStringContainsString('Commands', $output);
        $this->assertStringContainsString('make:', $output, 'List should show make: commands');
        $this->assertStringContainsString('serve', $output, 'List should show serve command');
    }

    // ==================== COMMAND HELP ====================

    /** @return array<int, array{string}> */
    public static function commandHelpProvider(): array
    {
        return [
            'key:generate' => ['key:generate'],
            'env:check'    => ['env:check'],
            'doctor'       => ['doctor'],
            'route:list'   => ['route:list'],
            'route:search' => ['route:search'],
            'benchmark'    => ['benchmark'],
            'config:cache' => ['config:cache'],
            'config:clear' => ['config:clear'],
            'optimize'     => ['optimize'],
            'new'          => ['new'],
            'storage:link' => ['storage:link'],
            'debug:health' => ['debug:health'],
            'up'           => ['up'],
            'down'         => ['down'],
            'serve'        => ['serve'],
            'test'         => ['test'],
            'migrate'      => ['migrate'],
            'make:crud'    => ['make:crud'],
            'make:controller' => ['make:controller'],
            'make:model'   => ['make:model'],
            'make:auth'    => ['make:auth'],
            'queue:work'   => ['queue:work'],
            'api:test'     => ['api:test'],
            'schedule:run' => ['schedule:run'],
            'deploy'       => ['deploy'],
        ];
    }

    #[DataProvider('commandHelpProvider')]
    public function testCommandHelp(string $command): void
    {
        [$exitCode, $output] = $this->runCommand($command, ['--help']);
        $this->assertSame(0, $exitCode, "'{$command} --help' should exit 0");
        $this->assertStringContainsString($command, $output, "Help for {$command} should reference the command name");
    }

    // ==================== KEY:GENERATE ====================

    public function testKeyGenerate(): void
    {
        [$exitCode, $output] = $this->runCommand('key:generate');
        $this->assertSame(0, $exitCode, 'key:generate should exit 0');
        $this->assertStringContainsString('JWT_SECRET', $output);
        $this->assertStringContainsString('generated', $output);
    }

    // ==================== ENV:CHECK ====================

    public function testEnvCheck(): void
    {
        [$exitCode, $output] = $this->runCommand('env:check');
        $this->assertSame(0, $exitCode, 'env:check should exit 0');
        $this->assertStringContainsString('Environment Check', $output);
        $this->assertStringContainsString('OK', $output);
    }

    // ==================== DOCTOR ====================

    public function testDoctor(): void
    {
        [$exitCode, $output] = $this->runCommand('doctor');
        $this->assertSame(0, $exitCode, 'doctor should exit 0');
        $this->assertStringContainsString('Environment Doctor', $output);
        $this->assertStringContainsString('PHP Version', $output);
    }

    // ==================== ROUTE:LIST ====================

    public function testRouteList(): void
    {
        [$exitCode, $output] = $this->runCommand('route:list');
        $this->assertSame(0, $exitCode, 'route:list should exit 0');
        $hasRoutes = str_contains($output, 'Method');
        $hasNoRoutes = str_contains($output, 'No routes');
        $this->assertTrue($hasRoutes || $hasNoRoutes, 'route:list should show routes or "No routes" message');
    }

    // ==================== ROUTE:SEARCH ====================

    public function testRouteSearch(): void
    {
        [$exitCode, $output] = $this->runCommand('route:search', ['user']);
        $this->assertSame(0, $exitCode, 'route:search should exit 0');
    }

    // ==================== BENCHMARK ====================

    public function testBenchmarkJson(): void
    {
        [$exitCode, $output] = $this->runCommand('benchmark', ['--iterations=5', '--json']);
        $this->assertSame(0, $exitCode, 'benchmark should exit 0');
        $this->assertStringContainsString('"results"', $output, 'JSON output should contain results key');
        $this->assertStringContainsString('"iterations": 5', $output, 'JSON should reflect 5 iterations');
    }

    // ==================== CONFIG:CACHE ====================

    public function testConfigCache(): void
    {
        [$exitCode, $output] = $this->runCommand('config:cache');
        $this->assertSame(0, $exitCode, 'config:cache should exit 0');
        $this->assertStringContainsString('cached', $output);
    }

    // ==================== CONFIG:CLEAR ====================

    public function testConfigClear(): void
    {
        [$exitCode, $output] = $this->runCommand('config:clear');
        $this->assertSame(0, $exitCode, 'config:clear should exit 0');
        $this->assertStringContainsString('cleared', $output);
    }

    // ==================== OPTIMIZE ====================

    public function testOptimize(): void
    {
        [$exitCode, $output] = $this->runCommand('optimize');
        $this->assertSame(0, $exitCode, 'optimize should exit 0');
        $this->assertStringContainsString('Optimization complete', $output);
    }

    // ==================== NEW PROJECT ====================

    public function testNewProject(): void
    {
        $projectName = 'TestProject_' . bin2hex(random_bytes(4));
        $targetPath = getcwd() . '/' . $projectName;

        try {
            [$exitCode, $output] = $this->runCommand('new', [$projectName]);
            $this->assertSame(0, $exitCode, "new {$projectName} should exit 0");
            $this->assertStringContainsString('created successfully', $output);
            $this->assertDirectoryExists($targetPath, 'Project directory should be created');
        } finally {
            if (is_dir($targetPath)) {
                self::rmdir($targetPath);
            }
        }
    }

    // ==================== STORAGE:LINK ====================

    public function testStorageLink(): void
    {
        [$exitCode, $output] = $this->runCommand('storage:link');
        $this->assertContainsEquals($exitCode, [0, 1], 'storage:link should exit 0 on success or 1 if symlinks not supported');
        $hasLink = str_contains($output, 'Link created') || str_contains($output, 'already exists');
        $noSupport = str_contains($output, 'Could not create');
        $this->assertTrue($hasLink || $noSupport, 'storage:link should either succeed or report failure');

        $junction = self::$tempProject . '/public/storage';
        if (is_dir($junction)) {
            self::removeJunction($junction);
        }
    }

    // ==================== DEBUG:HEALTH ====================

    public function testDebugHealth(): void
    {
        [$exitCode, $output] = $this->runCommand('debug:health');
        $this->assertSame(0, $exitCode, 'debug:health should exit 0');
        $this->assertStringContainsString('Debug', $output);
    }

    // ==================== MAINTENANCE MODE CYCLE ====================

    public function testMaintenanceModeCycle(): void
    {
        $downFile = self::$tempProject . '/storage/framework/down';

        // 1. up first (should say already live)
        if (file_exists($downFile)) {
            unlink($downFile);
        }
        [$exitUp1, $outputUp1] = $this->runCommand('up');
        $this->assertSame(0, $exitUp1, 'up should exit 0');
        $this->assertStringContainsString('already live', $outputUp1);

        // 2. down --message="Test"
        [$exitDown, $outputDown] = $this->runCommand('down', ['--message=Test']);
        $this->assertSame(0, $exitDown, 'down should exit 0');
        $this->assertStringContainsString('maintenance', $outputDown);
        $this->assertStringContainsString('Test', $outputDown);
        $this->assertFileExists($downFile, 'down marker file should exist');

        // 3. up again (should say live)
        [$exitUp2, $outputUp2] = $this->runCommand('up');
        $this->assertSame(0, $exitUp2, 'up should exit 0');
        $this->assertStringContainsString('live', $outputUp2);
        $this->assertFileDoesNotExist($downFile, 'down marker file should be removed');
    }

    // ==================== ERROR HANDLING ====================

    public function testUnknownCommand(): void
    {
        [$exitCode, $output] = $this->runCommand('nonexistent_command_xyz');
        $this->assertSame(1, $exitCode, 'Unknown command should exit 1');
        $this->assertStringContainsString('Unknown command', $output);
    }

    public function testUnknownCommandLevenshteinSuggestion(): void
    {
        [$exitCode, $output] = $this->runCommand('migratee');
        $this->assertSame(1, $exitCode, 'Unknown command should exit 1');
        $this->assertStringContainsString('migrate', $output, 'Should suggest similar command via Levenshtein');
    }

    public function testMakeControllerNoArgs(): void
    {
        [$exitCode, $output] = $this->runCommand('make:controller');
        $this->assertSame(1, $exitCode, 'make:controller with no args should exit 1');
        $this->assertStringContainsString('required', $output, 'Should show error about missing name');
    }

    public function testCommandHelpWithShortOption(): void
    {
        [$exitCode, $output] = $this->runCommand('doctor', ['-h']);
        $this->assertSame(0, $exitCode, 'doctor -h should exit 0');
        $this->assertStringContainsString('doctor', $output);
    }

    public function testCommandHelpWithHelpCommand(): void
    {
        [$exitCode, $output] = $this->runCommand('help', ['doctor']);
        $this->assertSame(0, $exitCode, 'help doctor should exit 0');
    }

    // ==================== START COMMAND ====================

    public function testStartCommand(): void
    {
        [$exitCode, $output] = $this->runCommand('start');
        $this->assertSame(0, $exitCode, 'start should exit 0');
        $this->assertStringContainsString('START', $output);
    }

    // ==================== ALIASES ====================

    public function testAliasSlow(): void
    {
        [$exitCode, $output] = $this->runCommand('slow', ['--help']);
        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('log:slow', $output);
    }

    public function testAliasWhy(): void
    {
        [$exitCode, $output] = $this->runCommand('why', ['--help']);
        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('debug:last', $output);
    }

    public function testAliasTraces(): void
    {
        [$exitCode, $output] = $this->runCommand('traces', ['--help']);
        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('trace:list', $output);
    }

    // ==================== KEY:GENERATE IDEMPOTENT ====================

    public function testKeyGenerateIdempotent(): void
    {
        [$exitCode1, $output1] = $this->runCommand('key:generate');
        $this->assertSame(0, $exitCode1);

        [$exitCode2, $output2] = $this->runCommand('key:generate');
        $this->assertSame(0, $exitCode2);
        $this->assertStringContainsString('JWT_SECRET', $output2, 'Second key:generate should also work');
    }

    // ==================== CONFIG CACHE THEN CLEAR ====================

    public function testConfigCacheThenClear(): void
    {
        [$exitCache, $outputCache] = $this->runCommand('config:cache');
        $this->assertSame(0, $exitCache);

        $cachedFile = self::$tempProject . '/storage/framework/config.php';
        $this->assertFileExists($cachedFile, 'config cache file should exist');

        [$exitClear, $outputClear] = $this->runCommand('config:clear');
        $this->assertSame(0, $exitClear);
        $this->assertStringContainsString('cleared', $outputClear);

        $this->assertFileDoesNotExist($cachedFile, 'config cache file should be removed after clear');
    }
}

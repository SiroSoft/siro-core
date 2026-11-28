<?php

declare(strict_types=1);

namespace Siro\Core\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Siro\Core\Console;

final class ConsoleTest extends TestCase
{
    private Console $console;

    protected function setUp(): void
    {
        $this->console = new Console(dirname(__DIR__, 2));
        ob_start();
    }

    protected function tearDown(): void
    {
        ob_end_clean();
    }

    private function runCommand(string $command, array $extra = []): int
    {
        $args = array_merge(['siro', $command], $extra);
        return $this->console->run($args);
    }

    public function testCanBeInstantiated(): void
    {
        $this->assertInstanceOf(Console::class, $this->console);
    }

    public function testRunWithVersionReturnsZero(): void
    {
        $this->assertEquals(0, $this->runCommand('--version'));
    }

    public function testRunWithHelpReturnsZero(): void
    {
        $this->assertEquals(0, $this->runCommand('--help'));
    }

    public function testRunWithListReturnsZero(): void
    {
        $this->assertEquals(0, $this->runCommand('list'));
    }

    public function testRunWithStartReturnsZero(): void
    {
        $this->assertEquals(0, $this->runCommand('start'));
    }

    public function testEmptyArgsReturnsZero(): void
    {
        $this->assertEquals(0, $this->console->run(['siro']));
    }

    public function testUnknownCommandReturnsOne(): void
    {
        $this->assertEquals(1, $this->runCommand('nonexistent_command_xyz'));
    }

    public function testHelpForMakeAuth(): void
    {
        $this->assertEquals(0, $this->runCommand('make:auth', ['--help']));
    }

    public function testHelpForMakeCrud(): void
    {
        $this->assertEquals(0, $this->runCommand('make:crud', ['--help']));
    }

    public function testHelpForMakeModel(): void
    {
        $this->assertEquals(0, $this->runCommand('make:model', ['--help']));
    }

    public function testHelpForMigrate(): void
    {
        $this->assertEquals(0, $this->runCommand('migrate', ['--help']));
    }

    public function testHelpForServe(): void
    {
        $this->assertEquals(0, $this->runCommand('serve', ['--help']));
    }

    public function testHelpForTest(): void
    {
        $this->assertEquals(0, $this->runCommand('test', ['--help']));
    }

    public function testHelpForDoctor(): void
    {
        $this->assertEquals(0, $this->runCommand('doctor', ['--help']));
    }

    public function testHelpForBenchmark(): void
    {
        $this->assertEquals(0, $this->runCommand('benchmark', ['--help']));
    }

    public function testHelpForRouteList(): void
    {
        $this->assertEquals(0, $this->runCommand('route:list', ['--help']));
    }

    public function testAliasSlow(): void
    {
        $this->assertEquals(0, $this->runCommand('slow', ['--help']));
    }

    public function testAliasWhy(): void
    {
        $this->assertEquals(0, $this->runCommand('why', ['--help']));
    }

    public function testAliasTraces(): void
    {
        $this->assertEquals(0, $this->runCommand('traces', ['--help']));
    }

    public function testMakeDocsAlias(): void
    {
        $this->assertEquals(0, $this->runCommand('make:docs', ['--help']));
    }

    public function testShortHelp(): void
    {
        $this->assertEquals(0, $this->runCommand('make:crud', ['-h']));
    }

    public function testAllMakeCommandsHaveHelp(): void
    {
        $cmds = ['make:auth', 'make:controller', 'make:model', 'make:migration',
            'make:crud', 'make:test', 'make:job', 'make:openapi', 'make:postman'];
        foreach ($cmds as $cmd) {
            $this->assertEquals(0, $this->runCommand($cmd, ['--help']), "$cmd --help failed");
        }
    }

    public function testAllDbCommandsHaveHelp(): void
    {
        foreach (['migrate', 'db:seed', 'db:show'] as $cmd) {
            $this->assertEquals(0, $this->runCommand($cmd, ['--help']), "$cmd --help failed");
        }
    }

    public function testAllLogCommandsHaveHelp(): void
    {
        foreach (['log:trace', 'log:replay', 'log:slow', 'log:stats', 'debug:last'] as $cmd) {
            $this->assertEquals(0, $this->runCommand($cmd, ['--help']), "$cmd --help failed");
        }
    }

    public function testListRaw(): void
    {
        $this->assertEquals(0, $this->runCommand('list', ['--raw']));
    }

    public function testListJson(): void
    {
        $this->assertEquals(0, $this->runCommand('list', ['--json']));
    }

    public function testListPlain(): void
    {
        $this->assertEquals(0, $this->runCommand('list'));
    }

    public function testOpenPostmanAlias(): void
    {
        $code = $this->console->run(['siro', 'open:postman']);
        $this->assertContains($code, [0, 1]);
    }

    public function testTinkerShortcut(): void
    {
        $code = $this->console->run(['siro', 'tink']);
        $this->assertContains($code, [0, 1]);
    }

    /**
     * v1.4.1 (item 5): `new Console('')` used to poison every file-based
     * command — route:rules looked for "/routes/api.php" and exited 1
     * (the pre-existing skeleton testRouteRules failure). An empty
     * basePath now resolves from SIRO_BASE_PATH (env) first.
     */
    public function testEmptyBasePathResolvesFromSiroBasePathEnv(): void
    {
        $tmp = sys_get_temp_dir() . '/siro_console_base_' . uniqid('', true);
        mkdir($tmp . '/routes', 0777, true);
        file_put_contents(
            $tmp . '/routes/api.php',
            "<?php\n\$router->get('/products', fn () => ['ok' => true]);\n"
        );
        $previous = getenv('SIRO_BASE_PATH');
        putenv('SIRO_BASE_PATH=' . $tmp);
        try {
            $console = new Console('');
            ob_start();
            try {
                $exit = $console->run(['siro', 'route:rules']);
            } finally {
                ob_end_clean();
            }
            $this->assertSame(0, $exit);
        } finally {
            if ($previous === false) {
                putenv('SIRO_BASE_PATH');
            } else {
                putenv('SIRO_BASE_PATH=' . $previous);
            }
            @unlink($tmp . '/routes/api.php');
            @rmdir($tmp . '/routes');
            @rmdir($tmp);
        }
    }

    /**
     * With no SIRO_BASE_PATH anywhere, an empty basePath falls back to
     * the working directory (same convention as Response/Storage/App).
     */
    public function testEmptyBasePathFallsBackToWorkingDirectory(): void
    {
        $previous = getenv('SIRO_BASE_PATH');
        putenv('SIRO_BASE_PATH');
        try {
            $console = new Console('');
            $ref = new \ReflectionProperty(Console::class, 'basePath');
            $this->assertSame((string) getcwd(), $ref->getValue($console));
        } finally {
            if ($previous === false) {
                putenv('SIRO_BASE_PATH');
            } else {
                putenv('SIRO_BASE_PATH=' . $previous);
            }
        }
    }
}

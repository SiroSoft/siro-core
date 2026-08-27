<?php

declare(strict_types=1);

namespace Siro\Core\Tests\Cli;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Siro\Core\Console;

final class MakeCommandsTest extends TestCase
{
    private Console $console;
    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/siro_make_test_' . bin2hex(random_bytes(4));
        mkdir($this->tempDir, 0777, true);

        foreach ([
            'app/Controllers', 'app/Models', 'app/Services', 'app/Repositories',
            'app/Middleware', 'app/Exceptions', 'app/Events', 'app/Jobs',
            'app/Mails', 'app/Listeners', 'app/Resources',
            'database/migrations', 'database/seeds', 'database/factories',
            'tests/Feature', 'tests/Unit', 'resources/lang/en',
            'storage/lang/en', 'storage/framework', 'routes', 'config',
            'docs', 'public',
        ] as $dir) {
            mkdir($this->tempDir . '/' . $dir, 0777, true);
        }

        file_put_contents($this->tempDir . '/config/database.php',
            '<?php return [\'driver\' => \'sqlite\', \'database\' => \':memory:\', \'slow_query_threshold\' => 500];');
        file_put_contents($this->tempDir . '/routes/api.php',
            "<?php\n\ndeclare(strict_types=1);\n\$app->router->get('/api/health', function () { return ['success' => true]; });\n");
        file_put_contents($this->tempDir . '/.env',
            "APP_ENV=testing\nAPP_DEBUG=true\nAPP_KEY=testing_app_key_for_hmac_32chars!!\nJWT_SECRET=test_jwt_secret_key_for_unit_tests_32chars!\n");

        putenv('SIRO_BASE_PATH=' . $this->tempDir);
        $this->console = new Console($this->tempDir);
    }

    protected function tearDown(): void
    {
        $this->rrmdir($this->tempDir);
    }

    private function runSilent(array $argv): int
    {
        ob_start();
        $code = $this->console->run($argv);
        ob_end_clean();
        return $code;
    }

    // ==================== COMMAND THAT PRODUCE FILES IN PREDICTABLE LOCATIONS ====================

    public function testMakeAuth(): void
    {
        $code = $this->runSilent(['siro', 'make:auth']);
        $this->assertEquals(0, $code, 'make:auth should exit 0');

        foreach ([
            '/app/Controllers/AuthController.php',
            '/app/Models/User.php',
            '/app/Services/UserService.php',
        ] as $rel) {
            $f = $this->tempDir . $rel;
            $this->assertFileExists($f, "Missing $rel");
            $this->assertValidPhp($f);
        }
        $migrations = glob($this->tempDir . '/database/migrations/*.php');
        $this->assertGreaterThanOrEqual(2, count($migrations), 'Expected >=2 migration files');
    }

    public function testMakeController(): void
    {
        $code = $this->runSilent(['siro', 'make:controller', 'TestClCtrl']);
        $this->assertEquals(0, $code);
        $f = $this->tempDir . '/app/Controllers/TestClCtrlController.php';
        $this->assertFileExists($f);
        $this->assertValidPhp($f);
        $this->assertStringContainsString('class TestClCtrlController', file_get_contents($f));
    }

    public function testMakeModel(): void
    {
        $code = $this->runSilent(['siro', 'make:model', 'TestMd']);
        $this->assertEquals(0, $code);
        $f = $this->tempDir . '/app/Models/TestMd.php';
        $this->assertFileExists($f);
        $this->assertValidPhp($f);
        $content = file_get_contents($f);
        $this->assertStringContainsString('class TestMd extends Model', $content);
    }

    public function testMakeMigration(): void
    {
        $code = $this->runSilent(['siro', 'make:migration', 'create_mig_test_table']);
        $this->assertEquals(0, $code);
        $files = glob($this->tempDir . '/database/migrations/*create_mig_test_table*');
        $this->assertCount(1, $files);
        $this->assertValidPhp($files[0]);
    }

    public function testMakeQueueTable(): void
    {
        $code = $this->runSilent(['siro', 'make:queue-table']);
        $this->assertEquals(0, $code);
        $files = glob($this->tempDir . '/database/migrations/*create_jobs_table*');
        $this->assertCount(1, $files);
        $this->assertValidPhp($files[0]);
    }

    public function testMakeResource(): void
    {
        $code = $this->runSilent(['siro', 'make:resource', 'TestRsr']);
        $this->assertEquals(0, $code);
        $f = $this->tempDir . '/app/Resources/TestRsrResource.php';
        $this->assertFileExists($f);
        $this->assertValidPhp($f);
        $this->assertStringContainsString('class TestRsrResource extends Resource', file_get_contents($f));
    }

    public function testMakeSeeder(): void
    {
        $code = $this->runSilent(['siro', 'make:seeder', 'TestSdr']);
        $this->assertEquals(0, $code);
        $f = $this->tempDir . '/database/seeds/TestSdrSeeder.php';
        $this->assertFileExists($f);
        $this->assertValidPhp($f);
    }

    public function testMakeCrudSimple(): void
    {
        $code = $this->runSilent(['siro', 'make:crud', 'TestCrd', '--simple', '--force']);
        $this->assertEquals(0, $code);

        $this->assertFileExists($this->tempDir . '/app/Models/TestCrd.php');
        $this->assertValidPhp($this->tempDir . '/app/Models/TestCrd.php');
        $this->assertFileExists($this->tempDir . '/app/Controllers/TestCrdController.php');
        $this->assertValidPhp($this->tempDir . '/app/Controllers/TestCrdController.php');

        $this->assertFileDoesNotExist($this->tempDir . '/app/Services/TestCrdService.php');
        $this->assertFileDoesNotExist($this->tempDir . '/app/Repositories/TestCrdRepository.php');
        $this->assertFileDoesNotExist($this->tempDir . '/app/Resources/TestCrdResource.php');
    }

    public function testMakeTest(): void
    {
        $code = $this->runSilent(['siro', 'make:test', 'TestUT']);
        $this->assertEquals(0, $code);
        $f = $this->tempDir . '/tests/Feature/TestUTTest.php';
        $this->assertFileExists($f);
        $this->assertValidPhp($f);
        $this->assertStringContainsString('class TestUTTest', file_get_contents($f));
    }

    public function testMakeJob(): void
    {
        $code = $this->runSilent(['siro', 'make:job', 'TestJb']);
        $this->assertEquals(0, $code);
        $f = $this->tempDir . '/app/Jobs/TestJbJob.php';
        $this->assertFileExists($f);
        $this->assertValidPhp($f);
        $this->assertStringContainsString('class TestJbJob', file_get_contents($f));
    }

    public function testMakeMail(): void
    {
        $code = $this->runSilent(['siro', 'make:mail', 'TestMl']);
        $this->assertEquals(0, $code);
        $f = $this->tempDir . '/app/Mails/TestMlMail.php';
        $this->assertFileExists($f);
        $this->assertValidPhp($f);
        $this->assertStringContainsString('class TestMlMail', file_get_contents($f));
    }

    public function testMakeEvent(): void
    {
        $code = $this->runSilent(['siro', 'make:event', 'TestEv']);
        $this->assertEquals(0, $code);
        $f = $this->tempDir . '/app/Events/TestEvEvent.php';
        $this->assertFileExists($f);
        $this->assertValidPhp($f);
        $this->assertStringContainsString('class TestEvEvent', file_get_contents($f));
    }

    public function testMakeLang(): void
    {
        $code = $this->runSilent(['siro', 'make:lang', 'en', 'test_lang']);
        $this->assertEquals(0, $code);
        $f = $this->tempDir . '/storage/lang/en/test_lang.php';
        $this->assertFileExists($f);
        $this->assertValidPhp($f);
        $this->assertStringContainsString('return [', file_get_contents($f));
    }

    public function testMakeFactory(): void
    {
        $code = $this->runSilent(['siro', 'make:factory', 'TestFct']);
        $this->assertEquals(0, $code);
        $f = $this->tempDir . '/database/factories/TestFctFactory.php';
        $this->assertFileExists($f);
        $this->assertValidPhp($f);
        $this->assertStringContainsString('class TestFctFactory', file_get_contents($f));
    }

    public function testMakeOpenApi(): void
    {
        $code = $this->runSilent(['siro', 'make:openapi', '--flow=auth']);
        $this->assertEquals(0, $code);

        // command writes to dirname(basePath/docs/openapi) => docs/openapi.json
        $f = $this->tempDir . '/docs/openapi.json';
        $this->assertFileExists($f);
        $this->assertJson(file_get_contents($f));
    }

    public function testMakePostman(): void
    {
        $code = $this->runSilent(['siro', 'make:postman', '--flow=crud']);
        $this->assertEquals(0, $code);

        $f = $this->tempDir . '/docs/postman/collection.json';
        $this->assertFileExists($f);
        $this->assertJson(file_get_contents($f));
    }

    public function testMakeService(): void
    {
        $code = $this->runSilent(['siro', 'make:service', 'TestSvc']);
        $this->assertEquals(0, $code);
        $f = $this->tempDir . '/app/Services/TestSvcService.php';
        $this->assertFileExists($f);
        $this->assertValidPhp($f);
        $this->assertStringContainsString('class TestSvcService', file_get_contents($f));
    }

    public function testMakeRepository(): void
    {
        $code = $this->runSilent(['siro', 'make:repository', 'TestRep']);
        $this->assertEquals(0, $code);
        $f = $this->tempDir . '/app/Repositories/TestRepRepository.php';
        $this->assertFileExists($f);
        $this->assertValidPhp($f);
        $this->assertStringContainsString('class TestRepRepository', file_get_contents($f));
    }

    public function testMakeMiddleware(): void
    {
        $code = $this->runSilent(['siro', 'make:middleware', 'TestMdw']);
        $this->assertEquals(0, $code);
        $f = $this->tempDir . '/app/Middleware/TestMdwMiddleware.php';
        $this->assertFileExists($f);
        $this->assertValidPhp($f);
        $this->assertStringContainsString('class TestMdwMiddleware', file_get_contents($f));
    }

    public function testMakeListener(): void
    {
        $code = $this->runSilent(['siro', 'make:listener', 'TestLst']);
        $this->assertEquals(0, $code);
        $f = $this->tempDir . '/app/Listeners/TestLstListener.php';
        $this->assertFileExists($f);
        $this->assertValidPhp($f);
        $this->assertStringContainsString('class TestLstListener', file_get_contents($f));
    }

    // ==================== DB-DEPENDENT COMMANDS ====================

    public function testMakeIdempotencyTable(): void
    {
        $handler = new \Siro\Core\Commands\MakeIdempotencyTableCommand($this->tempDir);
        ob_start();
        $code = $handler->run([]);
        ob_end_clean();
        $this->assertIsInt($code);
    }

    public function testMakeApikeyTable(): void
    {
        $handler = new \Siro\Core\Commands\MakeApiKeysTableCommand($this->tempDir);
        ob_start();
        $code = $handler->run([]);
        ob_end_clean();
        $this->assertIsInt($code);
    }

    public function testMakeApikey(): void
    {
        $handler = new \Siro\Core\Commands\MakeApiKeyCommand($this->tempDir);
        ob_start();
        $code = $handler->run(['TestKey', 'read,write']);
        ob_end_clean();
        $this->assertIsInt($code);
    }

    // ==================== DATA PROVIDER ====================

    /** @return array<string, array{0: string, 1: list<string>, 2: string}> */
    public static function makeCommandProvider(): array
    {
        return [
            'make:controller'     => ['make:controller',     ['TestDpCtrl'],         'app/Controllers/TestDpCtrlController.php'],
            'make:model'          => ['make:model',          ['TestDpModel'],        'app/Models/TestDpModel.php'],
            'make:migration'      => ['make:migration',      ['create_dp_test_tbl'], 'database/migrations/*dp_test_tbl*'],
            'make:resource'       => ['make:resource',       ['TestDpRes'],          'app/Resources/TestDpResResource.php'],
            'make:seeder'         => ['make:seeder',         ['TestDpSeed'],         'database/seeds/TestDpSeedSeeder.php'],
            'make:test'           => ['make:test',           ['TestDpTest'],         'tests/Feature/TestDpTestTest.php'],
            'make:job'            => ['make:job',            ['TestDpJobX'],         'app/Jobs/TestDpJobXJob.php'],
            'make:mail'           => ['make:mail',           ['TestDpMailX'],        'app/Mails/TestDpMailXMail.php'],
            'make:event'          => ['make:event',          ['TestDpEventX'],       'app/Events/TestDpEventXEvent.php'],
            'make:lang'           => ['make:lang',           ['en', 'dp_lang'],      'storage/lang/en/dp_lang.php'],
            'make:factory'        => ['make:factory',        ['TestDpFct'],          'database/factories/TestDpFctFactory.php'],
            'make:service'        => ['make:service',        ['TestDpSvc'],          'app/Services/TestDpSvcService.php'],
            'make:repository'     => ['make:repository',     ['TestDpRep'],          'app/Repositories/TestDpRepRepository.php'],
            'make:middleware'     => ['make:middleware',     ['TestDpMdw'],          'app/Middleware/TestDpMdwMiddleware.php'],
            'make:listener'       => ['make:listener',       ['TestDpLst'],          'app/Listeners/TestDpLstListener.php'],
        ];
    }

    /**
     * @param list<string> $args
     */
    #[DataProvider('makeCommandProvider')]
    public function testMakeCommand(string $command, array $args, string $expectedPath): void
    {
        $argv = array_merge(['siro', $command], $args);
        $code = $this->runSilent($argv);
        $this->assertEquals(0, $code, "{$command} should exit 0");

        $fullPath = $this->tempDir . '/' . $expectedPath;
        if (str_contains($expectedPath, '*')) {
            $matches = glob($fullPath);
            $this->assertGreaterThan(0, count($matches), "No file matching {$expectedPath}");
            foreach ($matches as $f) {
                $this->assertValidPhp($f);
            }
        } else {
            $this->assertFileExists($fullPath, "{$command} did not create {$expectedPath}");
            $this->assertValidPhp($fullPath);
        }
    }

    // ==================== HELPERS ====================

    private function assertValidPhp(string $file): void
    {
        $output = shell_exec('php -l ' . escapeshellarg($file) . ' 2>&1');
        $this->assertNotNull($output, "php -l returned null");
        $this->assertStringContainsString('No syntax errors', $output, "PHP lint failed: {$output}");
    }

    private function rrmdir(string $dir): void
    {
        if (!is_dir($dir)) return;
        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($files as $file) {
            if ($file->isDir()) rmdir($file->getRealPath());
            else unlink($file->getRealPath());
        }
        rmdir($dir);
    }
}

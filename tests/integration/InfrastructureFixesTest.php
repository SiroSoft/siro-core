<?php

declare(strict_types=1);

namespace Siro\Core\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Siro\Core\Console;

final class InfrastructureFixesTest extends TestCase
{
    private string $siroCorePath;
    private string $siroSoftPath;
    private string $siroPhpPath;
    private string $demoPath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->siroCorePath = dirname(__DIR__, 2);
        $this->siroSoftPath = dirname(__DIR__, 3);
        $this->siroPhpPath = $this->siroSoftPath . '/SiroPHP';
        $this->demoPath = $this->siroSoftPath . '/demo-v1.0';
    }

    public function testConsoleVersionIs0231(): void
    {
        $this->assertSame('0.23.1', Console::VERSION);
    }

    public function testDockerfileExists(): void
    {
        $this->assertFileExists($this->siroPhpPath . '/Dockerfile');
    }

    public function testDockerfileIsValid(): void
    {
        $content = file_get_contents($this->siroPhpPath . '/Dockerfile');
        $this->assertIsString($content);
        $this->assertStringContainsString('FROM php:8.2-cli-alpine', $content);
        $this->assertStringContainsString('EXPOSE 8080', $content);
        $this->assertStringContainsString('CMD', $content);
    }

    public function testDockerfileDevExists(): void
    {
        $this->assertFileExists($this->siroPhpPath . '/Dockerfile.dev');
    }

    public function testDockerfileDevIsValid(): void
    {
        $content = file_get_contents($this->siroPhpPath . '/Dockerfile.dev');
        $this->assertIsString($content);
        $this->assertStringContainsString('FROM php:8.2-cli-alpine', $content);
        $this->assertStringContainsString('composer install', $content);
        $this->assertStringContainsString('php -S 0.0.0.0:8080 -t public', $content);
    }

    public function testDemoV1HasCorrectStructure(): void
    {
        $this->assertFileExists($this->demoPath . '/composer.json');
        $this->assertFileExists($this->demoPath . '/public/index.php');
        $this->assertFileExists($this->demoPath . '/config/app.php');
        $this->assertFileExists($this->demoPath . '/config/database.php');
    }

    public function testDemoV1ComposerJson(): void
    {
        $composer = json_decode(
            (string) file_get_contents($this->demoPath . '/composer.json'),
            true
        );
        $this->assertIsArray($composer);
        $this->assertSame('sirosoft/demo', $composer['name']);
        $this->assertArrayHasKey('sirosoft/core', $composer['require']);
        $this->assertSame('^0.23.1', $composer['require']['sirosoft/core']);
    }

    public function testDemoV1PublicIndexHasExpectedRoutes(): void
    {
        $content = (string) file_get_contents($this->demoPath . '/public/index.php');

        $this->assertStringContainsString("Router::get('/',", $content);
        $this->assertStringContainsString("Router::get('/api/benchmark',", $content);
        $this->assertStringContainsString("Router::get('/api/hello/{name}',", $content);
        $this->assertStringContainsString("Router::get('/api/security/headers',", $content);

        $routeCount = preg_match_all("/Router::get\(/", $content);
        $this->assertSame(4, $routeCount, 'Expected exactly 4 route definitions');
    }

    public function testMiddlewareDuplicatesRemovedFromApp(): void
    {
        $this->assertFileDoesNotExist($this->siroPhpPath . '/app/Middleware/ThrottleMiddleware.php');
        $this->assertFileDoesNotExist($this->siroPhpPath . '/app/Middleware/CorsMiddleware.php');
    }

    public function testCoreMiddlewareStillExist(): void
    {
        $this->assertFileExists($this->siroCorePath . '/Middleware/ThrottleMiddleware.php');
        $this->assertFileExists($this->siroCorePath . '/Middleware/CorsMiddleware.php');
    }

    public function testIndexUsesCoreMiddlewareAliases(): void
    {
        $content = (string) file_get_contents($this->siroPhpPath . '/public/index.php');

        $this->assertStringContainsString(
            '\'throttle\' => \Siro\Core\Middleware\ThrottleMiddleware::class',
            $content
        );
        $this->assertStringContainsString(
            '\'cors\' => \Siro\Core\Middleware\CorsMiddleware::class',
            $content
        );
        $this->assertStringContainsString(
            '\'json\' => \Siro\Core\Middleware\JsonMiddleware::class',
            $content
        );
    }

    public function testAppMiddlewareDirectoryHasNoDuplicates(): void
    {
        $files = scandir($this->siroPhpPath . '/app/Middleware');
        $this->assertIsArray($files);
        $files = array_values(array_filter($files, fn (string $f): bool => !in_array($f, ['.', '..'], true)));

        $expected = ['AuthMiddleware.php', 'JsonMiddleware.php', 'SecurityHeadersMiddleware.php'];
        sort($files);
        sort($expected);

        $this->assertSame($expected, $files);
    }

    public function testDemoV1ConfigFilesAreValidPhp(): void
    {
        $appContent = (string) file_get_contents($this->demoPath . '/config/app.php');
        $this->assertStringContainsString('return [', $appContent);
        $this->assertStringContainsString("'name'", $appContent);
        $this->assertStringContainsString("'env'", $appContent);
        $this->assertStringContainsString("'debug'", $appContent);

        $dbContent = (string) file_get_contents($this->demoPath . '/config/database.php');
        $this->assertStringContainsString('return [', $dbContent);
        $this->assertStringContainsString("'driver'", $dbContent);
        $this->assertStringContainsString("'database'", $dbContent);
    }
}

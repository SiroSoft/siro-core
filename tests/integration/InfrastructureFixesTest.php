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

    protected function setUp(): void
    {
        parent::setUp();
        $this->siroCorePath = dirname(__DIR__, 2);
        $this->siroSoftPath = dirname(__DIR__, 3);
        $this->siroPhpPath = $this->siroSoftPath . '/SiroPHP';
    }

    private function requireSkeleton(): void
    {
        if (!is_dir($this->siroPhpPath)) {
            $this->markTestSkipped('SiroPHP skeleton directory not present — skipping skeleton-structure tests');
        }
    }

    public function testConsoleVersionIsCurrent(): void
    {
        $this->assertNotEmpty(Console::VERSION);
    }

    public function testDockerfileExists(): void
    {
        $this->requireSkeleton();
        $this->assertFileExists($this->siroPhpPath . '/Dockerfile');
    }

    public function testDockerfileIsValid(): void
    {
        $this->requireSkeleton();
        $content = file_get_contents($this->siroPhpPath . '/Dockerfile');
        $this->assertIsString($content);
        $this->assertStringContainsString('FROM dunglas/frankenphp:', $content);
        $this->assertStringContainsString('EXPOSE 80', $content);
        $this->assertStringContainsString('CMD', $content);
    }

    public function testDockerfileDevExists(): void
    {
        $this->markTestSkipped('Dockerfile.dev is no longer maintained; use docker/Dockerfile.frankenphp instead');
    }

    public function testDockerfileDevIsValid(): void
    {
        $this->markTestSkipped('Dockerfile.dev is no longer maintained; use docker/Dockerfile.frankenphp instead');
    }

    public function testMiddlewareDuplicatesRemovedFromApp(): void
    {
        $this->requireSkeleton();
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
        $this->requireSkeleton();
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
        $this->requireSkeleton();
        $files = scandir($this->siroPhpPath . '/app/Middleware');
        $this->assertIsArray($files);
        $files = array_values(array_filter($files, fn (string $f): bool => !in_array($f, ['.', '..'], true)));

        $expected = ['AuthMiddleware.php', 'SecurityHeadersMiddleware.php'];
        sort($files);
        sort($expected);

        $this->assertSame($expected, $files);
    }

}

<?php

declare(strict_types=1);

namespace Siro\Core\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Siro\Core\App;
use Siro\Core\Env;
use Siro\Core\Middleware\AuthMiddleware;
use Siro\Core\Request;
use Siro\Core\Response;
use RuntimeException;

final class ArchitectureFixesTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Env::reset();
    }

    public function testSoftDeletesUsesEventEmitNotEventDispatch(): void
    {
        $reflection = new \ReflectionClass(\Siro\Core\DB\SoftDeletes::class);
        $source = file_get_contents($reflection->getFileName());

        $this->assertStringContainsString('Event::emit', $source);
        $this->assertStringNotContainsString('Event::dispatch', $source);
    }

    public function testAuthMiddlewareAllErrorPathsReturnConsistentErrorMessage(): void
    {
        $middleware = new AuthMiddleware();
        $next = fn (Request $req): Response => Response::success();
        $expectedError = ['token' => ['Invalid or expired token']];

        $testCases = [
            'missing Authorization header' => new Request('GET', '/test'),
            'empty Bearer token'           => new Request('GET', '/test', [], ['authorization' => 'Bearer ']),
            'malformed token string'       => new Request('GET', '/test', [], ['authorization' => 'Bearer invalid-token']),
            'wrong auth scheme (Basic)'    => new Request('GET', '/test', [], ['authorization' => 'Basic dGVzdDp0ZXN0']),
        ];

        foreach ($testCases as $label => $request) {
            $response = $middleware->handle($request, $next);

            $this->assertSame(401, $response->statusCode(), "Status code mismatch: {$label}");

            $payload = $response->payload();
            $this->assertArrayHasKey('meta', $payload, "Payload missing 'meta' key: {$label}");
            $meta = $payload['meta'] ?? [];
            $this->assertIsArray($meta);
            $this->assertArrayHasKey('errors', $meta, "Payload missing 'meta.errors' key: {$label}");
            $this->assertSame($expectedError, $meta['errors'], "Error detail mismatch: {$label}");
        }
    }

    public function testAppValidateSecurityConfigThrowsExceptionForWeakSecret(): void
    {
        $app = new App(__DIR__ . '/../../');
        $reflection = new \ReflectionMethod(App::class, 'validateSecurityConfig');
        $reflection->setAccessible(true);

        $originalEnv = $_ENV['JWT_SECRET'] ?? '';
        $_ENV['JWT_SECRET'] = 'weak';
        putenv('JWT_SECRET=weak');

        try {
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('JWT_SECRET is missing or too weak');

            $reflection->invoke($app);
        } finally {
            if ($originalEnv !== '') {
                $_ENV['JWT_SECRET'] = $originalEnv;
                putenv('JWT_SECRET=' . $originalEnv);
            }
        }
    }

    public function testAppValidateSecurityConfigAcceptsValidSecret(): void
    {
        $app = new App(__DIR__ . '/../../');
        $reflection = new \ReflectionMethod(App::class, 'validateSecurityConfig');
        $reflection->setAccessible(true);

        $this->assertNull($reflection->invoke($app));
    }
}

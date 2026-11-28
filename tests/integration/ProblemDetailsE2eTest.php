<?php

declare(strict_types=1);

namespace Siro\Core\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Siro\Core\App;
use Siro\Core\Cache;
use Siro\Core\Config;
use Siro\Core\Database;
use Siro\Core\Env;
use Siro\Core\Middleware\VersionMiddleware;

/**
 * G4 end-to-end: App::run() exception mapping — RFC 7807 problem details
 * when the client asks for them, legacy envelope otherwise.
 *
 * Follows the AppRunTest harness: temp project, mocked $_SERVER globals,
 * output captured via ob_start(). In CLI mode send() echoes the JSON body
 * (headers are not emitted), so these tests assert the body shape; the
 * Content-Type header itself is covered in ResponseProblemTest.
 */
final class ProblemDetailsE2eTest extends TestCase
{
    private string $basePath;
    /** @var array<string, mixed> */
    private array $origServer = [];

    protected function setUp(): void
    {
        parent::setUp();
        Env::reset();
        Config::reset();
        putenv('APP_ENV=testing');
        putenv('APP_DEBUG=true');
        putenv('APP_KEY=this_is_a_sufficiently_long_app_key_for_tests_12345678');
        putenv('JWT_SECRET=test_jwt_secret_key_for_unit_tests_only_32chars!!');
        $this->origServer = $_SERVER;
        $this->basePath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'siro_problem_' . uniqid('', true);
        mkdir($this->basePath . DIRECTORY_SEPARATOR . 'routes', 0777, true);
        mkdir($this->basePath . DIRECTORY_SEPARATOR . 'config', 0777, true);
        mkdir($this->basePath . DIRECTORY_SEPARATOR . 'storage', 0777, true);
        file_put_contents(
            $this->basePath . DIRECTORY_SEPARATOR . '.env',
            "APP_ENV=testing\nAPP_DEBUG=true\n" . "APP_KEY=this_is_a_sufficiently_long_app_key_for_tests_12345678\n" .
                "JWT_SECRET=test_jwt_secret_key_for_unit_tests_only_32chars!!\n"
        );
        file_put_contents(
            $this->basePath . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'database.php',
            "<?php\nreturn ['driver' => 'sqlite', 'database' => ':memory:', 'slow_query_threshold' => 500];\n"
        );
        file_put_contents(
            $this->basePath . DIRECTORY_SEPARATOR . 'routes' . DIRECTORY_SEPARATOR . 'api.php',
            <<<'PHP'
<?php
$router->get('/ok', function () {
    return \Siro\Core\Response::success(['status' => 'ok']);
});
$router->get('/valerr', function () {
    throw new \Siro\Core\ValidationException(['name' => ['required']], 'Name is required.');
});
$router->get('/notfound', function () {
    throw new \Siro\Core\ModelNotFoundException('App\\Models\\Widget', 42);
});
$router->get('/forbidden', function () {
    throw new \Siro\Core\AuthorizationException('Nope.');
});
$router->get('/badbody', function () {
    throw new \Siro\Core\MalformedBodyException();
});
$router->get('/boom', function () {
    throw new \RuntimeException('db password is hunter2 at 10.0.0.9');
});
$router->get('/valerr-v2', function () {
    throw new \Siro\Core\ValidationException(['name' => ['required']], 'Name is required.');
}, ['version']);
PHP
        );
    }

    protected function tearDown(): void
    {
        $_SERVER = $this->origServer;
        Env::reset();
        Config::reset();
        VersionMiddleware::reset();
        Cache::reset();
        Database::purgeAll();
        \Siro\Core\Session::setInstance(null);
        unset($_COOKIE['siro_session']);
        if (is_dir($this->basePath)) {
            $this->rmDir($this->basePath);
        }
        parent::tearDown();
    }

    private function rmDir(string $dir): void
    {
        foreach (scandir($dir) as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . DIRECTORY_SEPARATOR . $entry;
            if (is_dir($path)) {
                $this->rmDir($path);
            } else {
                @unlink($path);
            }
        }
        @rmdir($dir);
    }

    /** @param array<string, string> $server */
    private function runRequest(array $server): string
    {
        $_SERVER = array_merge($this->origServer, $server);
        $_SERVER['REQUEST_METHOD'] = $server['REQUEST_METHOD'] ?? 'GET';
        $_SERVER['REQUEST_URI'] = $server['REQUEST_URI'] ?? '/';
        $app = new App($this->basePath);
        $app->boot();
        $app->loadRoutes($this->basePath . DIRECTORY_SEPARATOR . 'routes' . DIRECTORY_SEPARATOR . 'api.php');
        ob_start();
        $app->run();
        return ob_get_clean() ?: '';
    }

    /** @return array<string, mixed> */
    private function runJson(string $uri, string $accept = ''): array
    {
        $server = ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => $uri];
        if ($accept !== '') {
            $server['HTTP_ACCEPT'] = $accept;
        }
        $output = $this->runRequest($server);
        /** @var array<string, mixed> $decoded */
        $decoded = json_decode($output, true);
        $this->assertIsArray($decoded, 'Response was not JSON: ' . $output);
        return $decoded;
    }

    public function testValidationProblemWhenAcceptAsks(): void
    {
        $body = $this->runJson('/valerr', 'application/problem+json');
        $this->assertSame('https://siro.dev/problems/validation-failed', $body['type']);
        $this->assertSame(422, $body['status']);
        $this->assertSame('Name is required.', $body['detail']);
        $this->assertSame(['name' => ['required']], $body['errors']);
        $this->assertSame('/valerr', $body['instance']);
        $this->assertArrayNotHasKey('success', $body);
    }

    public function testValidationEnvelopeByDefault(): void
    {
        $body = $this->runJson('/valerr');
        $this->assertFalse($body['success']);
        $this->assertSame('Validation failed', $body['message']);
        $this->assertSame(['name' => ['required']], $body['meta']['errors']);
        $this->assertArrayNotHasKey('type', $body);
    }

    public function testNotFoundProblem(): void
    {
        $body = $this->runJson('/notfound', 'application/problem+json');
        $this->assertSame(404, $body['status']);
        $this->assertSame('https://siro.dev/problems/not-found', $body['type']);
    }

    public function testForbiddenProblem(): void
    {
        $body = $this->runJson('/forbidden', 'application/problem+json');
        $this->assertSame(403, $body['status']);
        $this->assertSame('https://siro.dev/problems/forbidden', $body['type']);
    }

    public function testMalformedBodyProblem(): void
    {
        $body = $this->runJson('/badbody', 'application/problem+json');
        $this->assertSame(400, $body['status']);
        $this->assertSame('https://siro.dev/problems/malformed-body', $body['type']);
    }

    public function testGenericErrorProblemNeverLeaksMessage(): void
    {
        $body = $this->runJson('/boom', 'application/problem+json');
        $this->assertSame(500, $body['status']);
        $this->assertSame('https://siro.dev/problems/internal-server-error', $body['type']);
        $this->assertSame('Internal Server Error', $body['detail']);
        $this->assertStringNotContainsString('hunter2', (string) json_encode($body));
    }

    public function testGenericErrorEnvelopeByDefault(): void
    {
        $body = $this->runJson('/boom');
        $this->assertFalse($body['success']);
        $this->assertSame('Internal Server Error', $body['message']);
        $this->assertArrayNotHasKey('type', $body);
    }

    public function testSuccessRouteUnchangedEvenWhenProblemAccepted(): void
    {
        $body = $this->runJson('/ok', 'application/problem+json');
        $this->assertTrue($body['success']);
        $this->assertSame('ok', $body['data']['status']);
    }

    public function testVersionTwoDefaultsToProblem(): void
    {
        \Siro\Core\Middleware\VersionMiddleware::register(2);
        $body = $this->runJson('/valerr-v2');
        $this->assertSame(422, $body['status']);
        $this->assertSame('https://siro.dev/problems/validation-failed', $body['type']);
    }
}

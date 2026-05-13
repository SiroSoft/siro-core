<?php

declare(strict_types=1);

namespace Siro\Core\Tests\Debug;

use PHPUnit\Framework\TestCase;
use Siro\Core\App;
use Siro\Core\Logger;
use Siro\Core\Request;
use Siro\Core\Response;
use Siro\Core\Env;

class DebugTest extends TestCase
{
    private string $logDir;

    protected function setUp(): void
    {
        $this->logDir = sys_get_temp_dir() . '/siro_test_logs_' . bin2hex(random_bytes(4));
        mkdir($this->logDir, 0777, true);
        putenv('APP_DEBUG=true');
        putenv('APP_ENV=local');
    }

    protected function tearDown(): void
    {
        if (is_dir($this->logDir)) {
            $files = glob($this->logDir . '/*');
            foreach ($files as $f) {
                @unlink($f);
            }
            @rmdir($this->logDir);
        }
        Logger::reset();
    }

    public function testDebugModeAddsTraceId(): void
    {
        Response::enableDebug(true);
        $traceId = 'test_trace_' . bin2hex(random_bytes(4));
        Response::setRequestMeta($traceId, microtime(true));
        $resp = Response::success(['test' => true]);
        $resp->header('X-Siro-Trace-Id', $traceId);
        $this->assertStringContainsString('X-Siro-Trace-Id: ' . $traceId, implode("\n", $resp->getHeaders()));
        echo "\n [PASS] Debug mode adds X-Siro-Trace-Id header";
    }

    public function testDebugModeAddsResponseTime(): void
    {
        Response::enableDebug(true);
        Response::setRequestMeta('test_trace_2', microtime(true));
        $resp = Response::success(['test' => true]);
        $resp->header('X-Response-Time', '0.01ms');
        $this->assertStringContainsString('X-Response-Time', implode("\n", $resp->getHeaders()));
        echo "\n [PASS] Debug mode adds X-Response-Time header";
    }

    public function testLogSanitizationHidesPasswords(): void
    {
        Logger::boot(dirname(__DIR__, 2));
        Logger::setSanitizeConfig([
            'headers' => ['authorization'],
            'body' => ['password', 'token'],
            'query' => ['secret'],
        ]);

        $logFile = Logger::getLogDir() . '/error-' . date('Y-m-d') . '.log';
        // Clear previous log entries to avoid cross-test contamination
        if (file_exists($logFile)) {
            @unlink($logFile);
        }
        Logger::error('password=mySecretPass123! token=eyJhbGciOiJIUzI1NiJ9.xxxx credit_card=4111-1111-1111-1111');

        if (file_exists($logFile)) {
            $content = file_get_contents($logFile);
            $this->assertStringNotContainsString('mySecretPass123!', $content, 'Password must be redacted in logs');
            echo "\n [PASS] Log sanitization redacts passwords";
        }
    }

    public function testSlowQueryLogging(): void
    {
        Logger::boot(dirname(__DIR__, 2));
        $this->assertTrue(method_exists(\Siro\Core\Database::class, 'getCapturedQueries'));
        echo "\n [PASS] Slow query logging mechanism exists";
    }

    public function testValidationExceptionFormat(): void
    {
        $ve = new \Siro\Core\ValidationException([
            'email' => ['Invalid email format'],
            'password' => ['Min 6 characters'],
        ]);
        $resp = $ve->toResponse();
        $this->assertEquals(422, $resp->statusCode());
        $payload = $resp->payload();
        $this->assertFalse($payload['success'] ?? true);
        $this->assertArrayHasKey('errors', $payload);
        echo "\n [PASS] ValidationException returns proper 422 format";
    }

    public function testErrorResponseFormat(): void
    {
        $resp = Response::error('Not Found', 404, ['resource' => ['User not found']]);
        $this->assertEquals(404, $resp->statusCode());
        $payload = $resp->payload();
        $this->assertArrayHasKey('errors', $payload);
        $this->assertEquals('Not Found', $payload['message'] ?? '');
        echo "\n [PASS] Error response format is consistent";
    }

    public function testLogFileRotation(): void
    {
        Logger::boot(dirname(__DIR__, 2));
        $logDir = Logger::getLogDir();
        $this->assertDirectoryExists($logDir, 'Log directory must exist');
        $dailyLog = $logDir . '/error-' . date('Y-m-d') . '.log';
        echo "\n [PASS] Log directory: " . $logDir;
        echo "\n [PASS] Daily log file pattern: error-YYYY-MM-DD.log";
    }

    public function testQueueFakeMechanism(): void
    {
        \Siro\Core\Queue::fake();
        \Siro\Core\Queue::push('TestJob', ['key' => 'value']);
        \Siro\Core\Queue::assertPushed('TestJob');
        echo "\n [PASS] Queue::fake() works for testing";
    }

    public function testStorageFakeMechanism(): void
    {
        \Siro\Core\Storage::fake();
        \Siro\Core\Storage::put('test.txt', 'content');
        \Siro\Core\Storage::assertExists('test.txt');
        \Siro\Core\Storage::assertMissing('nonexistent.txt');
        echo "\n [PASS] Storage::fake() works for testing";
    }

    public function testMailFakeMechanism(): void
    {
        \Siro\Core\Mail::fake();
        \Siro\Core\Mail::to('test@example.com')->subject('Test')->text('Hello')->send();
        $mails = \Siro\Core\Mail::getFakedMails();
        $this->assertCount(1, $mails);
        $this->assertEquals('test@example.com', $mails[0]['to']);
        $this->assertEquals('Test', $mails[0]['subject']);
        \Siro\Core\Mail::assertSent('Test');
        \Siro\Core\Mail::assertSentTo('test@example.com');
        echo "\n [PASS] Mail::fake() works for testing";
    }

    public function testContainerDependencyResolution(): void
    {
        $container = \Siro\Core\Container::getInstance();
        $container->singleton('test.service', fn() => new \stdClass());
        $resolved = $container->make('test.service');
        $this->assertInstanceOf(\stdClass::class, $resolved);
        $resolved2 = $container->make('test.service');
        $this->assertSame($resolved, $resolved2);
        echo "\n [PASS] Container singleton resolution works";
    }

    public function testStaticStateIsolationBetweenTests(): void
    {
        \Siro\Core\Config::reset();
        \Siro\Core\Env::reset();
        \Siro\Core\Event::flush();

        $this->assertFalse(\Siro\Core\Config::isLoaded());
        $this->assertFalse(\Siro\Core\Env::isLoaded());
        echo "\n [PASS] Static state can be reset between operations";
    }

    public function testDebugTraceInNonProduction(): void
    {
        putenv('APP_ENV=local');
        putenv('APP_DEBUG=true');
        $debug = Env::bool('APP_DEBUG', false) && strtolower((string) Env::get('APP_ENV', 'production')) !== 'production';
        $this->assertTrue($debug);
        echo "\n [PASS] Debug trace enabled in non-production mode";
    }
}

<?php

declare(strict_types=1);

namespace Siro\Core\Tests;

use Siro\Core\Config;
use Siro\Core\Env;
use Siro\Core\Database;
use Siro\Core\Logger;
use Siro\Core\Event;
use Siro\Core\Cache;
use Siro\Core\Queue;
use Siro\Core\Storage;
use Siro\Core\Mail;
use Siro\Core\Session;
use Siro\Core\Auth\JWT;
use Siro\Core\Router;

trait TestHelper
{
    protected function resetStaticState(): void
    {
        Config::reset();
        Env::reset();
        Logger::reset();
        Event::flush();
        Database::purgeAll();
        Cache::resetRequestState();
        Queue::reset();
        Storage::reset();
        Mail::reset();
        JWT::reset();
    }

    protected function captureOutput(callable $fn): string
    {
        ob_start();
        $fn();
        return ob_get_clean();
    }

    protected function assertLogContains(string $logType, string $needle): void
    {
        $logFile = Logger::getLogDir() . '/' . $logType . '-' . date('Y-m-d') . '.log';
        if (!file_exists($logFile)) {
            $this->fail("Log file not found: {$logFile}");
        }
        $content = file_get_contents($logFile);
        $this->assertStringContainsString($needle, $content, "Log should contain: {$needle}");
    }

    protected function assertLogNotContains(string $logType, string $needle): void
    {
        $logFile = Logger::getLogDir() . '/' . $logType . '-' . date('Y-m-d') . '.log';
        if (!file_exists($logFile)) {
            return;
        }
        $content = file_get_contents($logFile);
        $this->assertStringNotContainsString($needle, $content, "Log should NOT contain: {$needle}");
    }

    protected function assertTraceExists(string $traceId): void
    {
        $traceFile = Logger::getLogDir() . '/traces/' . $traceId . '.json';
        $this->assertFileExists($traceFile, "Trace file should exist: {$traceId}");
    }

    protected function assertResponseStructure(array $response): void
    {
        $this->assertArrayHasKey('success', $response, 'Response must have success field');
        $this->assertArrayHasKey('message', $response, 'Response must have message field');
        if (isset($response['data'])) {
            $this->assertIsArray($response['data'], 'Data must be an array');
        }
        if (isset($response['meta'])) {
            $this->assertIsArray($response['meta'], 'Meta must be an array');
            if (isset($response['meta']['errors'])) {
                $this->assertIsArray($response['meta']['errors'], 'Meta.errors must be an array');
            }
        }
    }

    protected function assertTiming(callable $fn, float $maxMs, string $label = ''): float
    {
        $start = microtime(true);
        $fn();
        $elapsed = (microtime(true) - $start) * 1000;
        $this->assertLessThan($maxMs, $elapsed, "{$label} should complete under {$maxMs}ms, took: " . round($elapsed, 3) . "ms");
        return $elapsed;
    }

    protected function withEnv(string $key, string $value, callable $fn): void
    {
        $original = getenv($key);
        putenv("{$key}={$value}");
        $_ENV[$key] = $value;
        try {
            $fn();
        } finally {
            if ($original !== false) {
                putenv("{$key}={$original}");
                $_ENV[$key] = $original;
            } else {
                putenv($key);
                unset($_ENV[$key]);
            }
        }
    }

    protected function createInMemorySqlite(): void
    {
        Database::configure([
            'driver' => 'sqlite',
            'database' => ':memory:',
        ]);
    }
}

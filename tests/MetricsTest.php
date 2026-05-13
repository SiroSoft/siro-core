<?php

declare(strict_types=1);

namespace Siro\Core\Tests;

use PHPUnit\Framework\TestCase;
use Siro\Core\Metrics;

final class MetricsTest extends TestCase
{
    protected function setUp(): void
    {
        Metrics::init('test', false);
    }

    protected function tearDown(): void
    {
        Metrics::init('siro', false);
    }

    public function testCounterIncrement(): void
    {
        Metrics::counter('http_requests_total', 1, ['method' => 'GET']);
        Metrics::counter('http_requests_total', 1, ['method' => 'GET']);
        $export = Metrics::export();

        $this->assertStringContainsString('http_requests_total', $export);
    }

    public function testHistogramObserve(): void
    {
        Metrics::histogram('http_request_duration_seconds', 0.5, ['route' => '/api/users']);
        Metrics::histogram('http_request_duration_seconds', 1.2, ['route' => '/api/users']);

        $export = Metrics::export();
        $this->assertStringContainsString('http_request_duration_seconds', $export);
    }

    public function testGaugeSet(): void
    {
        Metrics::gauge('memory_usage_bytes', 42_000_000);
        $export = Metrics::export();
        $this->assertStringContainsString('memory_usage_bytes', $export);
    }

    public function testExportFormat(): void
    {
        Metrics::counter('test_metric', 1, ['status' => '200']);

        $lines = explode("\n", trim(Metrics::export()));
        $this->assertNotEmpty($lines);

        $this->assertNotEmpty($lines, 'Export should have content');

        $hasMetric = false;
        foreach ($lines as $line) {
            if (str_contains($line, 'test_metric')) {
                $hasMetric = true;
                break;
            }
        }
        $this->assertTrue($hasMetric, 'Export should contain the metric name. Lines: ' . substr(implode("\n", $lines), 0, 500));
    }
}

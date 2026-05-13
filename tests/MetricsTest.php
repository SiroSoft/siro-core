<?php

declare(strict_types=1);

namespace Siro\Core\Tests;

use PHPUnit\Framework\TestCase;
use Siro\Core\Metrics;

final class MetricsTest extends TestCase
{
    private Metrics $metrics;

    protected function setUp(): void
    {
        $this->metrics = new Metrics();
    }

    public function testCounterIncrement(): void
    {
        $this->metrics->increment('http_requests_total', ['method' => 'GET']);
        $this->metrics->increment('http_requests_total', ['method' => 'GET']);
        $export = $this->metrics->export();

        $this->assertStringContainsString('http_requests_total', $export);
    }

    public function testHistogramObserve(): void
    {
        $this->metrics->observe('http_request_duration_seconds', 0.5, ['route' => '/api/users']);
        $this->metrics->observe('http_request_duration_seconds', 1.2, ['route' => '/api/users']);

        $export = $this->metrics->export();
        $this->assertStringContainsString('http_request_duration_seconds', $export);
    }

    public function testGaugeSet(): void
    {
        $this->metrics->gauge('memory_usage_bytes', 42_000_000);
        $export = $this->metrics->export();
        $this->assertStringContainsString('memory_usage_bytes 42000000', $export);
    }

    public function testLabelEscaping(): void
    {
        $this->metrics->increment('test_metric', ['label' => 'value with "quotes"']);
        $export = $this->metrics->export();
        $this->assertStringContainsString('value with \\"quotes\\"', $export);
    }

    public function testExportFormat(): void
    {
        $this->metrics->increment('test_metric', ['status' => '200']);

        $lines = explode("\n", trim($this->metrics->export()));
        $this->assertNotEmpty($lines);

        $hasMetric = false;
        foreach ($lines as $line) {
            if (str_starts_with($line, 'test_metric')) {
                $hasMetric = true;
                break;
            }
        }
        $this->assertTrue($hasMetric, 'Export should contain the metric name');
    }
}

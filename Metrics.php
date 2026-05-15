<?php

declare(strict_types=1);

namespace Siro\Core;

/**
 * Lightweight Prometheus metrics collector.
 *
 * Exposes counters and histograms at GET /metrics for Prometheus scraping.
 * No external dependencies — writes directly to OpenMetrics text format.
 *
 * Usage:
 *   Metrics::counter('http_requests_total', 1, ['method' => 'GET', 'path' => '/health']);
 *   Metrics::histogram('http_response_duration_ms', 12.5, ['method' => 'POST']);
 *   Metrics::gauge('db_connections_active', 5);
 *
 * @package Siro\Core
 */
final class Metrics
{
    private const METRICS_CACHE_FILE = 'storage/framework/metrics.php';

    /** @var array<string, array{type: string, help: string, labels: array<string, string>, value: float|int}> */
    private static array $counters = [];

    /** @var array<string, array{buckets: array<int, float>, values: array<string, array<int, int>>, sum: array<string, float>, count: array<string, int>, help?: string}> */
    private static array $histograms = [];

    /** @var array<string, array{help: string, value: float|int, labels: array<string, string>}> */
    private static array $gauges = [];

    private static string $namespace = 'siro';
    private static bool $persist = false;
    private static int $flushInterval = 0;
    private static int $opsSinceFlush = 0;

    public static function init(string $namespace = 'siro', bool $persist = false, int $flushInterval = 100): void
    {
        self::$namespace = $namespace;
        self::$persist = $persist;
        self::$flushInterval = max(1, $flushInterval);
        self::$opsSinceFlush = 0;
        if ($persist) {
            self::load();
        }
    }

    public static function persistNow(): void
    {
        if (!self::$persist) { return; }
        $data = ['counters' => self::$counters, 'histograms' => self::$histograms, 'gauges' => self::$gauges];
        $dir = dirname(self::METRICS_CACHE_FILE);
        if (!is_dir($dir)) { @mkdir($dir, 0775, true); }
        file_put_contents(self::METRICS_CACHE_FILE, '<?php return ' . var_export($data, true) . ';');
    }

    /**
     * Increment a counter.
     *
     * @param array<string, string> $labels
     */
    public static function counter(string $name, int $value = 1, array $labels = [], string $help = ''): void
    {
        $key = self::key($name, $labels);
        if (!isset(self::$counters[$key])) {
            self::$counters[$key] = ['type' => 'counter', 'help' => $help ?: "Counter {$name}", 'labels' => $labels, 'value' => 0];
        }
        self::$counters[$key]['value'] += $value;
        self::maybeFlush();
    }

    /**
     * Observe a value for a histogram.
     *
     * @param array<string, string> $labels
     * @param array<int, float> $buckets Custom bucket boundaries
     */
    public static function histogram(string $name, float $value, array $labels = [], string $help = '', array $buckets = [1, 5, 10, 25, 50, 100, 250, 500, 1000, 2500, 5000]): void
    {
        if (!isset(self::$histograms[$name])) {
            self::$histograms[$name] = [
                'buckets' => $buckets,
                'values' => [],
                'sum' => [],
                'count' => [],
            ];
        }
        $labelsKey = self::labelsKey($labels);

        if (!isset(self::$histograms[$name]['values'][$labelsKey])) {
            self::$histograms[$name]['values'][$labelsKey] = array_fill(0, count($buckets), 0);
            self::$histograms[$name]['sum'][$labelsKey] = 0.0;
            self::$histograms[$name]['count'][$labelsKey] = 0;
        }

        self::$histograms[$name]['sum'][$labelsKey] += $value;
        self::$histograms[$name]['count'][$labelsKey]++;

        foreach ($buckets as $i => $bucket) {
            if ($value <= $bucket) {
                self::$histograms[$name]['values'][$labelsKey][$i]++;
                break;
            }
        }

        self::maybeFlush();
    }

    /**
     * Set a gauge value.
     *
     * @param array<string, string> $labels
     */
    public static function gauge(string $name, float|int $value, array $labels = [], string $help = ''): void
    {
        $key = self::key($name, $labels);
        self::$gauges[$key] = ['help' => $help ?: "Gauge {$name}", 'value' => $value, 'labels' => $labels];
        self::maybeFlush();
    }

    /**
     * Export all metrics in OpenMetrics text format.
     */
    public static function export(): string
    {
        $lines = [];
        $lines[] = '# HELP ' . self::$namespace . '_ http metrics';
        $lines[] = '# TYPE ' . self::$namespace . '_ counter';
        $lines[] = '';

        foreach (self::$counters as $data) {
            $name = self::$namespace . '_' . $data['type'] . '_' . strval($data['labels']['__name__'] ?? '');
            $lines[] = '# HELP ' . $name . ' ' . $data['help'];
            $lines[] = '# TYPE ' . $name . ' counter';
            $labelStr = self::formatLabels($data['labels']);
            $lines[] = $name . $labelStr . ' ' . intval($data['value']);
        }

        foreach (self::$histograms as $name => $hData) {
            $fullName = self::$namespace . '_' . $name;
            $lines[] = '# HELP ' . $fullName . ' ' . strval($hData['help'] ?? '');
            $lines[] = '# TYPE ' . $fullName . ' histogram';

            foreach ($hData['values'] as $labelsKey => $buckets) {
                /** @var array<string, string> $labels */
                $labels = self::parseLabelsKey($labelsKey);
                $labelStr = self::formatLabels($labels);
                foreach ($buckets as $i => $count) {
                    $b = $hData['buckets'][$i];
                    $lines[] = $fullName . '_bucket' . $labelStr . '{le="' . $b . '"} ' . $count;
                }
                $lines[] = $fullName . '_bucket' . $labelStr . '{le="+Inf"} ' . $hData['count'][$labelsKey];
                $lines[] = $fullName . '_sum' . $labelStr . ' ' . $hData['sum'][$labelsKey];
                $lines[] = $fullName . '_count' . $labelStr . ' ' . $hData['count'][$labelsKey];
            }
        }

        foreach (self::$gauges as $data) {
            $name = self::$namespace . '_gauge_' . strval($data['labels']['__name__'] ?? '');
            $lines[] = '# HELP ' . $name . ' ' . $data['help'];
            $lines[] = '# TYPE ' . $name . ' gauge';
            $labelStr = self::formatLabels($data['labels']);
            $lines[] = $name . $labelStr . ' ' . $data['value'];
        }

        return implode("\n", $lines) . "\n";
    }

    /** Register metrics route on router automatically */
    public static function registerRoute(Router $router, string $path = '/metrics'): void
    {
        $router->get($path, function (Request $request) {
            $response = Response::raw(Metrics::export(), 'text/plain', 200);
            $response->header('Content-Type', 'text/plain; charset=utf-8');
            return $response;
        });
    }

    /** @param array<string, string> $labels */
    private static function key(string $name, array $labels): string
    {
        return $name . '::' . self::labelsKey($labels);
    }

    /** @param array<string, string> $labels */
    private static function labelsKey(array $labels): string
    {
        ksort($labels);
        return implode(',', array_map(fn ($k, $v) => strval($k) . '=' . strval($v), array_keys($labels), $labels));
    }

    /** @param array<string, string> $labels */
    private static function formatLabels(array $labels): string
    {
        if ($labels === []) { return ''; }
        $parts = [];
        foreach ($labels as $k => $v) {
            $parts[] = "{$k}=\"{$v}\"";
        }
        return '{' . implode(',', $parts) . '}';
    }

    /** @return array<string, string> */
    private static function parseLabelsKey(string $key): array
    {
        $labels = [];
        foreach (explode(',', $key) as $part) {
            $parts = explode('=', $part, 2);
            if (count($parts) === 2) {
                $labels[$parts[0]] = $parts[1];
            }
        }
        return $labels;
    }

    private static function maybeFlush(): void
    {
        if (!self::$persist) { return; }
        self::$opsSinceFlush++;
        if (self::$opsSinceFlush < self::$flushInterval) { return; }
        self::$opsSinceFlush = 0;
        self::persistNow();
    }

    private static function load(): void
    {
        $cacheFile = self::METRICS_CACHE_FILE;
        if (defined('SIRO_BASE_PATH') && is_string(SIRO_BASE_PATH)) {
            $cacheFile = SIRO_BASE_PATH . '/' . $cacheFile;
        }
        if (!is_file($cacheFile)) { return; }
        /** @var mixed $data */
        $data = require $cacheFile;
        if (is_array($data)) {
            /** @var array<string, array{type: string, help: string, labels: array<string, string>, value: float|int}> $counters */
            $counters = $data['counters'] ?? [];
            self::$counters = $counters;
            /** @var array<string, array{buckets: array<int, float>, values: array<string, array<int, int>>, sum: array<string, float>, count: array<string, int>}> $histograms */
            $histograms = $data['histograms'] ?? [];
            self::$histograms = $histograms;
            /** @var array<string, array{help: string, value: float|int, labels: array<string, string>}> $gauges */
            $gauges = $data['gauges'] ?? [];
            self::$gauges = $gauges;
        }
    }
}

#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * OpenTelemetry / W3C Trace Context propagation helper.
 *
 * Generates and parses traceparent headers (W3C Trace Context).
 * Enables distributed tracing across services without external dependencies.
 *
 * Usage:
 *   $traceparent = OtelTrace::generate();           // New trace
 *   $ctx = OtelTrace::parse($traceparent);           // Parse incoming
 *   Response::header('traceparent', $traceparent);   // Propagate
 */

final class OtelTrace
{
    private const VERSION = '00';
    private const TRACE_ID_LEN = 32;   // 16 bytes hex
    private const SPAN_ID_LEN = 16;    // 8 bytes hex

    /**
     * Generate a new W3C traceparent header for a new trace root.
     */
    public static function generate(): string
    {
        $traceId = bin2hex(random_bytes(16));
        $spanId = bin2hex(random_bytes(8));
        return sprintf('%s-%s-%s-%02x', self::VERSION, $traceId, $spanId, random_int(0, 255));
    }

    /**
     * Generate a child span from an existing traceparent.
     */
    public static function child(string $traceparent): string
    {
        $parts = explode('-', $traceparent);
        $traceId = $parts[1] ?? bin2hex(random_bytes(16));
        $spanId = bin2hex(random_bytes(8));
        return sprintf('%s-%s-%s-%02x', self::VERSION, $traceId, $spanId, random_int(0, 255));
    }

    /**
     * Parse a traceparent into its components.
     * @return array{version:string,trace_id:string,span_id:string,trace_flags:string}|null
     */
    public static function parse(string $traceparent): ?array
    {
        if (!preg_match('/^([0-9a-f]{2})-([0-9a-f]{32})-([0-9a-f]{16})-([0-9a-f]{2})$/', $traceparent, $m)) {
            return null;
        }
        return ['version' => $m[1], 'trace_id' => $m[2], 'span_id' => $m[3], 'trace_flags' => $m[4]];
    }

    /**
     * Check if a traceparent is valid W3C format.
     */
    public static function isValid(string $traceparent): bool
    {
        return self::parse($traceparent) !== null;
    }
}

// CLI usage
if (PHP_SAPI === 'cli' && isset($argv[1]) && $argv[1] === '--generate') {
    echo OtelTrace::generate() . "\n";
    exit(0);
}

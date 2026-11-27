<?php

declare(strict_types=1);

namespace Siro\Core\Webhook;

use InvalidArgumentException;

/**
 * HMAC-SHA256 webhook signing + verification (G5, v1.5.0).
 *
 * Canonical header (single header, Stripe-style):
 *   X-Siro-Signature: t={unix_timestamp},v1={hmac_hex}
 * where hmac = hash_hmac('sha256', "{timestamp}.{payload}", $secret).
 *
 * Verification is fail-closed and never throws: a malformed header, a
 * missing field, a non-numeric timestamp, a timestamp outside the replay
 * window, an empty secret, or a wrong signature all return false.
 *
 * @package Siro\Core\Webhook
 */
final class WebhookSigner
{
    public const string HEADER = 'X-Siro-Signature';

    /** Replay window in seconds (5 minutes, same as the plan). */
    public const int DEFAULT_TOLERANCE_SECONDS = 300;

    /**
     * Sign a payload and return the `X-Siro-Signature` header value.
     *
     * @throws InvalidArgumentException When the secret is empty — signing
     *     with no secret would produce a signature anyone can forge.
     */
    public static function sign(string $payload, string $secret, ?int $timestamp = null): string
    {
        if ($secret === '') {
            throw new InvalidArgumentException('WebhookSigner::sign() requires a non-empty secret.');
        }
        $ts = $timestamp ?? time();
        $hmac = hash_hmac('sha256', $ts . '.' . $payload, $secret);
        return 't=' . $ts . ',v1=' . $hmac;
    }

    /**
     * Build the request headers for a signed webhook delivery.
     *
     * @return array<string, string> Header map with the single canonical header.
     */
    public static function signatureHeaders(string $payload, string $secret, ?int $timestamp = null): array
    {
        return [self::HEADER => self::sign($payload, $secret, $timestamp)];
    }

    /**
     * Verify a signed payload. Fail-closed: any problem returns false.
     *
     * @param string $header The `X-Siro-Signature` header value (`t=...,v1=...`).
     * @param int $toleranceSeconds Maximum |now - timestamp| accepted (replay window).
     * @param int|null $now Current unix time (injected for tests; defaults to time()).
     */
    public static function verify(
        string $payload,
        string $header,
        string $secret,
        int $toleranceSeconds = self::DEFAULT_TOLERANCE_SECONDS,
        ?int $now = null
    ): bool {
        if ($secret === '' || $header === '') {
            return false;
        }
        if ($toleranceSeconds < 0) {
            return false;
        }
        $parts = self::parseHeader($header);
        if ($parts === null) {
            return false;
        }
        [$timestamp, $signature] = $parts;
        $current = $now ?? time();
        if (abs($current - $timestamp) > $toleranceSeconds) {
            return false;
        }
        $expected = hash_hmac('sha256', $timestamp . '.' . $payload, $secret);
        return hash_equals($expected, $signature);
    }

    /**
     * Parse `t={ts},v1={hex}` into [timestamp, signature].
     * Field order does not matter; unknown fields are ignored.
     * Returns null when either required field is missing or malformed.
     *
     * @return array{int, string}|null
     */
    private static function parseHeader(string $header): ?array
    {
        $timestamp = null;
        $signature = null;
        foreach (explode(',', $header) as $field) {
            $field = trim($field);
            if (str_starts_with($field, 't=')) {
                $raw = substr($field, 2);
                if ($raw === '' || !preg_match('/^-?\d+$/', $raw)) {
                    return null;
                }
                $timestamp = (int) $raw;
            } elseif (str_starts_with($field, 'v1=')) {
                $raw = substr($field, 3);
                if ($raw === '' || !preg_match('/^[0-9a-f]+$/', $raw)) {
                    return null;
                }
                $signature = $raw;
            }
        }
        if ($timestamp === null || $signature === null) {
            return null;
        }
        return [$timestamp, $signature];
    }
}

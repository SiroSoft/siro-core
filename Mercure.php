<?php

declare(strict_types=1);

namespace Siro\Core;

final class Mercure
{
    /** @param array<string, mixed> $data */
    public static function publish(string $topic, array $data, string $type = 'message'): bool
    {
        $hubUrl = Env::get('MERCURE_HUB_URL', 'http://localhost:3001/.well-known/mercure');
        $jwt = Env::get('MERCURE_PUBLISHER_JWT', '');
        if ($jwt === '') {
            return false;
        }

        $payload = (string) json_encode(['type' => $type, 'data' => $data]);
        $body = http_build_query([
            'topic' => $topic,
            'data' => $payload,
            'private' => 'on',
        ]);

        $ch = curl_init($hubUrl);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/x-www-form-urlencoded',
                'Authorization: Bearer ' . $jwt,
            ],
            CURLOPT_TIMEOUT => 2,
        ]);
        $result = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return $httpCode === 200;
    }

    public static function topic(string $resource, string|int|null $id = null): string
    {
        return $id !== null ? "/api/{$resource}/{$id}" : "/api/{$resource}";
    }
}

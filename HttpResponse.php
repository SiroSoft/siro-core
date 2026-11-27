<?php

declare(strict_types=1);

namespace Siro\Core;

/**
 * HTTP response wrapper returned by Http:: methods.
 *
 * @package Siro\Core
 */
final class HttpResponse
{
    private int $status;
    private string $body;
    private string $rawHeaders;
    /** @var array<string, mixed>|null */ private ?array $parsedJson = null;

    public function __construct(int $status, string $body, string $rawHeaders)
    {
        $this->status = $status;
        $this->body = $body;
        $this->rawHeaders = $rawHeaders;
    }

    public function status(): int
    {
        return $this->status;
    }

    public function body(): string
    {
        return $this->body;
    }

    /** @return array<string, mixed> */ public function json(): array
    {
        if ($this->parsedJson === null) {
            /** @var array<string, mixed> $decoded */
            $decoded = json_decode($this->body, true) ?? [];
            $this->parsedJson = $decoded;
        }
        return $this->parsedJson ?? [];
    }

    public function ok(): bool
    {
        return $this->status >= 200 && $this->status < 300;
    }

    public function failed(): bool
    {
        return $this->status >= 400;
    }

    public function header(string $name): string
    {
        $regex = '/^' . preg_quote($name, '/') . ':\s*(.+)$/mi';
        preg_match($regex, $this->rawHeaders, $matches);
        return trim($matches[1] ?? '');
    }

    /** @return array<string, string> */ public function headers(): array
    {
        $lines = explode("\r\n", trim($this->rawHeaders));
        $headers = [];
        foreach ($lines as $line) {
            if (str_contains($line, ': ')) {
                [$k, $v] = explode(': ', $line, 2);
                $headers[strtolower($k)] = $v;
            }
        }
        return $headers;
    }
}

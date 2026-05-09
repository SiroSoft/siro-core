<?php

declare(strict_types=1);

namespace Siro\Core;

abstract class Controller
{
    protected Request $request;

    public function setRequest(Request $request): void
    {
        $this->request = $request;
    }

    /**
     * @param array<string, mixed> $meta
     */
    protected function success(mixed $data = null, string $message = 'OK', int $statusCode = 200, array $meta = []): Response
    {
        return Response::success($data, $message, $statusCode, $meta);
    }

    protected function error(string $message, int $statusCode = 400, array $errors = []): Response
    {
        return Response::error($message, $statusCode, $errors);
    }

    protected function created(mixed $data = null, string $message = 'Created'): Response
    {
        return Response::created($data, $message);
    }

    protected function noContent(): Response
    {
        return Response::noContent();
    }

    /**
     * @param array<int, mixed> $data
     * @param array<string, mixed> $meta
     */
    protected function paginated(array $data, array $meta, string $message = 'OK'): Response
    {
        return Response::paginated($data, $meta, $message);
    }

    /**
     * @param array<string, string> $rules
     * @return array<string, mixed>
     */
    protected function validate(array $rules): array
    {
        return $this->request->validate($rules);
    }

    protected function input(?string $key = null, mixed $default = null): mixed
    {
        if ($key === null) {
            return $this->request->body();
        }
        return $this->request->input($key, $default);
    }

    protected function param(string $key, mixed $default = null): mixed
    {
        return $this->request->param($key, $default);
    }

    protected function query(?string $key = null, mixed $default = null): mixed
    {
        return $this->request->query($key, $default);
    }

    /**
     * @return array<string, mixed>|null
     */
    protected function user(): ?array
    {
        return $this->request->user();
    }
}

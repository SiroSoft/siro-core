<?php

declare(strict_types=1);

namespace Siro\Core\Tests\Unit;

use Siro\Core\Controller;
use Siro\Core\Response;

final class TestController extends Controller
{
    public function doSuccess(): Response
    {
        return $this->success(['a' => 1]);
    }

    public function doError(): Response
    {
        return $this->error('bad', 400);
    }

    public function doCreated(): Response
    {
        return $this->created(['id' => 1]);
    }

    public function doNoContent(): Response
    {
        return $this->noContent();
    }

    public function doPaginated(): Response
    {
        return $this->paginated([[1]], ['page' => 1, 'per_page' => 1, 'total' => 1, 'last_page' => 1]);
    }

    public function inputName(): mixed
    {
        return $this->input('name');
    }

    public function queryPage(): mixed
    {
        return $this->query('page');
    }

    public function allInput(): mixed
    {
        return $this->input();
    }

    public function doValidate(): array
    {
        return $this->validate(['name' => 'required|string']);
    }

    public function getUser(): ?array
    {
        return $this->user();
    }
}

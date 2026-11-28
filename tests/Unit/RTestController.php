<?php

declare(strict_types=1);

namespace Siro\Core\Tests\Unit;

use Siro\Core\Response;

class RTestController
{
    public function index(): Response
    {
        return Response::success(['ok' => 1]);
    }
}

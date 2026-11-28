<?php

declare(strict_types=1);

namespace Siro\Core\Tests\Unit;

use Siro\Core\Attributes\Body;
use Siro\Core\Attributes\QueryParam;
use Siro\Core\Request;
use Siro\Core\Response;

final class OpenApiWidgetController
{
    #[QueryParam('search', required: false, description: 'Filter text')]
    public function index(Request $request): Response
    {
        return Response::success([]);
    }

    #[Body('email', rules: 'required|email', description: 'Login email')]
    #[Body('age', type: 'integer', required: false)]
    public function store(Request $request): Response
    {
        return Response::created(['id' => 1]);
    }
}

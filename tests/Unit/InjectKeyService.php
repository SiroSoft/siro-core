<?php

declare(strict_types=1);

namespace Siro\Core\Tests\Unit;

use Siro\Core\Inject;

final class InjectKeyService
{
    public function __construct(
        #[Inject('services.payment.key')] public readonly string $apiKey = '',
    ) {
    }
}

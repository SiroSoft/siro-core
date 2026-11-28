<?php

declare(strict_types=1);

namespace Siro\Core\Tests\Unit;

use Siro\Core\Inject;

final class InjectTtlService
{
    public function __construct(
        #[Inject('config.jwt.ttl')] public readonly int $ttl = 3600,
    ) {
    }
}

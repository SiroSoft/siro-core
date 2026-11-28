<?php

declare(strict_types=1);

namespace Siro\Core\Tests\Unit;

use Siro\Core\QueueInterface;

final class QWTestJob implements QueueInterface
{
    public function handle(array $data = []): void
    {
        // no-op success
    }
}

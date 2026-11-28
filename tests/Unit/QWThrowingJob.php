<?php

declare(strict_types=1);

namespace Siro\Core\Tests\Unit;

use Siro\Core\QueueInterface;

final class QWThrowingJob implements QueueInterface
{
    public function handle(array $data = []): void
    {
        throw new \RuntimeException('boom');
    }
}

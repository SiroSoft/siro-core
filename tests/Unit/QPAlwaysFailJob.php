<?php

declare(strict_types=1);

namespace Siro\Core\Tests\Unit;

/** Job that always throws */

final class QPAlwaysFailJob implements \Siro\Core\QueueInterface
{
    public function handle(array $data = []): void
    {
        throw new \RuntimeException('QPAlwaysFailJob always fails');
    }
}

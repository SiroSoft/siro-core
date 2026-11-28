<?php

declare(strict_types=1);

namespace Siro\Core\Tests\Unit;

/** Poison job — always throws with unexpected exception type */

final class QPPoisonJob implements \Siro\Core\QueueInterface
{
    public function handle(array $data = []): void
    {
        throw new \InvalidArgumentException('Invalid payload structure');
    }
}

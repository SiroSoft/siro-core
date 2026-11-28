<?php

declare(strict_types=1);

namespace Siro\Core\Tests\Unit;

/** Job that records execution order */

final class QPOrderJob implements \Siro\Core\QueueInterface
{
    /** @var array<int, int> */
    public static array $order = [];

    public function handle(array $data = []): void
    {
        self::$order[] = (int) ($data['seq'] ?? 0);
    }
}

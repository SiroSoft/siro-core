<?php

declare(strict_types=1);

namespace Siro\Core\Tests\Unit;

/** Job that throws on first attempt, succeeds on second */

final class QPThrowOnceJob implements \Siro\Core\QueueInterface
{
    public static int $throwCount = 0;
    public static bool $succeeded = false;

    public function handle(array $data = []): void
    {
        self::$throwCount++;
        if (self::$throwCount <= 1) {
            throw new \RuntimeException('Temporary failure');
        }
        self::$succeeded = true;
    }
}

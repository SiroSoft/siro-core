<?php

declare(strict_types=1);

namespace Siro\Core\Tests\Unit;

/**
 * Alternate job type for mixed-job testing.
 */

final class QLongRunAltJob implements \Siro\Core\QueueInterface
{
    /** @var array<int, array<string, mixed>> */
    public static array $executions = [];

    public function handle(array $data = []): void
    {
        self::$executions[] = $data;
    }
}

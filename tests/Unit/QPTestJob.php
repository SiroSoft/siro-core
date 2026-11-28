<?php

declare(strict_types=1);

namespace Siro\Core\Tests\Unit;

// ============================================================================
// Test job classes
// ============================================================================

/** Simple job that records its execution */

final class QPTestJob implements \Siro\Core\QueueInterface
{
    /** @var array<int, array<string, mixed>> */
    public static array $executions = [];

    public function handle(array $data = []): void
    {
        self::$executions[] = $data;
    }
}

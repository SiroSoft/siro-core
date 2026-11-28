<?php

declare(strict_types=1);

namespace Siro\Core\Tests\Unit;

/** Job that tracks state leakage between executions */

final class QPStateJob implements \Siro\Core\QueueInterface
{
    /** @var array<int, array<string, mixed>> */
    public static array $states = [];

    public static ?array $previousState = null;

    public function handle(array $data = []): void
    {
        $seq = (int) ($data['seq'] ?? 0);
        self::$states[$seq] = [
            'seq' => $seq,
            'previous' => self::$previousState,
        ];
        self::$previousState = $data;
    }
}

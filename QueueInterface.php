<?php

declare(strict_types=1);

namespace Siro\Core;

interface QueueInterface
{
    /** @param array<string, mixed> $data */
    public function handle(array $data = []): void;
}

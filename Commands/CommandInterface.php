<?php

declare(strict_types=1);

namespace Siro\Core\Commands;

interface CommandInterface
{
    /** @param array<int, string> $args */
    public function run(array $args): int;
}

<?php

declare(strict_types=1);

namespace Siro\Core\Commands;

final class MakeDocsCommand implements \Siro\Core\Commands\CommandInterface {
    use CommandSupport;

    public function __construct(private readonly string $basePath)
    {
    }

    /** @param array<int, string> $args */
    public function run(array $args): int
    {
        $args[] = '--with-swagger';
        return (new MakeOpenApiCommand($this->basePath))->run($args);
    }
}

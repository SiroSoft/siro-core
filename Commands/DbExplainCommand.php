<?php

declare(strict_types=1);

namespace Siro\Core\Commands;

/**
 * @deprecated Use `php siro db:why <query_hash>` or `php siro db:why --query="SELECT ..."` instead.
 * This command forwards to DbWhyCommand (which runs EXPLAIN) instead of
 * printing a hint and exiting 0 without doing anything.
 */
final class DbExplainCommand implements \Siro\Core\Commands\CommandInterface
{
    use CommandSupport;

    public function __construct(private readonly string $basePath)
    {
    }

    /** @param array<int, string> $args */
    public function run(array $args): int
    {
        $this->write('');
        $this->write('  db:explain is deprecated — running db:why instead.');
        $this->write('');

        return (new DbWhyCommand($this->basePath))->run($args);
    }
}

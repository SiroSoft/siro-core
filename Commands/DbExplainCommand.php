<?php

declare(strict_types=1);

namespace Siro\Core\Commands;

/**
 * Redirect to db:why --explain.
 *
 * @deprecated Use `php siro db:why --explain` instead.
 */
final class DbExplainCommand implements \Siro\Core\Commands\CommandInterface {
    use CommandSupport;

    public function __construct(private readonly string $basePath)
    {
    }

    /** @param array<int, string> $args */
    public function run(array $args): int
    {
        $this->write('');
        $this->write('  ⚠ db:explain has been merged into db:why');
        $this->write('  Use: ' . "\033[36mphp siro db:why --explain\033[0m");
        $this->write('');

        $storage = $this->basePath . DIRECTORY_SEPARATOR . 'storage';
        if (!is_dir($storage)) {
            $this->write('  (project storage not found at ' . $storage . ')');
        }

        return 0;
    }
}

<?php

declare(strict_types=1);

namespace Siro\Core\Commands;

/**
 * Run the 30-second debug workflow demo.
 *
 * Requires and executes the scripts/demo.php script which simulates
 * the complete SiroPHP debug pipeline: test → fail → why → fix → retry.
 */
final class DemoCommand implements CommandInterface
{
    use CommandSupport;

    public function __construct(private readonly string $basePath) {}

    /** @param array<int, string> $args */
    public function run(array $args): int
    {
        $demoScript = $this->basePath . DIRECTORY_SEPARATOR . 'scripts' . DIRECTORY_SEPARATOR . 'demo.php';

        if (!file_exists($demoScript)) {
            $this->error('Demo script not found: ' . $demoScript);
            return 1;
        }

        require $demoScript;
        return 0;
    }
}

<?php

declare(strict_types=1);

namespace Siro\Core\Commands;

/**
 * Generate a job class.
 *
 * Creates a job class in app/Jobs/ with a handle()
 * method for queue processing.
 *
 * Usage:
 *   php siro make:job SendWelcomeEmail
 *
 * @package Siro\Core\Commands
 */
final class MakeJobCommand implements \Siro\Core\Commands\CommandInterface {
    use CommandSupport;

    public function __construct(private readonly string $basePath)
    {
    }

    /** @param array<int, string> $args */
    public function run(array $args): int
    {
        $name = trim((string) ($args[0] ?? ''));

        if ($name === '') {
            $this->write('Job class name is required. Example: php siro make:job SendWelcomeEmail');
            return 1;
        }

        $className = $this->studly($name);
        if (!str_ends_with($className, 'Job') && !str_ends_with($className, 'Mail')) {
            $className .= 'Job';
        }

        $jobsDir = $this->basePath . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'Jobs';
        if (!is_dir($jobsDir)) {
            mkdir($jobsDir, 0775, true);
        }

        $path = $jobsDir . DIRECTORY_SEPARATOR . $className . '.php';
        if (is_file($path) && !$this->confirmOverwrite($this->basePath, $path)) {
            $this->write('Skipped: app/Jobs/' . $className . '.php');
            return 0;
        }

        $content = <<<PHP
<?php

declare(strict_types=1);

namespace App\Jobs;

use Siro\Core\Queue;

/**
 * {$className} — generated job class.
 *
 * Usage:
 *   Queue::push({$className}::class, \$data);
 *
 * @package App\Jobs
 */
final class {$className}
{
    /**
     * Execute the job.
     *
     * @param array<string, mixed> \$data Data passed from Queue::push()
     */
    public function handle(array \$data = []): void
    {
        // Implement your job logic here
        // Example: Mail::to(\$data['email'])->subject('Welcome')->html(\$body)->send();
    }
}

PHP;

        file_put_contents($path, $content);
        $this->write('Generated: app/Jobs/' . $className . '.php');

        return 0;
    }
}

<?php

declare(strict_types=1);

namespace Siro\Core\Commands;

class UpCommand
{
    use CommandSupport;

    public string $name = 'up';
    public string $description = 'Disable maintenance mode';

    private string $basePath;

    public function __construct(string $basePath)
    {
        $this->basePath = $basePath;
    }

    public function run(array $args): int
    {
        $file = $this->basePath . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'framework' . DIRECTORY_SEPARATOR . 'down';
        if (file_exists($file)) {
            unlink($file);
            $this->write('Application is now live.');
        } else {
            $this->write('Application is already live.');
        }
        return 0;
    }
}

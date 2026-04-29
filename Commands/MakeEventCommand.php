<?php

declare(strict_types=1);

namespace Siro\Core\Commands;

/**
 * Generate an event class.
 *
 * Creates an event class in app/Events/ with a static dispatch()
 * method. Can be used with Event::on() for structured event handling.
 *
 * Usage:
 *   php siro make:event UserCreated
 *   php siro make:event UserDeleted
 *
 * @package Siro\Core\Commands
 */
final class MakeEventCommand
{
    use CommandSupport;

    public function __construct(private readonly string $basePath)
    {
    }

    /** @param array<int, string> $args */
    public function run(array $args): int
    {
        $name = trim((string) ($args[0] ?? ''));

        if ($name === '') {
            $this->write('Event class name is required. Example: php siro make:event UserCreated');
            return 1;
        }

        $className = $this->studly($name);
        if (!str_ends_with($className, 'Event')) {
            $className .= 'Event';
        }

        $eventsDir = $this->basePath . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'Events';
        if (!is_dir($eventsDir)) {
            mkdir($eventsDir, 0775, true);
        }

        $path = $eventsDir . DIRECTORY_SEPARATOR . $className . '.php';
        if (is_file($path) && !$this->confirmOverwrite($this->basePath, $path)) {
            $this->write('Skipped: app/Events/' . $className . '.php');
            return 0;
        }

        $eventName = strtolower(preg_replace('/(?<!^)[A-Z]/', '_$0', $className));

        $content = <<<PHP
<?php

declare(strict_types=1);

namespace App\Events;

use Siro\Core\Event;

/**
 * {$className} — generated event class.
 *
 * Usage:
 *   Event::on('{$eventName}', function (\$payload) {
 *       // handle event
 *   });
 *
 *   // Or dispatch directly:
 *   {$className}::dispatch(\$payload);
 *
 * @package App\Events
 */
final class {$className}
{
    /**
     * Dispatch the event.
     */
    public static function dispatch(mixed \$payload = null): void
    {
        Event::emit('{$eventName}', \$payload);
    }

    /**
     * Register a listener for this event.
     */
    public static function listen(callable \$callback): void
    {
        Event::on('{$eventName}', \$callback);
    }
}

PHP;

        file_put_contents($path, $content);
        $this->write('Generated: app/Events/' . $className . '.php');

        return 0;
    }
}

<?php

declare(strict_types=1);

namespace Siro\Core\Commands;

/**
 * Generate a mail class.
 *
 * Creates a mail template class in app/Mails/ with a build()
 * method for constructing the email content.
 *
 * Usage:
 *   php siro make:mail WelcomeMail
 *
 * @package Siro\Core\Commands
 */
final class MakeMailCommand implements \Siro\Core\Commands\CommandInterface {
    use CommandSupport;

    public function __construct(private readonly string $basePath)
    {
    }

    /** @param array<int, string> $args */
    public function run(array $args): int
    {
        $name = trim((string) ($args[0] ?? ''));

        if ($name === '') {
            $this->write('Mail class name is required. Example: php siro make:mail WelcomeMail');
            return 1;
        }

        $className = $this->studly($name);
        if (!str_ends_with($className, 'Mail')) {
            $className .= 'Mail';
        }

        $mailDir = $this->basePath . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'Mails';
        if (!is_dir($mailDir)) {
            mkdir($mailDir, 0775, true);
        }

        $path = $mailDir . DIRECTORY_SEPARATOR . $className . '.php';
        if (is_file($path) && !$this->confirmOverwrite($this->basePath, $path)) {
            $this->write('Skipped: app/Mails/' . $className . '.php');
            return 0;
        }

        $content = <<<PHP
<?php

declare(strict_types=1);

namespace App\Mails;

use Siro\Core\Mail;

/**
 * {$className} — generated mail class.
 *
 * Usage:
 *   Mail::to('user@example.com')
 *       ->subject('{$className} subject')
 *       ->html((new {$className}())->build(\$data))
 *       ->send();
 *
 * @package App\Mails
 */
final class {$className}
{
    /**
     * Build the email content.
     *
     * @param array<string, mixed> \$data Data passed from the caller
     * @return string HTML content
     */
    public function build(array \$data = []): string
    {
        \$name = htmlspecialchars(\$data['name'] ?? 'User', ENT_QUOTES, 'UTF-8');

        return <<<HTML
<!DOCTYPE html>
<html>
<head><meta charset="utf-8"></head>
<body style="font-family: sans-serif; padding: 20px;">
    <h1>Hello, {\$name}!</h1>
    <p>This is an email from SiroPHP.</p>
</body>
</html>
HTML;
    }
}

PHP;

        file_put_contents($path, $content);
        $this->write('Generated: app/Mails/' . $className . '.php');

        return 0;
    }
}

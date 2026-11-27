<?php

declare(strict_types=1);

namespace Siro\Core\Commands;

use Siro\Core\Auth\OAuth2\AuthorizationServer;
use Siro\Core\Auth\OAuth2\OAuthClient;
use Siro\Core\Database;
use Throwable;

/**
 * Create an OAuth2 client.
 *
 * Usage:
 *   php siro make:oauth-client "Partner A" --redirect-uri=https://partner.example/callback --grants=authorization_code,refresh_token --scopes=read
 *   php siro make:oauth-client "Backend Service" --grants=client_credentials --scopes=read,write
 *   php siro make:oauth-client "Mobile App" --public --redirect-uri=myapp://callback --grants=authorization_code,refresh_token
 *
 * The client secret is printed exactly once — store it now, it cannot be
 * retrieved later. Public clients (--public) have no secret; the
 * authorization_code flow relies on PKCE instead.
 */
final class MakeOAuthClientCommand implements \Siro\Core\Commands\CommandInterface
{
    use CommandSupport;

    public function __construct(private readonly string $basePath)
    {
    }

    /** @param array<int, string> $args */
    public function run(array $args): int
    {
        $this->initDatabase();

        $name = '';
        $flags = ['public' => false, 'redirect-uri' => '', 'grants' => '', 'scopes' => ''];
        foreach ($args as $arg) {
            if (str_starts_with($arg, '--')) {
                $pair = explode('=', substr($arg, 2), 2);
                $key = $pair[0];
                if (array_key_exists($key, $flags)) {
                    $flags[$key] = $pair[1] ?? true;
                }
                continue;
            }
            if ($name === '') {
                $name = $arg;
            }
        }

        if ($name === '') {
            $this->error('Usage: php siro make:oauth-client <name> [--public] [--redirect-uri=...] [--grants=...] [--scopes=...]');
            $this->info('Example: php siro make:oauth-client "Partner A" --redirect-uri=https://partner.example/callback --scopes=read');
            return 1;
        }

        try {
            Database::connection()->query('SELECT 1');
        } catch (Throwable) {
            $this->error('Database not configured. Run php siro migrate first.');
            return 1;
        }

        try {
            AuthorizationServer::createTables();

            $redirectUris = $flags['redirect-uri'] !== ''
                ? array_map('trim', explode(',', (string) $flags['redirect-uri']))
                : [];
            $grants = $flags['grants'] !== ''
                ? array_map('trim', explode(',', (string) $flags['grants']))
                : ['authorization_code', 'client_credentials', 'refresh_token'];
            $scopes = $flags['scopes'] !== ''
                ? array_map('trim', explode(',', (string) $flags['scopes']))
                : [];

            $client = OAuthClient::create([
                'name' => $name,
                'redirect_uris' => $redirectUris,
                'grants' => $grants,
                'scopes' => $scopes,
                'is_confidential' => $flags['public'] !== true,
            ]);

            $this->success('OAuth2 client created.');
            $this->write('  Client ID:     ' . $client['client_id']);
            if ($client['client_secret'] !== '') {
                $this->write('  Client secret: ' . $client['client_secret']);
                $this->write('  Store the secret now — it cannot be retrieved later.');
            } else {
                $this->write('  Public client (no secret — use PKCE).');
            }
            $this->write('  Grants:        ' . implode(',', $client['grants']));
            $this->write('  Scopes:        ' . ($client['scopes'] !== '' ? $client['scopes'] : '(all configured scopes)'));
            return 0;
        } catch (Throwable $e) {
            $this->error('Failed to create OAuth2 client: ' . $e->getMessage());
            return 1;
        }
    }

    private function initDatabase(): void
    {
        $configPath = $this->basePath . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'database.php';
        if (is_file($configPath)) {
            /** @var array<string, mixed> $config */
            $config = require $configPath;
            Database::configure($config);
        }
    }

    public function help(): void
    {
        echo "\nUsage: php siro make:oauth-client <name> [--public] [--redirect-uri=...] [--grants=...] [--scopes=...]\n\n";
        echo "Creates an OAuth2 client (league/oauth2-server bridge).\n";
        echo "The client secret is shown once — store it immediately.\n\n";
    }
}

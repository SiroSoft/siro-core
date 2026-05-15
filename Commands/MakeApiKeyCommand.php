<?php

declare(strict_types=1);

namespace Siro\Core\Commands;

use Siro\Core\Auth\ApiKey;
use Siro\Core\Commands\CommandSupport;
use Siro\Core\Database;
use Throwable;

/**
 * Create API key for external developers.
 */
final class MakeApiKeyCommand implements \Siro\Core\Commands\CommandInterface {
    use CommandSupport;

    /** @param array<int, string> $args */
    public function run(array $args): int
    {
        /** @var string $name */
        $name = $args[0] ?? '';
        /** @var string $scopes */
        $scopes = $args[1] ?? 'read';
        /** @var int $expiresIn */
        $expiresIn = isset($args[2]) ? (int) $args[2] : 365;

        if ($name === '') {
            $this->error('Usage: php siro make:apikey <name> [scopes] [expires_days]');
            $this->info('Example: php siro make:apikey "Partner A" "read,write" 365');
            return 1;
        }

        try {
            Database::connection()->query('SELECT 1');
        } catch (Throwable) {
            $this->error('Database not configured. Run php siro migrate first.');
            return 1;
        }

        try {
            /** @var array{token:string, name:string, scopes:string, created_at:string, expires_at:?string} $result */
            $result = ApiKey::create($name, $scopes, expiresIn: $expiresIn);

            $this->info('✓ API Key created successfully!');
            $this->info('');
            $this->info('Name:    ' . $result['name']);
            $this->info('Scopes:  ' . $result['scopes']);
            $this->info('Created: ' . $result['created_at']);
            $this->info('Expires: ' . ($result['expires_at'] ?? 'Never'));
            $this->info('');
            $this->warn('⚠️  Save this token NOW - it will not be shown again:');
            $this->info($result['token']);
            $this->info('');
            $this->info('Use in requests:');
            $this->info('  curl -H "X-Api-Key: ' . $result['token'] . '" https://your-api.com/endpoint');

            return 0;
        } catch (\Throwable $e) {
            $this->error('Failed to create API key: ' . $e->getMessage());
            return 1;
        }
    }

    public function help(): void
    {
        echo "\nUsage: php siro make:apikey <name> [scopes] [expires_days]\n\n";
        echo "Generate API key for external developers.\n\n";
        echo "Arguments:\n";
        echo "  name          Human-readable name for the key\n";
        echo "  scopes        Comma-separated: read,write,admin (default: read)\n";
        echo "  expires_days  Days until expiration (default: 365, 0 = never)\n\n";
        echo "Examples:\n";
        echo "  php siro make:apikey \"Partner A\"\n";
        echo "  php siro make:apikey \"Partner B\" \"read,write\"\n";
        echo "  php siro make:apikey \"Temp Key\" \"read\" 30\n\n";
    }
}
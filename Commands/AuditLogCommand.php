<?php

declare(strict_types=1);

namespace Siro\Core\Commands;

use Siro\Core\Audit;

/**
 * Append a manual entry to the immutable audit trail.
 *
 * Useful for recording out-of-band actions (manual DB changes, admin
 * overrides, deployments). Entries are HMAC-chained and tamper-evident.
 *
 * Usage:
 *   php siro audit:log user.manual_reset --context=user_id=5 --context=note="manual password reset"
 *
 * @package Siro\Core\Commands
 */
final class AuditLogCommand implements \Siro\Core\Commands\CommandInterface {
    use CommandSupport;

    public function __construct(string $basePath)
    {
        unset($basePath);
    }

    /** @param array<int, string> $args */
    public function run(array $args): int
    {
        $action = trim((string) ($args[0] ?? ''));
        if ($action === '') {
            $this->write('Usage: php siro audit:log <action> [--context=key=value]');
            $this->write('  Example: php siro audit:log user.manual_reset --context=user_id=5');
            return 1;
        }

        $context = [];
        foreach (array_slice($args, 1) as $arg) {
            if (str_starts_with($arg, '--context=')) {
                $pair = substr($arg, 10);
                $eq = strpos($pair, '=');
                if ($eq !== false) {
                    $key = trim(substr($pair, 0, $eq));
                    $val = trim(substr($pair, $eq + 1));
                    if ($key !== '') {
                        $context[$key] = $val;
                    }
                }
            }
        }
        $context['actor'] = ['type' => 'cli', 'id' => get_current_user(), 'ip' => '127.0.0.1'];

        $entry = Audit::log($action, $context);
        if ($entry === null) {
            $this->write('  ❌ Failed to append audit entry.');
            return 1;
        }

        $this->write('');
        $this->write('  🛡️  Audit entry appended');
        $this->write('  ' . str_repeat('-', 40));
        $this->write('  Action:  ' . $action);
        $this->write('  Index:   ' . (is_scalar($entry['index'] ?? null) ? (string) $entry['index'] : '?'));
        $this->write('  Hash:    ' . (is_string($entry['hash'] ?? null) ? substr($entry['hash'], 0, 16) : '?') . '…');
        $this->write('  Verify:  php siro audit:verify');
        $this->write('');

        return 0;
    }
}

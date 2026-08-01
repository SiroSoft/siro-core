<?php

declare(strict_types=1);

namespace Siro\Core\Commands;

use Siro\Core\Audit;

/**
 * Verify the integrity of the immutable audit trail.
 *
 * Checks the HMAC chain of every audit-*.jsonl file and reports any
 * tampering, insertion, or deletion. Exits non-zero on a broken chain.
 *
 * Usage:
 *   php siro audit:verify          # Verify today's audit file
 *   php siro audit:verify --all    # Verify every audit file
 *
 * @package Siro\Core\Commands
 */
final class AuditVerifyCommand implements \Siro\Core\Commands\CommandInterface {
    use CommandSupport;

    public function __construct(string $basePath)
    {
        unset($basePath);
    }

    /** @param array<int, string> $args */
    public function run(array $args): int
    {
        $all = in_array('--all', $args, true);

        $this->write('');
        $this->write('  🛡️  Audit Trail Verification');
        $this->write('  ' . str_repeat('=', 44));

        $files = $all ? Audit::files() : [];
        $ok = true;
        $verified = 0;

        if (!$all) {
            $result = Audit::verify();
            $files = [$result['file']];
        }

        foreach ($files as $file) {
            $result = Audit::verify($file);
            $name = basename($file);
            if ($result['ok']) {
                $this->write('  ✅ ' . str_pad($name, 32, ' ') . $result['entries'] . ' entries — chain valid');
                $verified += $result['entries'];
            } else {
                $this->write('  ❌ ' . str_pad($name, 32, ' ') . 'BROKEN at entry ' . ($result['broken'] ?? '?') . ': ' . ($result['reason'] ?? 'unknown'));
                $ok = false;
            }
        }

        $this->write('  ' . str_repeat('-', 44));
        if ($ok) {
            $this->write('  ✅ Audit trail intact — ' . $verified . ' verified entries');
        } else {
            $this->write('  ❌ Audit trail TAMPERED — investigate immediately');
        }
        $this->write('');

        return $ok ? 0 : 1;
    }
}

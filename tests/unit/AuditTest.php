<?php

declare(strict_types=1);

namespace Siro\Core\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Siro\Core\Audit;

/**
 * Audit trail tests: chain integrity, tamper and deletion detection.
 */
final class AuditTest extends TestCase
{
    private string $basePath;

    protected function setUp(): void
    {
        $this->basePath = sys_get_temp_dir() . '/siro_audit_test_' . uniqid();
        Audit::setBasePath($this->basePath);
        $dir = $this->basePath . '/storage/logs/audit';
        if (is_dir($dir)) {
            $this->cleanDir($dir);
        }
    }

    protected function tearDown(): void
    {
        Audit::resetBasePath();
        $dir = $this->basePath . '/storage/logs/audit';
        if (is_dir($dir)) {
            $this->cleanDir($dir);
        }
        if (is_dir($this->basePath)) {
            @rmdir($this->basePath . '/storage/logs');
            @rmdir($this->basePath . '/storage');
            @rmdir($this->basePath);
        }
    }

    public function testAppendCreatesChainedEntries(): void
    {
        $a = Audit::log('test.one', ['user_id' => '1']);
        $b = Audit::log('test.two', ['user_id' => '2']);
        $this->assertNotNull($a);
        $this->assertNotNull($b);
        $this->assertSame(1, $a['index']);
        $this->assertSame(2, $b['index']);
        // prev_hash of entry 2 must equal hash of entry 1
        $this->assertSame($a['hash'], $b['prev_hash']);
    }

    public function testVerifyPassesOnCleanChain(): void
    {
        Audit::log('a', []);
        Audit::log('b', []);
        Audit::log('c', []);
        $r = Audit::verify();
        $this->assertTrue($r['ok']);
        $this->assertSame(3, $r['entries']);
    }

    public function testVerifyDetectsModification(): void
    {
        Audit::log('a', ['user_id' => '1']);
        Audit::log('b', ['user_id' => '2']);
        $file = Audit::files()[0] ?? '';
        $this->assertNotSame('', $file);
        $lines = file($file);
        $this->assertIsArray($lines);
        $entry = json_decode((string) $lines[1], true);
        $this->assertIsArray($entry);
        /** @var array<string, mixed> $entry */
        $ctx = $entry['context'];
        $this->assertIsArray($ctx);
        $ctx['user_id'] = '999';
        $entry['context'] = $ctx;
        $lines[1] = json_encode($entry, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
        file_put_contents($file, implode('', $lines));

        $r = Audit::verify();
        $this->assertFalse($r['ok']);
        $this->assertSame('tampered (hash mismatch)', $r['reason'] ?? '');
    }

    public function testVerifyDetectsDeletion(): void
    {
        Audit::log('a', []);
        Audit::log('b', []);
        Audit::log('c', []);
        $file = Audit::files()[0] ?? '';
        $this->assertNotSame('', $file);
        $lines = file($file);
        $this->assertIsArray($lines);
        array_splice($lines, 1, 1);
        file_put_contents($file, implode('', $lines));

        $r = Audit::verify();
        $this->assertFalse($r['ok']);
        $this->assertStringContainsString('chain broken', (string) ($r['reason'] ?? ''));
    }

    private function cleanDir(string $dir): void
    {
        foreach (glob($dir . '/*') ?: [] as $file) {
            if (is_file($file)) {
                @unlink($file);
            }
        }
    }
}

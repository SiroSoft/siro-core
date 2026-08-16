<?php

declare(strict_types=1);

namespace Siro\Core\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * API stability baseline.
 *
 * Guards against accidental breaking changes to the public API surface.
 * A large growth in public methods (or removal of core classes) fails here,
 * signalling that the change should be reviewed before shipping as a patch.
 */
final class ApiStabilityTest extends TestCase
{
    /** @var list<string> Top-level source directories (PSR-4 under Siro\Core). */
    private const SOURCE_DIRS = ['Auth', 'Cache', 'Commands', 'DB', 'Lite', 'Logger', 'Middleware', 'Observers', 'Queue', 'Debug'];

    private const ROOT_EXCLUDE = ['benchmark.php', 'preload.php'];

    /**
     * Collect all source PHP files (root + source dirs), excluding vendor/tests.
     *
     * @return array<int, string>
     */
    private static function sourceFiles(): array
    {
        $root = dirname(__DIR__, 2);
        $files = [];
        foreach (glob($root . '/*.php') ?: [] as $f) {
            if (!in_array(basename($f), self::ROOT_EXCLUDE, true)) {
                $files[] = $f;
            }
        }
        foreach (self::SOURCE_DIRS as $dir) {
            $path = $root . '/' . $dir;
            if (!is_dir($path)) continue;
            $rii = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS)
            );
            foreach ($rii as $entry) {
                /** @var \SplFileInfo $entry */
                if ($entry->isFile() && $entry->getExtension() === 'php') {
                    $files[] = $entry->getPathname();
                }
            }
        }
        return $files;
    }

    public function testPublicApiMethodCountWithinTolerance(): void
    {
        $total = 0;
        foreach (self::sourceFiles() as $file) {
            $content = file_get_contents($file);
            if ($content === false) continue;
            preg_match_all('/public\s+function\s+(?!__)[A-Za-z0-9_]+/i', $content, $m);
            $total += count($m[0]);
        }
        $this->assertGreaterThan(100, $total, 'Sanity: expected a large public API surface');
        $baseline = 800; // snapshot at v0.35.1 (actual: 853); update after each major release
        $this->assertLessThanOrEqual(
            (int) ($baseline * 1.15),
            $total,
            sprintf('Public API grew from ~%d to %d methods — review before a patch release.', $baseline, $total)
        );
    }

    public function testCorePublicClassesExist(): void
    {
        foreach (['Request', 'Response', 'Router', 'App', 'Model', 'Collection', 'Validator', 'DB', 'Schema', 'Env', 'Config', 'Event', 'Queue', 'Mail', 'Storage', 'Hash', 'Encrypter', 'Logger', 'Container', 'Console', 'Audit'] as $class) {
            $this->assertTrue(
                class_exists('Siro\\Core\\' . $class) || interface_exists('Siro\\Core\\' . $class),
                "Public class Siro\\Core\\{$class} missing"
            );
        }
    }
}

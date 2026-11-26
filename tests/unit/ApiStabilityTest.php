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
        $baseline = 929; // snapshot at v1.5.0 (actual: 929; +31 vs 1.4.2: Auth +23 OAuth2, Commands +7, Middleware +1); update after each major release
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

    /**
     * G7: the committed fixture OpenAPI spec must be exactly what
     * make:openapi generates from the fixture routes — no hand edits.
     * Regenerates on a temp copy (so the public/ side-effects never touch
     * the checkout) and compares the (method, path) operation sets both
     * ways, plus asserts every committed operation declares responses.
     */
    public function testOpenApiSpecMatchesRoutes(): void
    {
        $fixture = dirname(__DIR__) . '/fixtures/contract-app';
        $committed = $fixture . '/docs/openapi/openapi.json';
        $this->assertFileExists($committed, 'Committed fixture spec must exist.');

        $tmp = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'siro_openapi_' . uniqid('', true);
        mkdir($tmp, 0777, true);
        $this->copyDir($fixture, $tmp);
        try {
            putenv('JWT_SECRET=placeholder_jwt_secret_for_tests_only_0000');
            putenv('APP_KEY=placeholder_app_key_for_tests_only_0000');
            $generated = $tmp . DIRECTORY_SEPARATOR . 'openapi.json';
            $code = (new \Siro\Core\Console($tmp))->run(['siro', 'make:openapi', '--force', '--output=' . $generated]);
            $this->assertSame(0, $code, 'make:openapi must succeed on the fixture app.');
            $this->assertFileExists($generated);

            $genSpec = json_decode((string) file_get_contents($generated), true);
            $comSpec = json_decode((string) file_get_contents($committed), true);
            $this->assertIsArray($genSpec);
            $this->assertIsArray($comSpec);
            $this->assertSame(
                self::operationSet($genSpec),
                self::operationSet($comSpec),
                'Committed spec operations must equal freshly generated operations.'
            );
            foreach ($comSpec['paths'] as $path => $operations) {
                foreach ($operations as $method => $operation) {
                    $this->assertNotEmpty(
                        $operation['responses'] ?? null,
                        "Committed operation {$method} {$path} must declare responses."
                    );
                }
            }
        } finally {
            $this->removeDir($tmp);
        }
    }

    /**
     * @param array<string, mixed> $spec
     * @return list<string> Sorted "METHOD path" entries.
     */
    private static function operationSet(array $spec): array
    {
        $set = [];
        foreach ($spec['paths'] ?? [] as $path => $operations) {
            if (!is_array($operations)) {
                continue;
            }
            foreach (array_keys($operations) as $method) {
                $set[] = strtoupper((string) $method) . ' ' . $path;
            }
        }
        sort($set);
        return $set;
    }

    private function copyDir(string $src, string $dst): void
    {
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($src, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );
        foreach ($it as $entry) {
            /** @var \SplFileInfo $entry */
            $target = $dst . DIRECTORY_SEPARATOR . $it->getSubPathName();
            if ($entry->isDir()) {
                mkdir($target, 0777, true);
            } else {
                copy($entry->getPathname(), $target);
            }
        }
    }

    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($it as $entry) {
            /** @var \SplFileInfo $entry */
            $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
        }
        rmdir($dir);
    }
}

<?php

declare(strict_types=1);

namespace Siro\Core\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Guards against version drift between the version surfaces.
 *
 * Mirrors the version-consistency gate in scripts/release-check.php, but
 * runs inside the suite so a drift fails `phpunit` itself, not just the
 * release script:
 *   Console::VERSION === CHANGELOG head === APP_VERSION in .env.siro/.env.bench
 *   (=== git tag pointing at HEAD, when HEAD is tagged).
 */
final class VersionDriftTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = dirname(__DIR__, 2);
    }

    public function testVersionMatchesChangelogHead(): void
    {
        $version = $this->consoleVersion();
        $this->assertMatchesRegularExpression('/^\d+\.\d+\.\d+$/', $version);

        $changelog = (string) file_get_contents($this->root . '/CHANGELOG.md');
        $this->assertMatchesRegularExpression(
            '/^## v(\d+\.\d+\.\d+)/m',
            $changelog,
            'CHANGELOG.md has no versioned head entry'
        );
        preg_match('/^## v(\d+\.\d+\.\d+)/m', $changelog, $m);

        $this->assertSame($version, $m[1], 'CHANGELOG head != Console::VERSION');
    }

    public function testVersionMatchesEnvFiles(): void
    {
        $version = $this->consoleVersion();

        foreach (['.env.siro', '.env.bench'] as $envFile) {
            $path = $this->root . '/' . $envFile;
            if (!is_file($path)) {
                continue;
            }
            $env = (string) file_get_contents($path);
            $this->assertMatchesRegularExpression(
                '/^APP_VERSION=(.+)$/m',
                $env,
                "{$envFile} has no APP_VERSION"
            );
            preg_match('/^APP_VERSION=(.+)$/m', $env, $m);
            $this->assertSame(
                $version,
                trim($m[1], "\"' "),
                "{$envFile} APP_VERSION != Console::VERSION"
            );
        }
    }

    public function testTagMatchesVersionWhenHeadIsTagged(): void
    {
        if (!function_exists('shell_exec')) {
            $this->markTestSkipped('shell_exec() unavailable — cannot read git tags.');
        }
        $tag = trim((string) shell_exec(
            'git -C ' . escapeshellarg($this->root) . ' tag --points-at HEAD 2>/dev/null'
        ));
        if ($tag === '') {
            $this->markTestSkipped('HEAD has no tag — tag check only applies on release commits.');
        }
        // A release commit carries exactly one version tag (vX.Y.Z).
        $tags = array_values(array_filter(explode("\n", $tag)));
        $versionTags = array_values(array_filter($tags, static fn (string $t): bool => (bool) preg_match('/^v\d+\.\d+\.\d+$/', $t)));
        $this->assertNotEmpty($versionTags, 'HEAD is tagged but has no vX.Y.Z tag.');
        $this->assertSame($this->consoleVersion(), ltrim($versionTags[0], 'v'), 'git tag != Console::VERSION');
    }

    private function consoleVersion(): string
    {
        $content = (string) file_get_contents($this->root . '/Console.php');
        $this->assertMatchesRegularExpression(
            "/const VERSION = '([^']+)'/",
            $content,
            'Cannot parse Console::VERSION'
        );
        preg_match("/const VERSION = '([^']+)'/", $content, $m);

        return $m[1];
    }
}

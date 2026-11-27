<?php

declare(strict_types=1);

namespace Siro\Core\Tests\Cli;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Siro\Core\Config;
use Siro\Core\Console;
use Siro\Core\Env;

/**
 * Regression tests for the META lane of the CLI smoke-fix round
 * (CLI-SMOKE-2026-11-27.md, META-M1): `help <command>` used to ignore its
 * argument and print the general 102-command help. It must now print the
 * same per-command help as `<command> --help`.
 *
 * Also pins the cross-checks for the fix: `list --raw` / `list --json`
 * still report the same command count, and every `<command> --help`
 * still exits 0 without side effects (see CommandSmokeAllTest).
 */
final class SmokeFixMetaTest extends TestCase
{
    private Console $console;
    private string $tempDir;

    protected function setUp(): void
    {
        Env::reset();
        Config::reset();

        $this->tempDir = sys_get_temp_dir() . '/siro_smoke_fix_meta_' . bin2hex(random_bytes(4));
        @mkdir($this->tempDir . '/storage/framework', 0777, true);

        $this->console = new Console($this->tempDir);
    }

    protected function tearDown(): void
    {
        $this->rrmdir($this->tempDir);
        Env::reset();
        Config::reset();
    }

    private function rrmdir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (array_diff(scandir($dir), ['.', '..']) as $item) {
            $path = $dir . '/' . $item;
            is_dir($path) ? $this->rrmdir($path) : unlink($path);
        }
        rmdir($dir);
    }

    /** @param array<int, string> $argv @return array{int, string} */
    private function runConsole(array $argv): array
    {
        ob_start();
        $exit = $this->console->run($argv);
        $output = (string) ob_get_clean();
        return [$exit, $output];
    }

    // ==================== META-M1: help <command> ====================

    #[Test]
    public function helpWithCommandShowsThatCommandsHelp(): void
    {
        [$exit, $output] = $this->runConsole(['siro', 'help', 'make:crud']);
        $this->assertSame(0, $exit, 'help make:crud should exit 0');

        [, $direct] = $this->runConsole(['siro', 'make:crud', '--help']);
        $this->assertSame($direct, $output, 'help make:crud must print exactly what make:crud --help prints');
        $this->assertStringContainsString('make:crud', $output);
    }

    /** @return array<int, array{string}> */
    public static function helpTargetProvider(): array
    {
        return [
            ['make:crud'],
            ['serve'],
            ['db:show'],
            ['log:slow'],
            ['api:test'],
            ['schedule:list'],
            ['route:cache'],
            ['queue:work'],
        ];
    }

    #[DataProvider('helpTargetProvider')]
    #[Test]
    public function helpWithCommandMatchesDirectHelpFlag(string $command): void
    {
        [$exit, $output] = $this->runConsole(['siro', 'help', $command]);
        $this->assertSame(0, $exit, "help {$command} should exit 0");

        [, $direct] = $this->runConsole(['siro', $command, '--help']);
        $this->assertSame($direct, $output, "help {$command} must match {$command} --help");
    }

    #[Test]
    public function helpWithoutArgsStillShowsGeneralHelp(): void
    {
        [$exit, $output] = $this->runConsole(['siro', 'help']);
        $this->assertSame(0, $exit, 'help with no args should exit 0');
        $this->assertStringContainsString('PHP Micro-Framework', $output);
        $this->assertStringContainsString('php siro <command> --help', $output);

        [, $flagHelp] = $this->runConsole(['siro', '--help']);
        $this->assertSame($flagHelp, $output, 'help with no args must match --help');
    }

    #[Test]
    public function helpUnknownCommandExitsNonZeroWithClearMessage(): void
    {
        [$exit, $output] = $this->runConsole(['siro', 'help', 'khong-co-lenh-nay']);
        $this->assertSame(1, $exit, 'help <unknown> should exit 1');
        $this->assertStringContainsString('Unknown command:', $output);
        $this->assertStringContainsString('khong-co-lenh-nay', $output);
        $this->assertStringNotContainsString('PHP Micro-Framework', $output, 'help <unknown> must not fall back to the general help screen');
    }

    #[Test]
    public function helpNearMissSuggestsTheRightCommand(): void
    {
        [$exit, $output] = $this->runConsole(['siro', 'help', 'make:crdu']);
        $this->assertSame(1, $exit, 'help make:crdu should exit 1');
        $this->assertStringContainsString('Did you mean?', $output);
        $this->assertStringContainsString('make:crud', $output);
    }

    #[Test]
    public function helpResolvesShortcutsAndAliases(): void
    {
        [$exitT, $outT] = $this->runConsole(['siro', 'help', 't']);
        $this->assertSame(0, $exitT);
        [, $directApiTest] = $this->runConsole(['siro', 'api:test', '--help']);
        $this->assertSame($directApiTest, $outT, 'help t must match api:test --help');

        [$exitSlow, $outSlow] = $this->runConsole(['siro', 'help', 'slow']);
        $this->assertSame(0, $exitSlow);
        [, $directSlow] = $this->runConsole(['siro', 'log:slow', '--help']);
        $this->assertSame($directSlow, $outSlow, 'help slow must match log:slow --help');

        [$exitTink, $outTink] = $this->runConsole(['siro', 'help', 'tink']);
        $this->assertSame(0, $exitTink);
        [, $directTinker] = $this->runConsole(['siro', 'tinker', '--help']);
        $this->assertSame($directTinker, $outTink, 'help tink must match tinker --help');
    }

    #[Test]
    public function helpResolvesVirtualCommands(): void
    {
        [, $outDocs] = $this->runConsole(['siro', 'help', 'make:docs']);
        [, $directDocs] = $this->runConsole(['siro', 'make:docs', '--help']);
        $this->assertSame($directDocs, $outDocs, 'help make:docs must match make:docs --help');

        [, $outPostman] = $this->runConsole(['siro', 'help', 'open:postman']);
        [, $directPostman] = $this->runConsole(['siro', 'open:postman', '--help']);
        $this->assertSame($directPostman, $outPostman, 'help open:postman must match open:postman --help');
    }

    #[Test]
    public function helpMetaNamesShowTheirOwnScreens(): void
    {
        [, $outList] = $this->runConsole(['siro', 'help', 'list']);
        [, $directList] = $this->runConsole(['siro', 'list']);
        $this->assertSame($directList, $outList, 'help list must match list');

        [, $outStart] = $this->runConsole(['siro', 'help', 'start']);
        [, $directStart] = $this->runConsole(['siro', 'start']);
        $this->assertSame($directStart, $outStart, 'help start must match start');

        [, $outHelp] = $this->runConsole(['siro', 'help', 'help']);
        [, $directHelp] = $this->runConsole(['siro', 'help']);
        $this->assertSame($directHelp, $outHelp, 'help help must match help');
    }

    #[Test]
    public function helpFlagWithCommandArgShowsCommandHelp(): void
    {
        [, $direct] = $this->runConsole(['siro', 'make:crud', '--help']);

        [, $outDashH] = $this->runConsole(['siro', '-h', 'make:crud']);
        $this->assertSame($direct, $outDashH, '-h make:crud must match make:crud --help');

        [, $outDashDashHelp] = $this->runConsole(['siro', '--help', 'make:crud']);
        $this->assertSame($direct, $outDashDashHelp, '--help make:crud must match make:crud --help');
    }

    // ==================== Cross-checks after the fix ====================

    #[Test]
    public function listRawAndJsonStillReport102Commands(): void
    {
        // v1.5.0 command count at base 975b068 (100 + schedule:list + route:cache).
        // The fix must not add or remove any command.
        [$exitRaw, $outRaw] = $this->runConsole(['siro', 'list', '--raw']);
        $this->assertSame(0, $exitRaw);
        $rawCommands = array_values(array_filter(explode("\n", trim($outRaw)), fn(string $l) => $l !== ''));
        $this->assertCount(102, $rawCommands, 'list --raw must still list 102 commands');

        [$exitJson, $outJson] = $this->runConsole(['siro', 'list', '--json']);
        $this->assertSame(0, $exitJson);
        $decoded = json_decode($outJson, true);
        $this->assertIsArray($decoded, 'list --json must output valid JSON');
        $total = 0;
        foreach ($decoded as $group) {
            $total += count($group['commands'] ?? []);
        }
        $this->assertSame(102, $total, 'list --json must still list 102 commands');
        $jsonCommands = [];
        foreach ($decoded as $group) {
            foreach ($group['commands'] ?? [] as $item) {
                $jsonCommands[] = $item['command'];
            }
        }
        sort($rawCommands);
        sort($jsonCommands);
        $this->assertSame($rawCommands, $jsonCommands, 'list --raw and list --json must name the same 102 commands');
    }

    // ==================== Pinned current behaviour (needs a decision) ====================

    /**
     * tinker/tink refuse to run without a TTY but still exit 0.
     * This pins the CURRENT behaviour recorded in CLI-SMOKE-2026-11-27.md
     * (META-M1 notes / meta.md note 4): changing it to a non-zero exit
     * needs an explicit coordinator decision, so update this test then.
     */
    #[Test]
    public function tinkerWithoutTtyRefusesButExitsZero(): void
    {
        [$exit, $output] = $this->runConsole(['siro', 'tinker']);
        $this->assertSame(0, $exit);
        $this->assertStringContainsString('Tinker requires an interactive terminal.', $output);

        [$exitTink, $outputTink] = $this->runConsole(['siro', 'tink']);
        $this->assertSame(0, $exitTink);
        $this->assertSame($output, $outputTink);
    }
}

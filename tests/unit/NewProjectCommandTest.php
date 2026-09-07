<?php

declare(strict_types=1);

namespace Siro\Core\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Siro\Core\Commands\NewProjectCommand;

/**
 * Covers the target resolver behind `siro new` (see D1_SOAK_REPORT.md:
 * an absolute target path such as `siro new /tmp/app` previously created
 * ./tmp/app instead of /tmp/app).
 */
final class NewProjectCommandTest extends TestCase
{
    public function testAbsolutePosixPathIsUsedAsIs(): void
    {
        $this->assertSame('/tmp/siro-app', NewProjectCommand::resolveTarget('/tmp/siro-app'));
    }

    public function testWindowsDrivePathIsUsedAsIs(): void
    {
        $this->assertSame('C:\\work\\siro-app', NewProjectCommand::resolveTarget('C:\\work\\siro-app'));
    }

    public function testWindowsDrivePathWithForwardSlashesIsUsedAsIs(): void
    {
        $this->assertSame('C:/work/siro-app', NewProjectCommand::resolveTarget('C:/work/siro-app'));
    }

    public function testUncPathIsUsedAsIs(): void
    {
        $this->assertSame('\\\\server\\share\\siro-app', NewProjectCommand::resolveTarget('\\\\server\\share\\siro-app'));
    }

    public function testBareNameIsAnchoredToCwd(): void
    {
        $expected = getcwd() . DIRECTORY_SEPARATOR . 'siro-app';
        $this->assertSame($expected, NewProjectCommand::resolveTarget('siro-app'));
    }

    public function testRelativeSubpathIsAnchoredToCwd(): void
    {
        $expected = getcwd() . DIRECTORY_SEPARATOR . 'apps' . DIRECTORY_SEPARATOR . 'siro-app';
        $this->assertSame($expected, NewProjectCommand::resolveTarget('apps/siro-app'));
    }

    public function testCommandIsRegisteredShape(): void
    {
        $this->assertTrue(class_exists(NewProjectCommand::class));
        $cmd = new NewProjectCommand(sys_get_temp_dir());
        $this->assertTrue(method_exists($cmd, 'run'));
        $this->assertContains('resolveTarget', get_class_methods(NewProjectCommand::class));
    }
}

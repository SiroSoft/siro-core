<?php

declare(strict_types=1);

namespace Siro\Core\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * CommandSupport trait coverage: table, studly, singular, plural, safeStr,
 * trace-file discovery helpers.
 */
final class CommandSupportTest extends TestCase
{
    private string $tracesDir;

    protected function setUp(): void
    {
        $this->tracesDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'siro_traces_' . uniqid('', true);
        mkdir($this->tracesDir . DIRECTORY_SEPARATOR . '2024' . DIRECTORY_SEPARATOR . '01', 0777, true);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->tracesDir)) {
            $this->rmDir($this->tracesDir);
        }
    }

    private function rmDir(string $dir): void
    {
        foreach (scandir($dir) as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . DIRECTORY_SEPARATOR . $entry;
            if (is_dir($path)) {
                $this->rmDir($path);
            } else {
                @unlink($path);
            }
        }
        @rmdir($dir);
    }

    private function support(): CssSupportProxy
    {
        return new CssSupportProxy();
    }

    public function testTableOutput(): void
    {
        ob_start();
        $this->support()->callTable(['A', 'B'], [['1', '2'], ['3', '4']]);
        $out = ob_get_clean();
        $this->assertStringContainsString('A', $out);
        $this->assertStringContainsString('B', $out);
    }

    public function testTableEmptyReturns(): void
    {
        ob_start();
        $this->support()->callTable([], []);
        $out = ob_get_clean();
        $this->assertSame('', $out);
    }

    public function testStudly(): void
    {
        $this->assertSame('HelloWorld', $this->support()->callStudly('hello_world'));
        $this->assertSame('FooBar', $this->support()->callStudly('foo-bar'));
        $this->assertSame('Foo', $this->support()->callStudly('foo@@@'));
    }

    public function testSingular(): void
    {
        $this->assertSame('category', $this->support()->callSingular('categories'));
        $this->assertSame('user', $this->support()->callSingular('users'));
        $this->assertSame('data', $this->support()->callSingular('data'));
    }

    public function testPlural(): void
    {
        $this->assertSame('users', $this->support()->callPlural('users'));
        $this->assertSame('boxes', $this->support()->callPlural('box'));
        $this->assertSame('categories', $this->support()->callPlural('category'));
        $this->assertSame('apps', $this->support()->callPlural('app'));
    }

    public function testSafeStr(): void
    {
        $this->assertSame('hi', $this->support()->callSafeStr('hi'));
        $this->assertSame('42', $this->support()->callSafeStr(42));
        $this->assertSame('x', $this->support()->callSafeStr(null, 'x'));
    }

    public function testFindTraceFilesEmptyDir(): void
    {
        $this->assertSame([], $this->support()->callFindTraceFiles($this->tracesDir . DIRECTORY_SEPARATOR . 'nonexistent'));
    }

    public function testFindTraceFilesFindsJson(): void
    {
        $sub = $this->tracesDir . DIRECTORY_SEPARATOR . '2024' . DIRECTORY_SEPARATOR . '01' . DIRECTORY_SEPARATOR . 'abc';
        mkdir($sub, 0777, true);
        file_put_contents($sub . DIRECTORY_SEPARATOR . 'trace1.json', '{}');
        file_put_contents($sub . DIRECTORY_SEPARATOR . 'trace1.txt', 'not json');
        $files = $this->support()->callFindTraceFiles($this->tracesDir);
        $this->assertCount(1, $files);
        $this->assertStringEndsWith('.json', $files[0]);
    }

    public function testFindRecentTraceFiles(): void
    {
        $sub = $this->tracesDir . DIRECTORY_SEPARATOR . '2024' . DIRECTORY_SEPARATOR . '01' . DIRECTORY_SEPARATOR . 'abc';
        mkdir($sub, 0777, true);
        file_put_contents($sub . DIRECTORY_SEPARATOR . 'a.json', '{}');
        file_put_contents($sub . DIRECTORY_SEPARATOR . 'b.json', '{}');
        $files = $this->support()->callFindRecentTraceFiles($this->tracesDir, 10);
        $this->assertCount(2, $files);
    }

    public function testFindRecentTraceFilesEmpty(): void
    {
        $this->assertSame([], $this->support()->callFindRecentTraceFiles($this->tracesDir . DIRECTORY_SEPARATOR . 'nonexistent', 5));
        $this->assertSame([], $this->support()->callFindRecentTraceFiles($this->tracesDir, 0));
    }

    public function testFindTraceByIdDirect(): void
    {
        file_put_contents($this->tracesDir . DIRECTORY_SEPARATOR . 'traceX.json', '{}');
        $found = $this->support()->callFindTraceById($this->tracesDir, 'traceX');
        $this->assertStringEndsWith('traceX.json', (string) $found);
    }

    public function testFindTraceByIdNested(): void
    {
        $sub = $this->tracesDir . DIRECTORY_SEPARATOR . date('Y') . DIRECTORY_SEPARATOR . date('m') . DIRECTORY_SEPARATOR . date('d') . DIRECTORY_SEPARATOR . 'abc';
        mkdir($sub, 0777, true);
        file_put_contents($sub . DIRECTORY_SEPARATOR . 'nested.json', '{}');
        $found = $this->support()->callFindTraceById($this->tracesDir, 'nested');
        $this->assertStringEndsWith('nested.json', (string) $found);
    }

    public function testFindTraceByIdMissing(): void
    {
        $this->assertNull($this->support()->callFindTraceById($this->tracesDir, 'zzz'));
    }

    public function testWriteVariants(): void
    {
        $p = $this->support();
        foreach ([
            'callInfo' => 'info',
            'callSuccess' => 'success',
            'callError' => 'error',
            'callWarn' => 'warn',
            'callHighlight' => 'highlight',
            'callComment' => 'comment',
        ] as $method => $text) {
            ob_start();
            $p->{$method}($text . ' message');
            $out = ob_get_clean();
            $this->assertStringContainsString($text, $out);
        }
    }

    public function testConfirmOverwriteYes(): void
    {
        $cmd = new \Siro\Core\Commands\LogReplayCommand($this->tracesDir, function (): string {
            return 'y';
        });
        $ref = new \ReflectionClass(\Siro\Core\Commands\LogReplayCommand::class);
        $m = $ref->getMethod('confirmOverwrite');
        $m->setAccessible(true);
        $result = $m->invoke($cmd, '/base', '/base/file.php');
        $this->assertTrue($result);
    }

    public function testConfirmOverwriteNo(): void
    {
        $cmd = new \Siro\Core\Commands\LogReplayCommand($this->tracesDir, function (): string {
            return 'n';
        });
        $ref = new \ReflectionClass(\Siro\Core\Commands\LogReplayCommand::class);
        $m = $ref->getMethod('confirmOverwrite');
        $m->setAccessible(true);
        $result = $m->invoke($cmd, '/base', '/base/file.php');
        $this->assertFalse($result);
    }
}

final class CssSupportProxy
{
    use \Siro\Core\Commands\CommandSupport;

    public function callTable(array $headers, array $rows): void
    {
        $this->table($headers, $rows);
    }

    public function callInfo(string $m): void
    {
        $this->info($m);
    }

    public function callSuccess(string $m): void
    {
        $this->success($m);
    }

    public function callError(string $m): void
    {
        $this->error($m);
    }

    public function callWarn(string $m): void
    {
        $this->warn($m);
    }

    public function callHighlight(string $m): void
    {
        $this->highlight($m);
    }

    public function callComment(string $m): void
    {
        $this->comment($m);
    }

    public function callStudly(string $v): string
    {
        return $this->studly($v);
    }

    public function callSingular(string $v): string
    {
        return $this->singular($v);
    }

    public function callPlural(string $v): string
    {
        return $this->plural($v);
    }

    public function callSafeStr(mixed $v, string $d = ''): string
    {
        return $this->safeStr($v, $d);
    }

    /** @return array<int, string> */
    public function callFindTraceFiles(string $dir): array
    {
        return $this->findTraceFiles($dir);
    }

    /** @return array<int, string> */
    public function callFindRecentTraceFiles(string $dir, int $limit): array
    {
        return $this->findRecentTraceFiles($dir, $limit);
    }

    public function callFindTraceById(string $dir, string $id): ?string
    {
        return $this->findTraceById($dir, $id);
    }
}

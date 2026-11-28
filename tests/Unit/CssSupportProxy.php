<?php

declare(strict_types=1);

namespace Siro\Core\Tests\Unit;

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

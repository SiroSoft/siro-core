<?php

declare(strict_types=1);

namespace Siro\Core;

final class ScheduleTask
{
    public string $type;
    public mixed $task;
    private string $expression = '* * * * *';
    /** @phpstan-ignore property.onlyWritten */
    private int $lastRun = 0;
    private bool $withoutOverlapping = false;
    private string $mutexKey = '';

    public function __construct(string $type, mixed $task)
    {
        $this->type = $type;
        $this->task = $task;
    }

    public function cron(string $expression): self
    {
        $this->expression = $expression;
        return $this;
    }

    public function everyMinute(): self     { return $this->cron('* * * * *'); }
    public function hourly(): self           { return $this->cron('0 * * * *'); }
    public function daily(): self            { return $this->cron('0 0 * * *'); }
    public function weekly(): self           { return $this->cron('0 0 * * 0'); }
    public function monthly(): self          { return $this->cron('0 0 1 * *'); }

    public function dailyAt(string $time): self
    {
        [$hour, $minute] = explode(':', $time) + [0, 0];
        return $this->cron(sprintf('%d %d * * *', (int) $minute, (int) $hour));
    }

    public function isDue(int $now): bool
    {
        return $this->matchesCron($now);
    }

    public function withoutOverlapping(int $expires = 1440): self
    {
        $this->withoutOverlapping = true;
        $this->mutexKey = 'schedule:' . sha1($this->expression . '_' . ($this->task instanceof \Closure ? spl_object_id($this->task) : (is_string($this->task) ? $this->task : (is_object($this->task) ? get_class($this->task) : ''))));
        if ($expires > 0) {
            Cache::remember($this->mutexKey, $expires, fn() => true);
        }
        return $this;
    }

    public function isLocked(): bool
    {
        if (!$this->withoutOverlapping) {
            return false;
        }
        return Cache::has($this->mutexKey);
    }

    public function unlock(): void
    {
        if ($this->mutexKey !== '') {
            Cache::forget($this->mutexKey);
        }
    }

    public function markRun(int $now): void
    {
        $this->lastRun = $now;
        if ($this->withoutOverlapping && $this->mutexKey !== '') {
            Cache::remember($this->mutexKey, 1440, fn() => true);
        }
    }

    private function matchesCron(int $now): bool
    {
        $parts = explode(' ', $this->expression);
        if (count($parts) !== 5) return false;

        return $this->cronMatch((int) date('i', $now), $parts[0])
            && $this->cronMatch((int) date('G', $now), $parts[1])
            && $this->cronMatch((int) date('j', $now), $parts[2])
            && $this->cronMatch((int) date('n', $now), $parts[3])
            && $this->cronMatch((int) date('w', $now), $parts[4]);
    }

    private function cronMatch(int $value, string $pattern): bool
    {
        if ($pattern === '*') return true;

        if (str_contains($pattern, ',')) {
            $parts = explode(',', $pattern);
            foreach ($parts as $part) {
                if ($this->cronMatchSingle($value, trim($part))) {
                    return true;
                }
            }
            return false;
        }

        return $this->cronMatchSingle($value, $pattern);
    }

    private function cronMatchSingle(int $value, string $pattern): bool
    {
        $step = 1;
        if (str_contains($pattern, '/')) {
            [$rangePart, $stepPart] = explode('/', $pattern, 2);
            $step = (int) $stepPart;
            if ($step <= 0) {
                return false;
            }
            $pattern = $rangePart;
        }

        if ($pattern === '*') {
            return $value % $step === 0;
        }

        if (str_contains($pattern, '-')) {
            [$start, $end] = explode('-', $pattern, 2);
            $start = (int) trim($start);
            $end = (int) trim($end);
            if ($value < $start || $value > $end) {
                return false;
            }
            if ($step > 1) {
                return ($value - $start) % $step === 0;
            }
            return true;
        }

        if ($step > 1) {
            return $value === (int) $pattern || ($value > (int) $pattern && ($value - (int) $pattern) % $step === 0);
        }

        return (int) $pattern === $value;
    }
}

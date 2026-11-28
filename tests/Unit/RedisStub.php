<?php

declare(strict_types=1);

namespace Siro\Core\Tests\Unit;

/**
 * Minimal \Redis stand-in. Extends \Redis so the RedisDriver type-hint keeps
 * working; every method the driver touches is overridden with in-memory
 * behaviour.
 *
 * Signature-compatibility contract (verified against phpredis 5.3.7 and the
 * 6.x signature set that broke CI — Redis::dbSize(): Redis|int|false):
 * - parameters stay UNTYPED: widening/omitting param types is always legal;
 * - overrides declare their OWN covariant return types, each a subset of the
 *   strictest (6.x) native union AND a legal addition over 5.x's untyped
 *   declarations. This survives any phpredis release: a future parent may
 *   only narrow (child ⊇ parent stays valid) or keep types untyped.
 * - omitting the return type instead would be fatal whenever the loaded
 *   parent declares one — exactly the ubuntu CI fatal this file once had.
 *
 * @codeCoverageIgnore
 */

final class RedisStub extends \Redis
{
    /** Satisfied natively via inherited signatures when ext-redis is present. */
    private const NATIVE = true;
    /** @var list<array<int, string>|false> */
    public array $scanBatches = [];

    /** @var list<string> */
    public array $deletedKeys = [];

    /** @var list<string> */
    public array $delArguments = [];

    /** @var array<string, string> */
    public array $store = [];

    /** @var int|string */
    public $dbSizeValue = 0;

    public bool $flushDbCalled = false;

    public int $lastSetexTtl = 0;

    private int $batchIndex = 0;

    /** @param int|null $iterator */
    public function scan(&$iterator, $pattern = null, $count = 0, $type = null): array|false
    {
        if ($this->batchIndex >= count($this->scanBatches)) {
            $iterator = 0;

            return [];
        }
        $batch = $this->scanBatches[$this->batchIndex++];
        $iterator = $this->batchIndex < count($this->scanBatches) ? $this->batchIndex : 0;

        return $batch;
    }

    /** Number of keys removed (phpredis native signature). */
    public function del($key, ...$otherKeys): int|false
    {
        $keys = is_array($key) ? $key : [$key, ...$otherKeys];
        $removed = 0;
        foreach ($keys as $k) {
            $k = (string) $k;
            $this->delArguments[] = $k;
            // Keys arriving here come straight from a scan() batch, so they
            // exist server-side and each counts as removed.
            $this->deletedKeys[] = $k;
            unset($this->store[$k]);
            $removed++;
        }

        return $removed;
    }

    /** Stored payload or false on miss (phpredis native). */
    public function get($key): string|false
    {
        return $this->store[$key] ?? false;
    }

    public function setex($key, $ttl, $value): bool
    {
        $this->lastSetexTtl = (int) $ttl;
        $this->store[$key] = (string) $value;

        return true;
    }

    public function dbSize(): int
    {
        return (int) $this->dbSizeValue;
    }

    public function flushDB($async = null): bool
    {
        $this->flushDbCalled = true;
        $this->store = [];

        return true;
    }
}

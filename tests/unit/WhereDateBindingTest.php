<?php

declare(strict_types=1);

namespace Siro\Core\Tests\Unit;

use Siro\Core\DB\QueryBuilder;
use Siro\Core\Tests\TestCase;

/**
 * Regression: whereDate()/whereMonth()/whereDay()/whereYear()/whereTime()
 * used to emit a positional `?` placeholder with a *named* binding key,
 * which PDO rejects with HY093 ("mixed named and positional parameters")
 * as soon as any other where() exists in the query (found via bank
 * auto-match on ERP prod). They must compile to named placeholders only.
 */
final class WhereDateBindingTest extends TestCase
{
    /**
     * @return array{0: string, 1: array<int|string, mixed>}
     */
    private function compiled(QueryBuilder $qb): array
    {
        return $qb->toCompiled();
    }

    public function testWhereDateWithOtherWheresHasNoPositionalPlaceholder(): void
    {
        $qb = (new QueryBuilder('orders'))
            ->where('total_amount', '>=', 100)
            ->whereDate('paid_at', '2026-09-01')
            ->where('payment_status', 'paid');
        [$sql, $bindings] = $this->compiled($qb);
        $this->assertStringNotContainsString('?', $sql);
        $this->assertStringContainsString('DATE(', $sql);
        foreach ($bindings as $key => $value) {
            $this->assertIsString($key);
            $this->assertStringContainsString(':' . $key, $sql);
        }
        $this->assertContains('2026-09-01', $bindings);
    }

    public function testAllDatePartsCompileToNamedPlaceholders(): void
    {
        foreach (['whereMonth' => 'MONTH(', 'whereDay' => 'DAY(', 'whereYear' => 'YEAR(', 'whereTime' => 'TIME('] as $method => $fn) {
            $qb = (new QueryBuilder('orders'))
                ->where('id', 'x')
                ->$method('created_at', '2026-09-01');
            [$sql, $bindings] = $this->compiled($qb);
            $this->assertStringNotContainsString('?', $sql, $method);
            $this->assertStringContainsString($fn, $sql, $method);
            $this->assertContains('2026-09-01', $bindings, $method);
        }
    }

    public function testWhereDateOperatorFormStillWorks(): void
    {
        $qb = (new QueryBuilder('orders'))->whereDate('paid_at', '>=', '2026-09-01');
        [$sql, $bindings] = $this->compiled($qb);
        $this->assertStringContainsString('>=', $sql);
        $this->assertStringNotContainsString('?', $sql);
        $this->assertContains('2026-09-01', $bindings);
    }
}

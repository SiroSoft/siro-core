<?php

declare(strict_types=1);

namespace Siro\Core\Tests\Unit;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;


final class PropertyBasedTest extends TestCase
{
    #[DataProvider('provideStrings')]
    public function testValidatorEmailRuleNeverThrows(mixed $value): void
    {
        \Siro\Core\Validator::extend('__pbt_never_fails', fn() => true);
        $result = \Siro\Core\Validator::make(
            ['field' => $value],
            ['field' => 'email']
        );
        $this->assertIsArray($result);
    }

    /** @return iterable<array{mixed}> */
    public static function provideStrings(): iterable
    {
        // Extreme strings
        yield [str_repeat('a', 10000)];
        yield [str_repeat('a', 100000)];
        yield [str_repeat('!@#$%', 1000)];
        yield ["\0\0\0\0"];
        yield ["\n\r\t"];
        yield [str_repeat("\xFF", 1000)];
        yield [mb_convert_encoding('日本語', 'SJIS', 'UTF-8')];
        yield [''];

        // Edge case values
        yield [INF];
        yield [NAN];
        yield [PHP_INT_MAX];
        yield [PHP_INT_MIN];
        yield [1.0e-300];
        yield [1.0e+300];

        // Structured data
        yield [['key' => 'value', 'nested' => ['a' => 1]]];
        yield [(object) ['x' => 1]];
        yield [fopen('php://memory', 'r')];

        // Unusual strings
        yield ['00000000-0000-0000-0000-000000000000'];
        yield ['DROP TABLE users; --'];
        yield ['../../../etc/passwd'];
        yield ['<script>alert(1)</script>'];
        yield ['{{7*7}}'];
        yield ['; rm -rf /'];
        yield [sprintf('x%sy', "\0")];
    }
}

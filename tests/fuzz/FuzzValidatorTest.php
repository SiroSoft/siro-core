<?php

declare(strict_types=1);

namespace Siro\Core\Tests\Fuzz;
use PHPUnit\Framework\Attributes\DataProvider;


use Siro\Core\Tests\TestCase;
use Siro\Core\Validator;

final class FuzzValidatorTest extends TestCase
{
    #[DataProvider('provideMakeInputFuzz')]
    public function testMakeNeverThrows(array $input, array $rules): void
    {
        $result = Validator::make($input, $rules);
        $this->assertIsArray($result);
    }

    #[DataProvider('provideMakeInputFuzz')]
    public function testMakeReturnsArrayOfStringsOrArrays(array $input, array $rules): void
    {
        $result = Validator::make($input, $rules);
        $this->assertIsArray($result);
        foreach ($result as $field => $messages) {
            $this->assertIsArray($messages);
            foreach ($messages as $msg) {
                $this->assertIsString($msg);
            }
        }
    }

    /** @return iterable<string, array{array, array}> */
    public static function provideMakeInputFuzz(): iterable
    {
        $fields = ['name', 'email', 'age', 'score', 'url', 'code', 'items', 'flag', ''];
        $ruleSets = [
            'required', 'email', 'numeric', 'integer', 'string', 'array', 'boolean',
            'url', 'date', 'min:1', 'min:3', 'max:10', 'max:100',
            'in:1,2,3', 'in:active,inactive,pending',
            'nullable', 'nullable|email', 'nullable|numeric',
            'required|email', 'required|numeric|min:0|max:100',
            'required|string|min:2|max:50',
            'required|in:yes,no',
            'email|min:5', 'url|max:200',
        ];

        $fuzzValues = [
            null, true, false, 0, 1, -1, 3.14, INF, NAN, -INF,
            '', ' ', "\0", "\n", "\t", "\r",
            'a', 'abc', 'test@example.com', '../../etc/passwd',
            '<script>alert(1)</script>', 'DROP TABLE users;',
            '00000000-0000-0000-0000-000000000000',
            str_repeat('x', 1), str_repeat('x', 100), str_repeat('x', 10000),
            [], ['key' => 'value'], [1, 2, 3],
        ];

        $idx = 0;
        foreach ($fields as $field) {
            foreach ($ruleSets as $rules) {
                $ruleArr = $field !== '' ? [$field => $rules] : ['' => $rules];
                foreach ($fuzzValues as $value) {
                    $input = $field !== '' ? [$field => $value] : ['' => $value];
                    yield 'fv_' . $idx++ => [$input, $ruleArr];
                }
            }
        }
    }

    #[DataProvider('provideRegexFuzz')]
    public function testRegexRuleNeverThrows(string $pattern, mixed $value): void
    {
        $result = Validator::make(
            ['field' => $value],
            ['field' => 'regex:' . $pattern]
        );
        $this->assertIsArray($result);
    }

    /** @return iterable<string, array{string, mixed}> */
    public static function provideRegexFuzz(): iterable
    {
        $patterns = [
            '/^[a-z]+$/', '/[0-9]/', '/./', '/^$/', '//',
        ];
        $values = [
            null, true, false, 0, '', 'abc', '123',
            str_repeat('x', 1000), "\0", "\n",
        ];
        $idx = 0;
        foreach ($patterns as $pattern) {
            foreach ($values as $value) {
                yield 'rg_' . $idx++ => [$pattern, $value];
            }
        }
    }

    #[DataProvider('provideEdgeCaseRuleFormats')]
    public function testEdgeCaseRuleFormats(array $input, array $rules): void
    {
        $result = Validator::make($input, $rules);
        $this->assertIsArray($result);
    }

    /** @return iterable<string, array{array, array}> */
    public static function provideEdgeCaseRuleFormats(): iterable
    {
        yield 'empty rules' => [['x' => 1], []];
        yield 'empty input empty rules' => [[], []];
        yield 'null field null rule param' => [['x' => null], ['x' => 'min:']];
        yield 'malformed rule' => [['x' => 'val'], ['x' => ':|:|:']];
        yield 'rule with many colons' => [['x' => 'val'], ['x' => 'min:1:2:3:4:5']];
        yield 'multiple fields with same rule' => [
            ['a' => 'x', 'b' => 'y', 'c' => 'z'],
            ['a' => 'required', 'b' => 'required', 'c' => 'required'],
        ];
        yield 'missing field with required' => [['other' => 'x'], ['missing' => 'required']];
        yield 'url with missing protocol' => [['u' => 'example.com'], ['u' => 'url']];
        yield 'date with string' => [['d' => 'not-a-date'], ['d' => 'date']];
        yield 'boolean rule on int' => [['b' => 1], ['b' => 'boolean']];
    }
}

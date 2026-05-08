<?php

declare(strict_types=1);

namespace Siro\Core\Tests\Unit;

use Siro\Core\Tests\TestCase;
use Siro\Core\Str;

final class StrExtensionsTest extends TestCase
{
    public function testSlugWithEmptyString(): void
    {
        $this->assertSame('', Str::slug(''));
    }

    public function testSlugWithSpecialCharacters(): void
    {
        $this->assertSame('hello-world-123', Str::slug('Hello World 123!@#'));
    }

    public function testSlugWithLeadingTrailingSpaces(): void
    {
        $this->assertSame('hello-world', Str::slug('  Hello World  '));
    }

    public function testLimitWithEmptyString(): void
    {
        $this->assertSame('', Str::limit('', 5));
    }

    public function testLimitWithExactLength(): void
    {
        $this->assertSame('Hello', Str::limit('Hello', 5));
    }

    public function testLimitTruncatesLonger(): void
    {
        $this->assertStringEndsWith('...', Str::limit('Hello World', 10));
    }

    public function testLimitWithUtf8(): void
    {
        $result = Str::limit('你好世界', 2);
        $this->assertStringEndsWith('...', $result);
    }

    public function testContainsCaseSensitive(): void
    {
        $this->assertTrue(Str::contains('Hello World', 'World'));
        $this->assertFalse(Str::contains('Hello World', 'world'));
        $this->assertFalse(Str::contains('Hello World', 'Foo'));
    }

    public function testContainsWithEmptyNeedle(): void
    {
        $this->assertFalse(Str::contains('Hello World', ''));
    }

    public function testStartsWithCaseSensitive(): void
    {
        $this->assertTrue(Str::startsWith('Hello', 'H'));
        $this->assertTrue(Str::startsWith('Hello', 'He'));
        $this->assertFalse(Str::startsWith('Hello', 'h'));
        $this->assertFalse(Str::startsWith('Hello', ''));
    }

    public function testEndsWithCaseSensitive(): void
    {
        $this->assertTrue(Str::endsWith('Hello', 'o'));
        $this->assertTrue(Str::endsWith('Hello', 'lo'));
        $this->assertFalse(Str::endsWith('Hello', 'O'));
        $this->assertFalse(Str::endsWith('Hello', ''));
    }

    public function testRandomWithLength1(): void
    {
        $result = Str::random(1);
        $this->assertSame(1, strlen($result));
    }

    public function testRandomWithMaxLength(): void
    {
        $result = Str::random(64);
        $this->assertSame(64, strlen($result));
    }

    public function testRandomUniqueness(): void
    {
        $strings = [];
        for ($i = 0; $i < 100; $i++) {
            $strings[] = Str::random(32);
        }
        $unique = array_unique($strings);
        $this->assertSame(100, count($unique), 'Random strings should be unique');
    }

    public function testLengthWithEmptyString(): void
    {
        $this->assertSame(0, Str::length(''));
    }

    public function testLengthWithSpecialCharacters(): void
    {
        $this->assertSame(15, Str::length('Hello World!@#$'));
    }

    public function testSubstrWithNegativeOffset(): void
    {
        $this->assertSame('World', Str::substr('Hello World', -5));
    }

    public function testSubstrWithOutOfRange(): void
    {
        $this->assertSame('', Str::substr('Hello', 100));
    }

    public function testCamelWithNumbers(): void
    {
        $this->assertSame('hello123World', Str::camel('hello123_world'));
    }

    public function testStudlyWithNumbers(): void
    {
        $this->assertSame('Hello123World', Str::studly('hello123_world'));
    }

    public function testSnakeBasic(): void
    {
        $this->assertSame('hello_world', Str::snake('helloWorld'));
        $this->assertSame('hello_world', Str::snake('hello_world'));
    }

    public function testWordsWithZero(): void
    {
        $this->assertStringEndsWith('...', Str::words('Hello World Test', 0));
    }

    public function testWordsWithExactCount(): void
    {
        $this->assertSame('Hello World', Str::words('Hello World', 2));
    }

    public function testWordsTruncatesBeyondCount(): void
    {
        $this->assertSame('Hello...', Str::words('Hello World Test', 1));
    }

    public function testWordsPreservesUtf8(): void
    {
        $result = Str::words('你好 世界 大家', 1);
        $this->assertSame('你好...', $result);
    }

    public function testAfterWithFoundSearch(): void
    {
        $this->assertSame('World', Str::after('Hello World', 'Hello '));
    }

    public function testAfterWithNotFoundSearch(): void
    {
        $this->assertSame('Hello World', Str::after('Hello World', 'Foo'));
    }

    public function testBeforeWithFoundSearch(): void
    {
        $this->assertSame('Hello', Str::before('Hello World', ' World'));
    }

    public function testBeforeWithNotFoundSearch(): void
    {
        $this->assertSame('Hello World', Str::before('Hello World', 'Foo'));
    }

    public function testIsJsonWithValidJson(): void
    {
        $this->assertTrue(Str::isJson('{"name":"John"}'));
    }

    public function testIsJsonWithInvalidJson(): void
    {
        $this->assertFalse(Str::isJson('not json'));
    }

    public function testPluralRules(): void
    {
        $this->assertSame('boxes', Str::plural('box'));
        $this->assertSame('cats', Str::plural('cat'));
    }

    public function testSingularRules(): void
    {
        $this->assertSame('box', Str::singular('boxes'));
        $this->assertSame('cat', Str::singular('cats'));
    }

    public function testUcfirst(): void
    {
        $this->assertSame('Hello', Str::ucfirst('hello'));
    }

    public function testPadBoth(): void
    {
        $result = Str::padBoth('Hello', 10, '_');
        $this->assertSame(10, strlen($result));
        $this->assertSame('__Hello___', $result);
    }
}

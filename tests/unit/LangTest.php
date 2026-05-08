<?php

declare(strict_types=1);

namespace Siro\Core\Tests\Unit;

use Siro\Core\Tests\TestCase;
use Siro\Core\Lang;

final class LangTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpLangFiles();
    }

    private function setUpLangFiles(): void
    {
        $basePath = dirname(__DIR__, 2);
        $langDir = $basePath . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'lang';

        if (!is_dir($langDir . DIRECTORY_SEPARATOR . 'en')) {
            mkdir($langDir . DIRECTORY_SEPARATOR . 'en', 0777, true);
        }
        if (!is_dir($langDir . DIRECTORY_SEPARATOR . 'vi')) {
            mkdir($langDir . DIRECTORY_SEPARATOR . 'vi', 0777, true);
        }

        $enMessages = <<<'PHP'
<?php
return [
    'welcome' => 'Welcome',
    'goodbye' => 'Goodbye',
    'nested' => [
        'deep' => 'Deep nested value',
    ],
    'apples' => '{count} apple|{count} apples',
    'greeting' => 'Hello :name',
    'missing' => 'This key should not exist',
];
PHP;

        $viMessages = <<<'PHP'
<?php
return [
    'welcome' => 'Chào mừng',
    'goodbye' => 'Tạm biệt',
    'nested' => [
        'deep' => 'Giá trị sâu',
    ],
    'apples' => '{count} quả táo|{count} quả táo',
    'greeting' => 'Xin chào :name',
];
PHP;

        file_put_contents($langDir . DIRECTORY_SEPARATOR . 'en' . DIRECTORY_SEPARATOR . 'messages.php', $enMessages);
        file_put_contents($langDir . DIRECTORY_SEPARATOR . 'vi' . DIRECTORY_SEPARATOR . 'messages.php', $viMessages);

        Lang::boot($basePath);
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        $basePath = dirname(__DIR__, 2);
        $langDir = $basePath . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'lang';
        $this->recursiveDelete($langDir);
    }

    private function recursiveDelete(string $dir): void
    {
        if (!is_dir($dir)) return;
        $files = array_diff(scandir($dir) ?: [], ['.', '..']);
        foreach ($files as $file) {
            $path = $dir . DIRECTORY_SEPARATOR . $file;
            is_dir($path) ? $this->recursiveDelete($path) : unlink($path);
        }
        rmdir($dir);
    }

    public function testGetReturnsTranslation(): void
    {
        $this->assertSame('Welcome', Lang::get('messages.welcome'));
    }

    public function testGetReturnsKeyWhenNotFound(): void
    {
        $result = Lang::get('messages.nonexistent');
        $this->assertSame('messages.nonexistent', $result);
    }

    public function testGetWithParameterReplacement(): void
    {
        $result = Lang::get('messages.greeting', ['name' => 'John']);
        $this->assertSame('Hello John', $result);
    }

    public function testGetWithMissingParameter(): void
    {
        $result = Lang::get('messages.greeting', []);
        $this->assertSame('Hello :name', $result);
    }

    public function testGetNestedKey(): void
    {
        $this->assertSame('Deep nested value', Lang::get('messages.nested.deep'));
    }

    public function testGetNestedKeyMissing(): void
    {
        $this->assertSame('messages.nested.missing', Lang::get('messages.nested.missing'));
    }

    public function testHasReturnsTrue(): void
    {
        $this->assertTrue(Lang::has('messages.welcome'));
    }

    public function testHasReturnsFalse(): void
    {
        $this->assertFalse(Lang::has('messages.nonexistent'));
    }

    public function testHasNestedKey(): void
    {
        $this->assertTrue(Lang::has('messages.nested.deep'));
    }

    public function testLocaleDefaults(): void
    {
        $this->assertSame('en', Lang::locale());
    }

    public function testSetLocale(): void
    {
        Lang::setLocale('vi');
        $this->assertSame('vi', Lang::locale());
        $this->assertSame('Chào mừng', Lang::get('messages.welcome'));
        Lang::setLocale('en');
    }

    public function testSetFallbackLocale(): void
    {
        Lang::setFallbackLocale('vi');
        $result = Lang::get('messages.welcome');
        $this->assertSame('Welcome', $result);
        Lang::setFallbackLocale('en');
    }

    public function testFallbackLocaleUsedWhenKeyMissing(): void
    {
        Lang::setLocale('vi');
        $result = Lang::get('messages.goodbye');
        $this->assertSame('Tạm biệt', $result);
        Lang::setLocale('en');
    }

    public function testPluralSingular(): void
    {
        $result = Lang::plural('messages.apples', 1);
        $this->assertSame('1 apple', $result);
    }

    public function testPluralPlural(): void
    {
        $result = Lang::plural('messages.apples', 5);
        $this->assertSame('5 apples', $result);
    }

    public function testPluralWithCountPlaceholder(): void
    {
        $result = Lang::plural('messages.apples', 5);
        $this->assertSame('5 apples', $result);
    }

    public function testPluralNoPlaceholderNoPipe(): void
    {
        $result = Lang::plural('messages.welcome', 5);
        $this->assertSame('Welcome', $result);
    }

    public function testBasePath(): void
    {
        $basePath = Lang::basePath();
        $this->assertNotEmpty($basePath);
        $this->assertStringContainsString('lang', $basePath);
    }

    public function testInvalidKeyFormat(): void
    {
        $result = Lang::get('invalid-key-no-dot');
        $this->assertSame('invalid-key-no-dot', $result);
    }

    public function testEmptyKey(): void
    {
        $result = Lang::get('');
        $this->assertSame('', $result);
    }
}
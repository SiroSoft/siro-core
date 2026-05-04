<?php

declare(strict_types=1);

namespace Siro\Core\Commands;

final class MakeTestCommand
{
    use CommandSupport;

    public function __construct(private readonly string $basePath)
    {
    }

    public function run(array $args): int
    {
        $name = trim((string) ($args[0] ?? ''));
        if ($name === '') {
            $this->write('Usage: php siro make:test <name> [--unit]');
            $this->write('  Creates an integration/feature test in tests/Feature/');
            $this->write('  Use --unit to create a unit test in tests/Unit/');
            return 1;
        }

        $name = preg_replace('/[^a-zA-Z0-9_]+/', '', $name) ?? $name;
        $name = trim($name, '_');

        $isUnit = in_array('--unit', $args, true);
        $dirName = $isUnit ? 'Unit' : 'Feature';
        $suffix = 'Test';

        $path = $this->basePath . DIRECTORY_SEPARATOR . 'tests' . DIRECTORY_SEPARATOR . $dirName . DIRECTORY_SEPARATOR . $name . $suffix . '.php';

        if (is_file($path) && !$this->confirmOverwrite($this->basePath, $path)) {
            return 0;
        }

        $dir = dirname($path);
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        if ($isUnit) {
            file_put_contents($path, $this->unitTemplate($name));
        } else {
            $endpoint = '/api/' . strtolower(preg_replace('/Test$/', '', $name));
            file_put_contents($path, $this->featureTemplate($name, $endpoint));
        }

        $this->write('Generated: tests/' . $dirName . '/' . $name . $suffix . '.php');
        $short = $isUnit ? 'tests/Unit/' . $name . $suffix . '.php' : 'tests/Feature/' . $name . $suffix . '.php';
        $suite = $isUnit ? 'Unit' : 'Feature';
        $this->write('  Run: vendor/bin/phpunit --testsuite=' . $suite . ' --filter=' . $name . $suffix);
        return 0;
    }

    private function featureTemplate(string $name, string $endpoint): string
    {
        return <<<PHP
<?php

declare(strict_types=1);

namespace App\Tests\Feature;

use App\Tests\TestCase;
use Siro\Core\Request;

final class {$name}Test extends TestCase
{
    public function testIndexReturns200(): void
    {
        \$app = \$this->createApp();
        \$response = \$this->dispatch(\$app, 'GET', '{$endpoint}');
        \$this->assertEquals(200, \$response->statusCode());
    }

    public function testShowReturns404ForInvalidId(): void
    {
        \$app = \$this->createApp();
        \$response = \$this->dispatch(\$app, 'GET', '{$endpoint}/999');
        \$this->assertEquals(404, \$response->statusCode());
    }

    public function testStoreReturns201WithValidData(): void
    {
        \$app = \$this->createApp();
        \$response = \$this->dispatch(\$app, 'POST', '{$endpoint}', ['name' => 'Test']);
        \$this->assertEquals(201, \$response->statusCode());
    }

    public function testStoreReturns422WithoutRequiredFields(): void
    {
        \$app = \$this->createApp();
        \$response = \$this->dispatch(\$app, 'POST', '{$endpoint}', []);
        \$this->assertEquals(422, \$response->statusCode());
    }
}

PHP;
    }

    private function unitTemplate(string $name): string
    {
        return <<<PHP
<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Tests\TestCase;
use Siro\Core\Request;

final class {$name}Test extends TestCase
{
    public function testBasicAssertion(): void
    {
        \$request = new Request('GET', '/test');
        \$this->assertSame('GET', \$request->method());
        \$this->assertSame('/test', \$request->path());
    }
}

PHP;
    }
}

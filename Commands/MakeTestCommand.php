<?php

declare(strict_types=1);

namespace Siro\Core\Commands;

final class MakeTestCommand implements \Siro\Core\Commands\CommandInterface {
    use CommandSupport;

    public function __construct(private readonly string $basePath)
    {
    }

    /** @param array<int, string> $args */
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
        // Strip trailing 'Test' to avoid double suffix
        $className = preg_replace('/Test$/', '', $name) ?? $name;

        $isUnit = in_array('--unit', $args, true);
        $dirName = $isUnit ? 'Unit' : 'Feature';
        $suffix = 'Test';

        $path = $this->basePath . DIRECTORY_SEPARATOR . 'tests' . DIRECTORY_SEPARATOR . $dirName . DIRECTORY_SEPARATOR . $className . $suffix . '.php';

        if (is_file($path) && !$this->confirmOverwrite($this->basePath, $path)) {
            return 0;
        }

        $dir = dirname($path);
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        if ($isUnit) {
            file_put_contents($path, $this->unitTemplate($className));
        } else {
            $endpoint = '/api/' . strtolower($className);
            file_put_contents($path, $this->featureTemplate($className, $endpoint));
        }

        $this->write('Generated: tests/' . $dirName . '/' . $className . $suffix . '.php');
        $short = $isUnit ? 'tests/Unit/' . $className . $suffix . '.php' : 'tests/Feature/' . $className . $suffix . '.php';
        $suite = $isUnit ? 'Unit' : 'Feature';
        $this->write('  Run: vendor/bin/phpunit --testsuite=' . $suite . ' --filter=' . $className . $suffix);
        return 0;
    }

    private function featureTemplate(string $name, string $endpoint): string
    {
        return <<<PHP
<?php

declare(strict_types=1);

namespace App\Tests\Feature;

use App\Tests\TestCase;

final class {$name}Test extends TestCase
{
    public function testIndexReturns200(): void
    {
        \$this->get('{$endpoint}')->assertOk();
    }

    public function testShowReturns404ForInvalidId(): void
    {
        \$this->get('{$endpoint}/999')->assertNotFound();
    }

    public function testStoreReturns201WithValidData(): void
    {
        \$this->post('{$endpoint}', ['name' => 'Test'])->assertCreated();
    }

    public function testStoreReturns422WithoutRequiredFields(): void
    {
        \$this->post('{$endpoint}', [])->assertValidationError();
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

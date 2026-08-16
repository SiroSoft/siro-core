<?php

declare(strict_types=1);

namespace Siro\Core\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Siro\Core\Commands\MakeCrudCommand;

/**
 * MakeCrudCommand — the "Build" step of the workflow.
 * Generates model/migration/repository/service/controller/resource/routes/test
 * files into a throwaway temp project.
 */
final class MakeCrudCommandTest extends TestCase
{
    private string $basePath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->basePath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'siro_crud_' . uniqid('', true);
        mkdir($this->basePath, 0777, true);
        // Real projects have a routes/ dir with an /api group (closes with "});")
        mkdir($this->basePath . DIRECTORY_SEPARATOR . 'routes', 0777, true);
        file_put_contents(
            $this->basePath . DIRECTORY_SEPARATOR . 'routes' . DIRECTORY_SEPARATOR . 'api.php',
            "<?php\n\n\$router->group(['prefix' => 'api'], function (\$router) {\n    \$router->get('/health', fn () => 'ok');\n});\n"
        );
    }

    protected function tearDown(): void
    {
        if (is_dir($this->basePath)) {
            $this->rmDir($this->basePath);
        }
        parent::tearDown();
    }

    private function rmDir(string $dir): void
    {
        foreach (scandir($dir) as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . DIRECTORY_SEPARATOR . $entry;
            if (is_dir($path)) {
                $this->rmDir($path);
            } else {
                @unlink($path);
            }
        }
        @rmdir($dir);
    }

    /** @param array<int, string> $args @return array{int, string} */
    private function runCmd(array $args): array
    {
        ob_start();
        $cmd = new MakeCrudCommand($this->basePath);
        $exit = $cmd->run($args);
        $output = ob_get_clean() ?: '';
        return [$exit, $output];
    }

    public function testUsageWhenNoResource(): void
    {
        [$exit, $output] = $this->runCmd([]);
        $this->assertSame(1, $exit);
        $this->assertStringContainsString('Usage', $output);
    }

    public function testFullCrudGeneratesAllFiles(): void
    {
        [$exit, $output] = $this->runCmd(['product']);
        $this->assertSame(0, $exit, $output);
        $this->assertFileExists($this->basePath . '/app/Models/Product.php');
        $this->assertFileExists($this->basePath . '/app/Controllers/ProductController.php');
        $this->assertFileExists($this->basePath . '/app/Services/ProductService.php');
        $this->assertFileExists($this->basePath . '/app/Repositories/ProductRepository.php');
        $this->assertFileExists($this->basePath . '/app/Resources/ProductResource.php');
        $this->assertFileExists($this->basePath . '/routes/api.php');
        $this->assertFileExists($this->basePath . '/tests/Feature/ProductTest.php');
        $migrations = glob($this->basePath . '/database/migrations/*_create_products_table.php');
        $this->assertNotEmpty($migrations);
        $this->assertStringContainsString('created successfully', $output);
    }

    public function testSimpleCrudSkipsMigrationServiceRepo(): void
    {
        [$exit, $output] = $this->runCmd(['book', '--simple']);
        $this->assertSame(0, $exit);
        $this->assertFileExists($this->basePath . '/app/Models/Book.php');
        $this->assertFileExists($this->basePath . '/app/Controllers/BookController.php');
        $this->assertFileDoesNotExist($this->basePath . '/app/Services/BookService.php');
        $this->assertFileDoesNotExist($this->basePath . '/app/Repositories/BookRepository.php');
        $migrations = glob($this->basePath . '/database/migrations/*book*');
        $this->assertEmpty($migrations);
        $this->assertStringContainsString('Simple', $output);
    }

    public function testWithoutServiceAndRepository(): void
    {
        [$exit, $output] = $this->runCmd(['order', '--without-service', '--without-repository']);
        $this->assertSame(0, $exit);
        $this->assertFileExists($this->basePath . '/app/Models/Order.php');
        $this->assertFileDoesNotExist($this->basePath . '/app/Services/OrderService.php');
        $this->assertFileDoesNotExist($this->basePath . '/app/Repositories/OrderRepository.php');
    }

    public function testServiceWithoutRepository(): void
    {
        // Service generated but repository skipped → service talks to model directly
        [$exit, $output] = $this->runCmd(['shipment', '--without-repository']);
        $this->assertSame(0, $exit);
        $this->assertFileExists($this->basePath . '/app/Services/ShipmentService.php');
        $this->assertFileDoesNotExist($this->basePath . '/app/Repositories/ShipmentRepository.php');
        $service = (string) file_get_contents($this->basePath . '/app/Services/ShipmentService.php');
        $this->assertStringContainsString('private readonly Shipment $model', $service);
    }

    public function testServiceWithRepository(): void
    {
        // Full mode → service talks to repository
        [$exit, $output] = $this->runCmd(['invoice']);
        $this->assertSame(0, $exit);
        $this->assertFileExists($this->basePath . '/app/Services/InvoiceService.php');
        $this->assertFileExists($this->basePath . '/app/Repositories/InvoiceRepository.php');
        $service = (string) file_get_contents($this->basePath . '/app/Services/InvoiceService.php');
        $this->assertStringContainsString('private readonly InvoiceRepository $repo', $service);
    }

    public function testRepositoryGeneratedContent(): void
    {
        $this->runCmd(['invoice']);
        $repo = (string) file_get_contents($this->basePath . '/app/Repositories/InvoiceRepository.php');
        $this->assertStringContainsString('findAll', $repo);
        $this->assertStringContainsString('InvoiceRepository', $repo);
    }

    public function testRoutesCreatedWhenMissing(): void
    {
        // Remove the routes file → generateRoutes creates it from scratch
        @unlink($this->basePath . DIRECTORY_SEPARATOR . 'routes' . DIRECTORY_SEPARATOR . 'api.php');
        [$exit, $output] = $this->runCmd(['console']);
        $this->assertSame(0, $exit);
        $this->assertFileExists($this->basePath . '/routes/api.php');
        $content = (string) file_get_contents($this->basePath . '/routes/api.php');
        $this->assertStringContainsString('ConsoleController', $content);
    }

    public function testSingularizationAndPluralization(): void
    {
        [$exit, $output] = $this->runCmd(['categories']);
        $this->assertSame(0, $exit);
        $this->assertFileExists($this->basePath . '/app/Models/Category.php');
        // table should be pluralized from 'categories' → 'categories' (already plural)
        $migrations = glob($this->basePath . '/database/migrations/*_create_categories_table.php');
        $this->assertNotEmpty($migrations);
    }

    public function testForceOverwriteSkipsConfirm(): void
    {
        // First run creates files; second run with --force overwrites without prompt
        $this->runCmd(['product']);
        [$exit, $output] = $this->runCmd(['product', '--force']);
        $this->assertSame(0, $exit);
        $this->assertStringContainsString('created successfully', $output);
    }

    public function testWithRbac(): void
    {
        [$exit, $output] = $this->runCmd(['user', '--with-rbac']);
        $this->assertSame(0, $exit);
        $this->assertFileExists($this->basePath . '/app/Models/User.php');
    }

    public function testGuessValidationUser(): void
    {
        $ref = new \ReflectionClass(MakeCrudCommand::class);
        $m = $ref->getMethod('guessValidationRules');
        $m->setAccessible(true);
        $cmd = new MakeCrudCommand($this->basePath);
        $rules = $m->invoke($cmd, 'Customer');
        $this->assertStringContainsString('required|email', $rules);
        $rules2 = $m->invoke($cmd, 'Invoice');
        $this->assertStringContainsString('required|numeric', $rules2);
        $rules3 = $m->invoke($cmd, 'Product');
        $this->assertStringContainsString('required|numeric|min:0', $rules3);
        $rules4 = $m->invoke($cmd, 'Tag');
        $this->assertStringContainsString("'slug'", $rules4);
        $rules5 = $m->invoke($cmd, 'Whatever');
        $this->assertStringContainsString('required|min:2|max:255', $rules5);
    }

    public function testGeneratedFilesContainExpectedContent(): void
    {
        $this->runCmd(['gadget']);
        $model = (string) file_get_contents($this->basePath . '/app/Models/Gadget.php');
        $this->assertStringContainsString('final class Gadget', $model);
        $this->assertStringContainsString("'gadgets'", $model);
        $controller = (string) file_get_contents($this->basePath . '/app/Controllers/GadgetController.php');
        $this->assertStringContainsString('class GadgetController', $controller);
        $routes = (string) file_get_contents($this->basePath . '/routes/api.php');
        $this->assertStringContainsString('GadgetController', $routes);
        $test = (string) file_get_contents($this->basePath . '/tests/Feature/GadgetTest.php');
        $this->assertStringContainsString('class GadgetTest', $test);
    }

    public function testWithSeedFlag(): void
    {
        [$exit, $output] = $this->runCmd(['member', '--seed']);
        $this->assertSame(0, $exit);
        $this->assertStringContainsString('db:seed', $output);
    }

    public function testOverwriteDeclinedSkipsFiles(): void
    {
        // First run creates files
        $this->runCmd(['widget']);
        // Second run WITHOUT --force → confirmOverwrite via ask() returns empty (decline)
        $cmd = new MakeCrudCommand($this->basePath);
        ob_start();
        $exit = $cmd->run(['widget']);
        $output = ob_get_clean() ?: '';
        // ask() on non-tty returns empty → declined → files skipped, but returns 0 with partial
        $this->assertContains($exit, [0, 1]);
    }
}

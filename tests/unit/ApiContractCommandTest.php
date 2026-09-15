<?php

declare(strict_types=1);

namespace Siro\Core\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Siro\Core\Commands\ApiContractCommand;

final class ApiContractCommandTest extends TestCase
{
    private string $basePath;

    protected function setUp(): void
    {
        $this->basePath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'siro_contract_' . uniqid('', true);
        mkdir($this->basePath . '/routes', 0777, true);
        mkdir($this->basePath . '/config', 0777, true);
        mkdir($this->basePath . '/docs/openapi', 0777, true);
        file_put_contents($this->basePath . '/.env', "APP_ENV=testing\nAPP_DEBUG=false\n");
        file_put_contents($this->basePath . '/config/database.php', "<?php return ['driver' => 'sqlite', 'database' => ':memory:'];");
        file_put_contents($this->basePath . '/routes/api.php', "<?php \$router->get('/api/widgets', 'WidgetController@index');");
    }

    protected function tearDown(): void
    {
        $this->remove($this->basePath);
    }

    private function remove(string $dir): void
    {
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') continue;
            $path = $dir . DIRECTORY_SEPARATOR . $entry;
            is_dir($path) ? $this->remove($path) : unlink($path);
        }
        rmdir($dir);
    }

    public function testReportsValidAndMissingOperations(): void
    {
        file_put_contents($this->basePath . '/docs/openapi/openapi.json', json_encode([
            'paths' => [
                '/api/widgets' => ['get' => ['responses' => ['200' => ['description' => 'ok']]]],
                '/api/missing' => ['get' => ['responses' => ['200' => ['description' => 'ok']]]],
            ],
        ]));
        [$code, $output] = $this->runCommand([]);
        $this->assertSame(1, $code);
        $this->assertStringContainsString('1 passed', $output);
        $this->assertStringContainsString('1 failed', $output);
    }

    public function testMissingSpecFailsClearly(): void
    {
        [$code, $output] = $this->runCommand(['--spec=' . $this->basePath . '/missing.json']);
        $this->assertSame(1, $code);
        $this->assertStringContainsString('not found', strtolower($output));
    }

    /** @return array{int, string} */
    private function runCommand(array $args): array
    {
        ob_start();
        $code = (new ApiContractCommand($this->basePath))->run($args);
        return [$code, ob_get_clean() ?: ''];
    }
}

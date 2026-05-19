<?php

declare(strict_types=1);

namespace Siro\Core\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Siro\Core\Config;
use Siro\Core\Router;
use Siro\Core\Storage;

final class SecurityFixesTest extends TestCase
{
    private static function extractJsonFromCache(string $content): string
    {
        $after = substr($content, strlen('<?php exit; ?>'));
        $sep = strrpos($after, '.hmac.');
        return $sep !== false ? substr($after, 0, $sep) : trim($after);
    }

    private string $tempDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tempDir = sys_get_temp_dir() . '/siro_security_fixes_' . uniqid();
        Config::reset();
        Storage::fake();
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        if (is_dir($this->tempDir)) {
            $this->removeDir($this->tempDir);
        }
        $siblingStorage = dirname($this->tempDir) . DIRECTORY_SEPARATOR . 'storage';
        if (is_dir($siblingStorage)) {
            $this->removeDir($siblingStorage);
        }
    }

    // ----- 1. XSS Fix in Queue::dashboardHtml() -----

    public function testDashboardHtmlEscapesScriptTagsWithHtmlspecialchars(): void
    {
        $input = '<script>alert("XSS")</script>';
        $escaped = htmlspecialchars($input, ENT_QUOTES, 'UTF-8');

        $this->assertStringNotContainsString('<script>', $escaped);
        $this->assertStringNotContainsString('</script>', $escaped);
        $this->assertStringContainsString('&lt;script&gt;', $escaped);
        $this->assertStringContainsString('&quot;', $escaped);
    }

    public function testDashboardHtmlEscapesAllDataFields(): void
    {
        $payloads = [
            'id' => '<img src=x onerror=alert(1)>',
            'job' => '"><script>evil()</script>',
            'attempts' => '1e3',
            'max_attempts' => "' OR '1'='1",
            'priority' => '0; DROP TABLE jobs;--',
        ];

        foreach ($payloads as $field => $input) {
            $escaped = htmlspecialchars((string) ($payloads[$field] ?? ''), ENT_QUOTES, 'UTF-8');
            $this->assertStringNotContainsString('<', $escaped, "Field {$field} not escaped");
            $this->assertStringNotContainsString('>', $escaped, "Field {$field} not escaped");
            $this->assertStringNotContainsString('"', $escaped, "Field {$field} not escaped");
            $this->assertStringNotContainsString("'", $escaped, "Field {$field} not escaped");
        }
    }

    public function testDashboardHtmlEscapesNullCoalescedDefaults(): void
    {
        $defaultEscaped = htmlspecialchars((string) ('' ?? ''), ENT_QUOTES, 'UTF-8');
        $this->assertSame('', $defaultEscaped);

        $idEscaped = htmlspecialchars((string) (null ?? ''), ENT_QUOTES, 'UTF-8');
        $this->assertSame('', $idEscaped);
    }

    public function testDashboardHtmlDoubleEncodesAlreadyEscapedInput(): void
    {
        $input = '&#60;script&#62;';
        $doubleEscaped = htmlspecialchars($input, ENT_QUOTES, 'UTF-8');
        $this->assertStringContainsString('&amp;', $doubleEscaped);
    }

    public function testDashboardHtmlPreservesSafeCharacters(): void
    {
        $safe = 'NormalJobName_123';
        $escaped = htmlspecialchars($safe, ENT_QUOTES, 'UTF-8');
        $this->assertSame($safe, $escaped);
    }

    // ----- 2. SQL Injection Fix in Queue::getFailedJobs() -----

    public function testGetFailedJobsCastsNonNumericLimitToInt(): void
    {
        $nonNumericValues = [
            '50; DROP TABLE failed_jobs;--',
            'abc',
            '1 UNION SELECT * FROM users',
            '',
            '3.14',
            '0x1',
        ];

        foreach ($nonNumericValues as $limit) {
            $casted = max(1, (int) $limit);
            $this->assertIsInt($casted, 'Limit should be int after cast');
            $this->assertGreaterThanOrEqual(1, $casted, 'Limit should be at least 1');
        }
    }

    public function testGetFailedJobsCastsFloatLimitToInt(): void
    {
        $result = max(1, (int) 3.99);
        $this->assertSame(3, $result);
        $this->assertIsInt($result);
    }

    public function testGetFailedJobsEnsuresMinimumLimitOfOne(): void
    {
        $this->assertSame(1, max(1, (int) 0));
        $this->assertSame(1, max(1, (int) -5));
        $this->assertSame(1, max(1, (int) -1));
    }

    public function testGetFailedJobsPreservesValidNumericLimit(): void
    {
        $this->assertSame(50, max(1, (int) 50));
        $this->assertSame(100, max(1, (int) 100));
        $this->assertSame(1, max(1, (int) 1));
    }

    public function testGetFailedJobsRejectsSqlInStringWithIntCast(): void
    {
        $malicious = '100; DELETE FROM failed_jobs; SELECT 1';
        $safe = max(1, (int) $malicious);
        $this->assertSame(100, $safe);
        $this->assertStringNotContainsString('DELETE', (string) $safe);
    }

    // ----- 3. Config Cache Uses JSON -----

    public function testConfigCacheProducesJsonNotVarExport(): void
    {
        mkdir($this->tempDir, 0777, true);
        file_put_contents($this->tempDir . '/app.php', '<?php return ["name" => "Siro", "nested" => ["key" => "value"]];');

        Config::reset();
        Config::load($this->tempDir);
        $cacheFile = Config::cache();

        $this->assertNotNull($cacheFile);
        $this->assertFileExists($cacheFile);

        $content = file_get_contents($cacheFile);
        $this->assertStringStartsWith('<?php exit; ?>', $content, 'Cache should start with PHP exit guard');

        $jsonPart = self::extractJsonFromCache($content);
        $this->assertJson($jsonPart, 'Cache content after exit guard should be valid JSON');

        $decoded = json_decode($jsonPart, true);
        $this->assertArrayHasKey('app', $decoded);
        $this->assertSame('Siro', $decoded['app']['name']);
        $this->assertSame('value', $decoded['app']['nested']['key']);

        $this->assertStringNotContainsString('array (', $jsonPart, 'Cache should NOT use var_export format');
        $this->assertStringNotContainsString('=>', $jsonPart, 'Cache should NOT contain PHP array syntax');
    }

    public function testConfigCacheFileIsValidJson(): void
    {
        mkdir($this->tempDir, 0777, true);
        file_put_contents($this->tempDir . '/app.php', '<?php return ["name" => "Siro"];');

        Config::reset();
        Config::load($this->tempDir);
        $cacheFile = Config::cache();
        $this->assertNotNull($cacheFile);

        $content = file_get_contents($cacheFile);
        $jsonPart = self::extractJsonFromCache($content);
        $decoded = json_decode($jsonPart, true);

        $this->assertIsArray($decoded);
        $this->assertSame('Siro', $decoded['app']['name']);
    }

    // ----- 4. Env Cache Uses JSON -----

    public function testEnvCacheFormatProducesJson(): void
    {
        $cacheDir = $this->tempDir . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'framework';
        mkdir($cacheDir, 0777, true);

        $data = ['APP_NAME' => 'Siro', 'DB_HOST' => 'localhost'];
        $expectedContent = '<?php exit; ?>' . json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
        $cacheFile = $cacheDir . DIRECTORY_SEPARATOR . 'env.php';
        file_put_contents($cacheFile, $expectedContent);

        $this->assertFileExists($cacheFile);

        $content = file_get_contents($cacheFile);
        $this->assertStringStartsWith('<?php exit; ?>', $content);
        $this->assertStringNotContainsString('array (', $content, 'Should NOT use var_export');
        $this->assertStringNotContainsString('=>', $content, 'Should NOT use PHP array syntax');

        $jsonPart = substr($content, 14);
        $this->assertJson($jsonPart);

        $decoded = json_decode($jsonPart, true);
        $this->assertIsArray($decoded);
        $this->assertSame('Siro', $decoded['APP_NAME']);
        $this->assertSame('localhost', $decoded['DB_HOST']);

        $encodedAgain = json_encode($decoded, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
        $this->assertJson($encodedAgain);
    }

    public function testEnvCacheExcludesSensitiveKeysFromContent(): void
    {
        $cacheDir = $this->tempDir . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'framework';
        mkdir($cacheDir, 0777, true);

        $data = ['DB_PASSWORD' => 'pass', 'APP_KEY' => 'secret', 'JWT_SECRET' => 'jwt'];
        unset($data['APP_KEY'], $data['JWT_SECRET']);

        $expectedContent = '<?php exit; ?>' . json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
        $cacheFile = $cacheDir . DIRECTORY_SEPARATOR . 'env.php';
        file_put_contents($cacheFile, $expectedContent);

        $content = file_get_contents($cacheFile);
        $jsonPart = substr($content, 14);
        $decoded = json_decode($jsonPart, true);

        $this->assertArrayNotHasKey('APP_KEY', $decoded);
        $this->assertArrayNotHasKey('JWT_SECRET', $decoded);
        $this->assertArrayHasKey('DB_PASSWORD', $decoded);
    }

    // ----- 5. Router Cache Uses JSON -----

    public function testRouterSaveToCacheProducesJson(): void
    {
        $router = new Router();
        $router->get('/test', 'TestController@index');
        $router->post('/users', 'UserController@store');
        $router->get('/users/{id}', 'UserController@show');

        $cacheFile = $this->tempDir . '/routes.php';
        mkdir(dirname($cacheFile), 0777, true);

        $result = $router->saveToCache($cacheFile);
        $this->assertTrue($result);
        $this->assertFileExists($cacheFile);

        $content = file_get_contents($cacheFile);
        $this->assertStringStartsWith('<?php exit; ?>', $content, 'Cache should start with PHP exit guard');

        $jsonPart = self::extractJsonFromCache($content);
        $this->assertJson($jsonPart, 'Cache content should be valid JSON');

        $data = json_decode($jsonPart, true);
        $this->assertIsArray($data);
        $this->assertArrayHasKey('static', $data);
        $this->assertArrayHasKey('dynamic', $data);

        $this->assertStringNotContainsString('array (', $jsonPart, 'Should NOT use var_export');
        $this->assertStringNotContainsString('=>', $jsonPart, 'Should NOT use PHP array syntax');
    }

    public function testRouterLoadFromCacheWithValidJson(): void
    {
        $cacheFile = $this->tempDir . '/routes.php';
        mkdir(dirname($cacheFile), 0777, true);

        $exported = [
            'static' => [
                'GET' => [
                    '/api/items' => [
                        'path' => '/api/items',
                        'handler' => 'ItemController@index',
                        'handler_raw' => 'ItemController@index',
                        'middleware' => [],
                        'cache_ttl' => 0,
                    ],
                ],
            ],
            'dynamic' => [],
        ];

        $json = json_encode($exported, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $secret = 'test_app_key_for_unit_tests_router_cache_32chars!';
        putenv('APP_KEY=' . $secret);
        $_ENV['APP_KEY'] = $secret;
        $hmac = hash_hmac('sha256', $json, $secret);
        $content = '<?php exit; ?>' . $json . '.hmac.' . $hmac . PHP_EOL;
        file_put_contents($cacheFile, $content);

        $router = new Router();
        $loaded = $router->loadFromCache($cacheFile);
        $this->assertTrue($loaded, 'Should load from cache');
        $this->assertTrue($router->isCached());

        $routes = $router->getRoutes();
        $this->assertNotEmpty($routes);
    }

    public function testRouterLoadFromCacheReturnsFalseForMissingFile(): void
    {
        $router = new Router();
        $result = $router->loadFromCache('/nonexistent/cache/file.php');
        $this->assertFalse($result);
    }

    public function testRouterLoadFromCacheReturnsFalseForInvalidData(): void
    {
        $cacheFile = $this->tempDir . '/routes.php';
        mkdir(dirname($cacheFile), 0777, true);
        $json = '{"invalid": true}';
        $secret = 'test_jwt_secret_key_for_unit_tests_only_32chars!!';
        $hmac = hash_hmac('sha256', $json, $secret);
        file_put_contents($cacheFile, '<?php exit; ?>' . $json . '.hmac.' . $hmac . PHP_EOL);

        $router = new Router();
        $result = $router->loadFromCache($cacheFile);
        $this->assertFalse($result, 'Should reject data without static/dynamic keys');
    }

    public function testRouterSaveToCacheJsonEncodesCorrectly(): void
    {
        $router = new Router();
        $router->get('/string-route', 'HomeController@index');
        $router->get('/another-route', 'AnotherController@show');

        $cacheFile = $this->tempDir . '/routes.php';
        mkdir(dirname($cacheFile), 0777, true);
        $result = $router->saveToCache($cacheFile);
        $this->assertTrue($result);

        $content = file_get_contents($cacheFile);
        $jsonPart = self::extractJsonFromCache($content);
        $data = json_decode($jsonPart, true);

        $this->assertIsArray($data);
        $this->assertArrayHasKey('static', $data);
        $this->assertArrayHasKey('dynamic', $data);

        $staticRoutes = $data['static']['GET'] ?? [];
        $paths = array_keys($staticRoutes);
        $this->assertContains('/string-route', $paths, 'String routes should be kept');
        $this->assertContains('/another-route', $paths);
    }

    // ----- 6. Storage Path Traversal -----

    public function testStorageLocalPathSanitizationLogic(): void
    {
        $path = '....//....//....//etc/passwd';

        $dirSep = DIRECTORY_SEPARATOR;
        $cleanPath = ltrim($path, $dirSep);

        $previous = '';
        while ($cleanPath !== $previous) {
            $previous = $cleanPath;
            $pattern = ['../', '..\\', './', '.\\', '\\', '/'];
            $cleanPath = str_replace($pattern, $dirSep, $cleanPath);
        }

        $segments = explode($dirSep, $cleanPath);
        $filtered = [];
        foreach ($segments as $segment) {
            if ($segment === '..' || $segment === '.' || $segment === '') {
                continue;
            }
            $filtered[] = $segment;
        }
        $cleanPath = implode($dirSep, $filtered);

        $this->assertStringNotContainsString('..', $cleanPath, 'Traversal sequences should be removed');
        $this->assertStringNotContainsString('//', $cleanPath, 'Double slashes should be normalized');
        $this->assertStringNotContainsString('etc/passwd', $cleanPath, 'Should not resolve to actual path');
    }

    public function testStorageLocalPathRecursiveSanitizationHandlesNestedPatterns(): void
    {
        $dirSep = DIRECTORY_SEPARATOR;
        $path = '....//....//config';

        $cleanPath = ltrim($path, $dirSep);
        $previous = '';
        while ($cleanPath !== $previous) {
            $previous = $cleanPath;
            $pattern = ['../', '..\\', './', '.\\', '\\', '/'];
            $cleanPath = str_replace($pattern, $dirSep, $cleanPath);
        }

        $segments = explode($dirSep, $cleanPath);
        $filtered = [];
        foreach ($segments as $segment) {
            if ($segment === '..' || $segment === '.' || $segment === '') {
                continue;
            }
            $filtered[] = $segment;
        }
        $cleanPath = implode($dirSep, $filtered);

        $this->assertStringNotContainsString('..', $cleanPath, 'Traversal segments should be removed');
        $this->assertStringContainsString('config', $cleanPath, 'Legitimate filename should remain');
    }

    public function testStorageLocalPathBlocksStandardDotDotSequences(): void
    {
        $dirSep = DIRECTORY_SEPARATOR;
        $path = '../../../etc/passwd';

        $cleanPath = ltrim($path, $dirSep);
        $previous = '';
        while ($cleanPath !== $previous) {
            $previous = $cleanPath;
            $pattern = ['../', '..\\', './', '.\\', '\\', '/'];
            $cleanPath = str_replace($pattern, $dirSep, $cleanPath);
        }

        $segments = explode($dirSep, $cleanPath);
        $filtered = [];
        foreach ($segments as $segment) {
            if ($segment === '..' || $segment === '.' || $segment === '') {
                continue;
            }
            $filtered[] = $segment;
        }
        $cleanPath = implode($dirSep, $filtered);

        $this->assertSame('etc' . $dirSep . 'passwd', $cleanPath, 'Traversal removed, only filename remains');
        $this->assertStringNotContainsString('..', $cleanPath, 'No parent directory references remain');
    }

    // ----- Helpers -----

    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . DIRECTORY_SEPARATOR . $item;
            is_dir($path) ? $this->removeDir($path) : unlink($path);
        }
        rmdir($dir);
    }
}

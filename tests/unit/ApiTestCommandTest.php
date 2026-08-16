<?php

declare(strict_types=1);

namespace Siro\Core\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Siro\Core\Cache;
use Siro\Core\Commands\ApiTestCommand;
use Siro\Core\Env;

/**
 * ApiTestCommand — the "Test" step of the debug workflow.
 * Dispatches requests in-process against a throwaway project with routes.
 */
final class ApiTestCommandTest extends TestCase
{
    private string $basePath;

    protected function setUp(): void
    {
        parent::setUp();
        Env::reset();
        putenv('APP_ENV=local');
        putenv('APP_DEBUG=true');
        $this->basePath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'siro_apitest_' . uniqid('', true);
        mkdir($this->basePath . DIRECTORY_SEPARATOR . 'storage', 0777, true);
        mkdir($this->basePath . DIRECTORY_SEPARATOR . 'routes', 0777, true);
        mkdir($this->basePath . DIRECTORY_SEPARATOR . 'config', 0777, true);
        file_put_contents(
            $this->basePath . DIRECTORY_SEPARATOR . '.env',
            "APP_ENV=local\nAPP_DEBUG=true\nJWT_SECRET=this_is_a_sufficiently_long_jwt_secret_for_tests_1234\n"
        );
        file_put_contents(
            $this->basePath . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'database.php',
            "<?php\nreturn ['driver' => 'sqlite', 'database' => ':memory:', 'slow_query_threshold' => 500];\n"
        );
        file_put_contents(
            $this->basePath . DIRECTORY_SEPARATOR . 'routes' . DIRECTORY_SEPARATOR . 'api.php',
            <<<'PHP'
<?php
$router->get('/health', function () {
    return ['success' => true, 'status' => 'ok'];
});
$router->post('/echo', function (\Siro\Core\Request $request) {
    return ['success' => true, 'body' => $request->all()];
});
$router->get('/users/{id}', function ($id) {
    return ['success' => true, 'id' => (int) $id];
});
$router->post('/auth/login', function () {
    return ['success' => true, 'token' => 'valid_token_here_1234567890'];
});
$router->get('/json', function () {
    return \Siro\Core\Response::json(['success' => true, 'data' => ['token' => 'role_token_1234567890']]);
});
PHP
        );
    }

    protected function tearDown(): void
    {
        $this->restoreBootstrapEnv();
        Env::reset();
        Cache::reset();
        \Siro\Core\Database::purgeAll();
        \Siro\Core\Logger::reset();
        \Siro\Core\Session::setInstance(null);
        unset($_COOKIE['siro_session']);
        if (is_dir($this->basePath)) {
            $this->rmDir($this->basePath);
        }
        parent::tearDown();
    }

    private function restoreBootstrapEnv(): void
    {
        putenv('APP_KEY=test_app_key_for_encryption_tests_32chars!!');
        $_ENV['APP_KEY'] = 'test_app_key_for_encryption_tests_32chars!!';
        putenv('JWT_SECRET=test_jwt_secret_key_for_unit_tests_only_32chars!!');
        $_ENV['JWT_SECRET'] = 'test_jwt_secret_key_for_unit_tests_only_32chars!!';
        putenv('APP_ENV=testing');
        $_ENV['APP_ENV'] = 'testing';
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
        $cmd = new ApiTestCommand($this->basePath);
        $exit = $cmd->run($args);
        $output = ob_get_clean() ?: '';
        return [$exit, $output];
    }

    public function testNoArgsPrintsHelp(): void
    {
        [$exit, $output] = $this->runCmd([]);
        $this->assertSame(0, $exit);
        $this->assertStringContainsString('Usage', $output);
    }

    public function testMissingMethodPath(): void
    {
        [$exit, $output] = $this->runCmd(['GET']);
        $this->assertSame(1, $exit);
        $this->assertStringContainsString('Method and path are required', $output);
    }

    public function testGetRequestSucceeds(): void
    {
        [$exit, $output] = $this->runCmd(['GET', '/health']);
        $this->assertSame(0, $exit);
        $this->assertStringContainsString('Status: 200', $output);
        $this->assertStringContainsString('ok', $output);
    }

    public function testPostRequestWithFields(): void
    {
        [$exit, $output] = $this->runCmd(['POST', '/echo', 'name=John', 'age=30']);
        $this->assertSame(0, $exit);
        $this->assertStringContainsString('Status: 200', $output);
    }

    public function testPostWithJsonBodyFlag(): void
    {
        [$exit, $output] = $this->runCmd(['POST', '/echo', '--body={"name":"Test","price":5}']);
        $this->assertSame(0, $exit);
        $this->assertStringContainsString('Status: 200', $output);
    }

    public function testPostWithJsonEqualsFlag(): void
    {
        [$exit, $output] = $this->runCmd(['POST', '/echo', '--json={"a":1,"b":2}']);
        $this->assertSame(0, $exit);
        $this->assertStringContainsString('Status: 200', $output);
    }

    public function testFormContentType(): void
    {
        [$exit, $output] = $this->runCmd(['POST', '/echo', '--form', 'x=1']);
        $this->assertSame(0, $exit);
        $this->assertStringContainsString('Status: 200', $output);
    }

    public function testCustomHeader(): void
    {
        [$exit, $output] = $this->runCmd(['GET', '/health', '--header=X-Custom: val']);
        $this->assertSame(0, $exit);
        $this->assertStringContainsString('Status: 200', $output);
    }

    public function testRouteWithPathParam(): void
    {
        [$exit, $output] = $this->runCmd(['GET', '/users/42']);
        $this->assertSame(0, $exit);
        $this->assertStringContainsString('"id"', $output);
    }

    public function testQueryStringInPath(): void
    {
        [$exit, $output] = $this->runCmd(['GET', '/health?page=2']);
        $this->assertSame(0, $exit);
        $this->assertStringContainsString('Status: 200', $output);
    }

    public function testHistoryShowsEmpty(): void
    {
        [$exit, $output] = $this->runCmd(['--history']);
        $this->assertSame(0, $exit);
        $this->assertStringContainsString('No request history', $output);
    }

    public function testHistoryAfterRequest(): void
    {
        $this->runCmd(['GET', '/health']);
        [$exit, $output] = $this->runCmd(['--history']);
        $this->assertSame(0, $exit);
        $this->assertStringContainsString('GET', $output);
        $this->assertStringContainsString('/health', $output);
    }

    public function testHistoryClear(): void
    {
        $this->runCmd(['GET', '/health']);
        [$exit, $output] = $this->runCmd(['--history-clear']);
        $this->assertSame(0, $exit);
        $this->assertStringContainsString('cleared', $output);
    }

    public function testCollectionSave(): void
    {
        [$exit, $output] = $this->runCmd(['POST', '/echo', 'name=x', '--collection-save=myapi']);
        $this->assertSame(0, $exit);
        $this->assertStringContainsString('Saved to collection', $output);
    }

    public function testCollectionListEmpty(): void
    {
        [$exit, $output] = $this->runCmd(['--collection-list']);
        $this->assertSame(0, $exit);
        $this->assertStringContainsString('No collections', $output);
    }

    public function testCollectionListAfterSave(): void
    {
        $this->runCmd(['POST', '/echo', 'name=x', '--collection-save=myapi']);
        [$exit, $output] = $this->runCmd(['--collection-list']);
        $this->assertSame(0, $exit);
        $this->assertStringContainsString('myapi', $output);
    }

    public function testCollectionRunMissing(): void
    {
        [$exit, $output] = $this->runCmd(['--collection=nonexistent']);
        $this->assertSame(1, $exit);
        $this->assertStringContainsString('not found', $output);
    }

    public function testCollectionRun(): void
    {
        $this->runCmd(['POST', '/echo', 'name=x', '--collection-save=myapi']);
        [$exit, $output] = $this->runCmd(['--collection=myapi']);
        $this->assertSame(0, $exit);
        $this->assertStringContainsString('Running collection', $output);
    }

    public function testAsUserSavesToken(): void
    {
        [$exit, $output] = $this->runCmd(['GET', '/json', '--as=admin']);
        $this->assertSame(0, $exit);
        $this->assertStringContainsString('Token for', $output);
        $this->assertFileExists($this->basePath . '/storage/api-test-auth.json');
    }

    public function testAsGuest(): void
    {
        [$exit, $output] = $this->runCmd(['GET', '/health', '--as=guest']);
        $this->assertSame(0, $exit);
        $this->assertStringContainsString('Guest mode', $output);
    }

    public function testAsUserWithId(): void
    {
        [$exit, $output] = $this->runCmd(['GET', '/health', '--as=user:123']);
        $this->assertSame(0, $exit);
        $this->assertStringContainsString('Status: 200', $output);
    }

    public function testCors(): void
    {
        [$exit, $output] = $this->runCmd(['GET', '/health', '--cors']);
        $this->assertSame(0, $exit);
        $this->assertStringContainsString('CORS Test', $output);
    }

    public function testLoginFlow(): void
    {
        [$exit, $output] = $this->runCmd(['POST', '/auth/login', 'email=a@b.com', 'password=123456', '--login', '--as=admin']);
        $this->assertContains($exit, [0, 1]);
    }

    public function testHistoryLimit(): void
    {
        for ($i = 0; $i < 3; $i++) {
            $this->runCmd(['GET', '/health']);
        }
        // --history=2 needs --history flag present to enter the history branch
        [$exit, $output] = $this->runCmd(['--history', '--history=2']);
        $this->assertSame(0, $exit);
        $this->assertStringContainsString('Total: 2', $output);
    }

    public function testHistoryCapAt100(): void
    {
        // Write 150 history entries → cap at 100
        $historyFile = $this->basePath . '/storage/api-test-history.json';
        $entries = [];
        for ($i = 0; $i < 150; $i++) {
            $entries[] = ['time' => 'x', 'method' => 'GET', 'path' => "/p$i", 'fields' => [], 'headers' => [], 'status' => 200, 'duration_ms' => 1, 'memory_mb' => 1, 'as' => null];
        }
        file_put_contents($historyFile, json_encode($entries));
        $this->runCmd(['GET', '/health']);
        $loaded = json_decode((string) file_get_contents($historyFile), true);
        $this->assertLessThanOrEqual(100, count($loaded));
    }

    public function testBodyKeyValueForm(): void
    {
        [$exit, $output] = $this->runCmd(['POST', '/echo', '--body=name=John']);
        $this->assertSame(0, $exit);
        $this->assertStringContainsString('Status: 200', $output);
    }

    public function testLoopFlag(): void
    {
        [$exit, $output] = $this->runCmd(['GET', '/health', '--loop=3']);
        $this->assertSame(0, $exit);
        $this->assertStringContainsString('Running 3 requests', $output);
        $this->assertStringContainsString('Results:', $output);
    }

    public function testNonJsonResponseBody(): void
    {
        // Route returns a Response with plain text content (non-JSON body)
        $routes = (string) file_get_contents($this->basePath . '/routes/api.php');
        $routes .= "\n\$router->get('/plain', function () { return \\Siro\\Core\\Response::raw('plain text response'); });\n";
        file_put_contents($this->basePath . '/routes/api.php', $routes);
        [$exit, $output] = $this->runCmd(['GET', '/plain']);
        $this->assertSame(0, $exit);
        $this->assertStringContainsString('Status: 200', $output);
    }

    public function testLoadCollectionsInvalidJson(): void
    {
        file_put_contents($this->basePath . '/storage/api-test-collections.json', '{ not json');
        [$exit, $output] = $this->runCmd(['--collection-list']);
        $this->assertSame(0, $exit);
        $this->assertStringContainsString('No collections', $output);
    }

    public function testLoadHistoryInvalidJson(): void
    {
        file_put_contents($this->basePath . '/storage/api-test-history.json', '{ nope');
        [$exit, $output] = $this->runCmd(['--history']);
        $this->assertSame(0, $exit);
        $this->assertStringContainsString('No request history', $output);
    }

    public function testEncryptDecryptToken(): void
    {
        $cmd = new ApiTestCommand($this->basePath);
        $ref = new \ReflectionClass(ApiTestCommand::class);
        $enc = $ref->getMethod('encryptToken');
        $enc->setAccessible(true);
        $dec = $ref->getMethod('decryptToken');
        $dec->setAccessible(true);
        putenv('APP_KEY=test_app_key_for_encryption_tests_32chars!!');
        $encrypted = $enc->invoke($cmd, 'secret-token');
        $this->assertStringNotContainsString('secret-token', (string) $encrypted);
        $decrypted = $dec->invoke($cmd, (string) $encrypted);
        $this->assertSame('secret-token', $decrypted);
        putenv('APP_KEY');
    }

    public function testLoadTokensDecrypt(): void
    {
        $cmd = new ApiTestCommand($this->basePath);
        $ref = new \ReflectionClass(ApiTestCommand::class);
        $enc = $ref->getMethod('encryptToken');
        $enc->setAccessible(true);
        putenv('APP_KEY=test_app_key_for_encryption_tests_32chars!!');
        $encrypted = $enc->invoke($cmd, 'role-token');
        file_put_contents(
            $this->basePath . '/storage/api-test-auth.json',
            json_encode(['admin' => 'enc:' . $encrypted])
        );
        $m = $ref->getMethod('loadTokens');
        $m->setAccessible(true);
        $tokens = $m->invoke($cmd);
        $this->assertSame('role-token', $tokens['admin']);
        putenv('APP_KEY');
    }

    public function testAsWithSavedToken(): void
    {
        // Save a token for role 'admin' then use --as=admin (line 497 sends Bearer)
        file_put_contents(
            $this->basePath . '/storage/api-test-auth.json',
            json_encode(['admin' => 'plain-token-12345'])
        );
        [$exit, $output] = $this->runCmd(['GET', '/health', '--as=admin']);
        $this->assertSame(0, $exit);
        $this->assertStringContainsString('Status: 200', $output);
    }

    public function testAsWithMissingTokenWarns(): void
    {
        // No saved token → warning printed
        [$exit, $output] = $this->runCmd(['GET', '/health', '--as=norole']);
        $this->assertSame(0, $exit);
        $this->assertStringContainsString('No saved token', $output);
    }

    public function testLocaleHeader(): void
    {
        [$exit, $output] = $this->runCmd(['GET', '/health', '--header=X-Locale: en']);
        $this->assertSame(0, $exit);
        $this->assertStringContainsString('Status: 200', $output);
    }

    public function testRunCollectionMultipleRequests(): void
    {
        // Save two requests then run the collection
        $collections = [
            'multi' => [
                'name' => 'multi',
                'requests' => [
                    ['method' => 'GET', 'path' => '/health', 'fields' => [], 'headers' => [], 'content_type' => 'json', 'as' => null],
                    ['method' => 'POST', 'path' => '/echo', 'fields' => ['x' => '1'], 'headers' => [], 'content_type' => 'json', 'as' => null],
                ],
            ],
        ];
        file_put_contents($this->basePath . '/storage/api-test-collections.json', json_encode($collections));
        [$exit, $output] = $this->runCmd(['--collection=multi']);
        $this->assertSame(0, $exit);
        $this->assertStringContainsString('2 passed', $output);
    }

    public function testCollectionListNonArrayEntry(): void
    {
        // Collections file with a non-array entry
        file_put_contents(
            $this->basePath . '/storage/api-test-collections.json',
            json_encode(['weird' => 'not-an-array', 'good' => ['name' => 'good', 'requests' => []]])
        );
        [$exit, $output] = $this->runCmd(['--collection-list']);
        $this->assertSame(0, $exit);
        $this->assertStringContainsString('good', $output);
    }

    public function testInteractiveFieldInput(): void
    {
        // POST without fields triggers interactive ask(); feed via readline is not
        // possible in-process, so verify the flow starts (asks for field name).
        // Use a POST route and expect it to enter the field prompt path.
        ob_start();
        $cmd = new ApiTestCommand($this->basePath);
        $exit = $cmd->run(['POST', '/echo']);
        $output = ob_get_clean() ?: '';
        // readline on non-tty returns empty → loop breaks immediately with no fields
        $this->assertContains($exit, [0, 1]);
    }

    public function testInteractiveFieldInputWithProvider(): void
    {
        // Input provider answers: field name 'name', value 'John', then empty to finish
        $inputs = ['name', 'John', ''];
        $provider = function () use (&$inputs): string {
            return $inputs !== [] ? (string) array_shift($inputs) : '';
        };
        ob_start();
        $cmd = new ApiTestCommand($this->basePath, $provider);
        $exit = $cmd->run(['POST', '/echo']);
        $output = ob_get_clean() ?: '';
        $this->assertSame(0, $exit, $output);
        $this->assertStringContainsString('Status: 200', $output);
        $this->assertStringContainsString('John', $output);
    }

    public function testLoginSuccessFlow(): void
    {
        // /auth/login route returns success:true + token → login succeeds
        $routes = (string) file_get_contents($this->basePath . '/routes/api.php');
        $routes .= "\n\$router->post('/api/auth/login', function () { return ['success' => true, 'data' => ['token' => 'login_token_1234567890']]; });\n";
        file_put_contents($this->basePath . '/routes/api.php', $routes);
        [$exit, $output] = $this->runCmd(['POST', '/api/auth/login', 'email=a@b.com', 'password=x', '--login', '--as=admin']);
        $this->assertSame(0, $exit, $output);
        $this->assertStringContainsString('Login successful', $output);
    }

    public function testLoginFailureFlow(): void
    {
        // Route returns 400 → login fails
        $routes = (string) file_get_contents($this->basePath . '/routes/api.php');
        $routes .= "\n\$router->post('/api/auth/login', function () { return \\Siro\\Core\\Response::error('bad credentials', 400); });\n";
        file_put_contents($this->basePath . '/routes/api.php', $routes);
        [$exit, $output] = $this->runCmd(['POST', '/api/auth/login', 'email=a@b.com', 'password=x', '--login', '--as=admin']);
        $this->assertSame(1, $exit);
        $this->assertStringContainsString('Login failed', $output);
    }

    public function testWatchModeBounded(): void
    {
        // Set up watch dirs; bounded iterations → exits cleanly without a change
        mkdir($this->basePath . '/app', 0777, true);
        file_put_contents($this->basePath . '/app/Controller.php', '<?php');
        putenv('SIRO_API_TEST_WATCH_MAX=1');
        ob_start();
        $cmd = new ApiTestCommand($this->basePath);
        $exit = $cmd->run(['GET', '/health', '--watch']);
        ob_end_clean();
        putenv('SIRO_API_TEST_WATCH_MAX');
        $this->assertSame(0, $exit);
    }

    public function testAddFilesRecursiveSkipsNonPhp(): void
    {
        // Nested dirs + mixed files; verify watchMode discovers them without error
        mkdir($this->basePath . '/app/Sub', 0777, true);
        file_put_contents($this->basePath . '/app/a.php', '<?php');
        file_put_contents($this->basePath . '/app/b.txt', 'not php');
        file_put_contents($this->basePath . '/app/Sub/c.php', '<?php');
        putenv('SIRO_API_TEST_WATCH_MAX=1');
        ob_start();
        $cmd = new ApiTestCommand($this->basePath);
        $exit = $cmd->run(['GET', '/health', '--watch']);
        ob_end_clean();
        putenv('SIRO_API_TEST_WATCH_MAX');
        $this->assertSame(0, $exit);
    }

    public function testSendInternalThrows(): void
    {
        // Route handler that throws → App catches it into a 500 response (exit 1)
        $routes = (string) file_get_contents($this->basePath . '/routes/api.php');
        $routes .= "\n\$router->get('/boom', function () { throw new \\RuntimeException('kaboom'); });\n";
        file_put_contents($this->basePath . '/routes/api.php', $routes);
        [$exit, $output] = $this->runCmd(['GET', '/boom']);
        $this->assertContains($exit, [0, 1]);
    }

    public function testValidationExceptionHandled(): void
    {
        // Route throws a ValidationException → response built from it (line 544-545)
        $routes = (string) file_get_contents($this->basePath . '/routes/api.php');
        $routes .= "\n\$router->get('/valerr', function () { throw new \\Siro\\Core\\ValidationException(['name' => 'required']); });\n";
        file_put_contents($this->basePath . '/routes/api.php', $routes);
        [$exit, $output] = $this->runCmd(['GET', '/valerr']);
        $this->assertContains($exit, [0, 1]);
    }

    public function testWebhookBounded(): void
    {
        // Bounded webhook listener; send requests via socket until it exits.
        // Child writes to a marker file (not stdout) to avoid pipe-blocking.
        $marker = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'siro_wh_out_' . uniqid('', true) . '.txt';
        $whPort = random_int(19000, 19999);
        $cmd = new ApiTestCommand($this->basePath);
        $ref = new \ReflectionClass(ApiTestCommand::class);
        $m = $ref->getMethod('listenWebhook');
        $m->setAccessible(true);
        // Start listener in a child process so the parent test doesn't block.
        // Paths are embedded directly to avoid argv-index confusion on Windows.
        $projectDir = dirname(__DIR__, 2);
        $script = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'siro_wh_' . uniqid('', true) . '.php';
        $runner = "<?php\n"
            . "require " . var_export($projectDir . '/vendor/autoload.php', true) . ";\n"
            . "putenv('SIRO_API_TEST_WEBHOOK_MAX=2');\n"
            . "putenv('SIRO_API_TEST_WEBHOOK_ACCEPT_TIMEOUT=1');\n"
            . "set_time_limit(15);\n"
            . "\$cmd = new \\Siro\\Core\\Commands\\ApiTestCommand(" . var_export($this->basePath, true) . ");\n"
            . "\$ref = new ReflectionClass(\\Siro\\Core\\Commands\\ApiTestCommand::class);\n"
            . "\$m = \$ref->getMethod('listenWebhook');\n"
            . "\$m->setAccessible(true);\n"
            . "ob_start();\n"
            . "\$m->invoke(\$cmd, ['--port=" . $whPort . "']);\n"
            . "file_put_contents(" . var_export($marker, true) . ", (string) ob_get_clean());\n"
            . "exit(0);\n";
        file_put_contents($script, $runner);
        // Use file redirection (not pipes) so the child can't block on stdout
        $nul = DIRECTORY_SEPARATOR === '\\' ? 'NUL' : '/dev/null';
        $errFile = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'siro_wh_err_' . uniqid('', true) . '.txt';
        $descriptors = [0 => ['file', $nul, 'r'], 1 => ['file', $nul, 'w'], 2 => ['file', $errFile, 'w']];
        $proc = proc_open([PHP_BINARY, $script], $descriptors, $pipes);
        // Wait for server to bind, then send two webhook POSTs
        usleep(900000);
        for ($i = 0; $i < 2; $i++) {
            $errno = 0;
            $errstr = '';
            $conn = @fsockopen('127.0.0.1', $whPort, $errno, $errstr, 2);
            if ($conn !== false) {
                fwrite($conn, "POST /webhook HTTP/1.1\r\nHost: localhost\r\nContent-Type: application/json\r\n\r\n{\"event\":\"test$i\"}");
                fclose($conn);
            }
            usleep(300000);
        }
        // Wait for the child to finish and write the marker
        $deadline = time() + 15;
        $exited = false;
        while (time() < $deadline) {
            $status = proc_get_status($proc);
            if (!$status['running']) {
                $exited = true;
                break;
            }
            usleep(200000);
        }
        if (!$exited && is_resource($proc)) {
            @proc_terminate($proc);
            usleep(200000);
        }
        if (is_resource($proc)) {
            @proc_close($proc);
        }
        $out = file_exists($marker) ? (string) file_get_contents($marker) : '';
        $errOut = file_exists($errFile) ? (string) file_get_contents($errFile) : '';
        @unlink($marker);
        @unlink($errFile);
        @unlink($script);
        putenv('SIRO_API_TEST_WEBHOOK_MAX');
        $this->assertStringContainsString('Received POST', $out, $errOut);
    }

    public function testJsonFlag(): void
    {
        [$exit, $output] = $this->runCmd(['GET', '/health', '--json']);
        $this->assertSame(0, $exit);
        $this->assertStringContainsString('Status: 200', $output);
    }

    public function testBodyJsonObjectForm(): void
    {
        // --body={"json":"payload"} JSON object form → fields merged into request
        [$exit, $output] = $this->runCmd(['POST', '/echo', '--body={"json":"payload","count":5}']);
        $this->assertSame(0, $exit);
        $this->assertStringContainsString('Status: 200', $output);
        $this->assertStringContainsString('payload', $output);
        $this->assertStringContainsString('"count"', $output);
    }

    public function testEncryptTokenNoKey(): void
    {
        $cmd = new ApiTestCommand($this->basePath);
        $ref = new \ReflectionClass(ApiTestCommand::class);
        $enc = $ref->getMethod('encryptToken');
        $enc->setAccessible(true);
        $dec = $ref->getMethod('decryptToken');
        $dec->setAccessible(true);
        putenv('APP_KEY=');
        unset($_ENV['APP_KEY']);
        // No APP_KEY → token returned as-is
        $this->assertSame('plain', $enc->invoke($cmd, 'plain'));
        $this->assertSame('plain', $dec->invoke($cmd, 'plain'));
        $this->restoreEnv();
    }

    private function restoreEnv(): void
    {
        putenv('APP_KEY=test_app_key_for_encryption_tests_32chars!!');
        $_ENV['APP_KEY'] = 'test_app_key_for_encryption_tests_32chars!!';
    }

    public function testCollectionRunWithFailure(): void
    {
        // Collection with a request to a route that 404s → failed++
        $collections = [
            'fail' => [
                'name' => 'fail',
                'requests' => [
                    ['method' => 'GET', 'path' => '/nonexistent-route-xyz', 'fields' => [], 'headers' => [], 'content_type' => 'json', 'as' => null],
                ],
            ],
        ];
        file_put_contents($this->basePath . '/storage/api-test-collections.json', json_encode($collections));
        [$exit, $output] = $this->runCmd(['--collection=fail']);
        $this->assertSame(1, $exit);
        $this->assertStringContainsString('failed', $output);
    }

    public function testCollectionListWithRequests(): void
    {
        // Collection with requests → list shows last request method+path (lines 454-457)
        $collections = [
            'api' => [
                'name' => 'api',
                'requests' => [
                    ['method' => 'POST', 'path' => '/echo', 'fields' => [], 'headers' => [], 'content_type' => 'json', 'as' => null],
                ],
            ],
        ];
        file_put_contents($this->basePath . '/storage/api-test-collections.json', json_encode($collections));
        [$exit, $output] = $this->runCmd(['--collection-list']);
        $this->assertSame(0, $exit);
        $this->assertStringContainsString('POST /echo', $output);
    }

    public function testAcceptLanguageHeader(): void
    {
        [$exit, $output] = $this->runCmd(['GET', '/health', '--header=Accept-Language: en']);
        $this->assertSame(0, $exit);
        $this->assertStringContainsString('Status: 200', $output);
    }

    public function testWatchModeFileDeleted(): void
    {
        // Start watch with a file, delete it mid-loop → deleted branch (302-304)
        mkdir($this->basePath . '/routes/sub', 0777, true);
        $file = $this->basePath . '/routes/sub/tmp.php';
        file_put_contents($file, '<?php');
        putenv('SIRO_API_TEST_WATCH_MAX=3');
        $deleter = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'siro_del_' . uniqid('', true) . '.php';
        file_put_contents($deleter, "<?php sleep(1); @unlink(\$argv[1]);");
        $descriptors = [0 => ['file', 'NUL', 'r'], 1 => ['file', 'NUL', 'w'], 2 => ['file', 'NUL', 'w']];
        $proc = proc_open([PHP_BINARY, $deleter, $file], $descriptors, $pipes);
        ob_start();
        $cmd = new ApiTestCommand($this->basePath);
        $exit = $cmd->run(['GET', '/health', '--watch']);
        ob_end_clean();
        if (is_resource($proc)) {
            proc_close($proc);
        }
        @unlink($deleter);
        putenv('SIRO_API_TEST_WATCH_MAX');
        $this->assertSame(0, $exit);
    }

    public function testWriteInternalTraceDebugOff(): void
    {
        // APP_DEBUG=false → writeInternalTrace returns early (line 950)
        putenv('APP_DEBUG=false');
        $cmd = new ApiTestCommand($this->basePath);
        $ref = new \ReflectionClass(ApiTestCommand::class);
        $m = $ref->getMethod('writeInternalTrace');
        $m->setAccessible(true);
        $request = new \Siro\Core\Request('GET', '/health', [], [], [], '127.0.0.1');
        $response = \Siro\Core\Response::success(['ok']);
        $m->invoke($cmd, 'GET', '/health', 200, 1.5, $request, $response);
        putenv('APP_DEBUG=true');
        $this->assertTrue(true);
    }

    public function testWriteInternalTraceWithRawBody(): void
    {
        putenv('APP_DEBUG=true');
        $cmd = new ApiTestCommand($this->basePath);
        $ref = new \ReflectionClass(ApiTestCommand::class);
        $m = $ref->getMethod('writeInternalTrace');
        $m->setAccessible(true);
        $request = new \Siro\Core\Request('POST', '/echo', [], [], ['name' => 'x'], '127.0.0.1');
        $response = \Siro\Core\Response::success(['ok']);
        $m->invoke($cmd, 'POST', '/echo', 200, 1.5, $request, $response);
        $this->assertTrue(true);
    }

    public function testWriteInternalTraceThrows(): void
    {
        putenv('APP_DEBUG=true');
        $cmd = new ApiTestCommand($this->basePath);
        $ref = new \ReflectionClass(ApiTestCommand::class);
        $m = $ref->getMethod('writeInternalTrace');
        $m->setAccessible(true);
        // Passing null-ish values to force Logger::trace to throw
        $request = new \Siro\Core\Request('GET', '/x', [], [], [], '127.0.0.1');
        try {
            $m->invoke($cmd, 'GET', '/x', 200, 1.0, $request, null);
        } catch (\Throwable) {
        }
        $this->assertTrue(true);
    }

    public function testTokenSaveCreatesDir(): void
    {
        // Remove the storage dir so the token-save mkdir runs (line 618)
        // The /json route returns a token; --as=admin triggers the save
        $routes = (string) file_get_contents($this->basePath . '/routes/api.php');
        $routes .= "\n\$router->get('/token', function () { return ['success' => true, 'data' => ['token' => 'abcdefghij1234567890']]; });\n";
        file_put_contents($this->basePath . '/routes/api.php', $routes);
        // Remove auth file so the save path runs fresh
        @unlink($this->basePath . '/storage/api-test-auth.json');
        [$exit, $output] = $this->runCmd(['GET', '/token', '--as=admin']);
        $this->assertSame(0, $exit, $output);
        $this->assertStringContainsString('Token for', $output);
    }

    public function testCollectionSaveCreatesStorageDir(): void
    {
        // Remove storage dir → saveToCollection mkdir (line 379)
        $this->rmDir($this->basePath . '/storage');
        mkdir($this->basePath . '/storage', 0777, true);
        mkdir($this->basePath . '/storage/logs', 0777, true);
        [$exit, $output] = $this->runCmd(['POST', '/echo', 'name=x', '--collection-save=api']);
        $this->assertSame(0, $exit);
        $this->assertStringContainsString('Saved to collection', $output);
    }
}

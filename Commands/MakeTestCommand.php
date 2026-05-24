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
        // Check for --from-trace=<id>
        $fromTrace = '';
        $ignoreFields = [];
        foreach ($args as $arg) {
            if (str_starts_with($arg, '--from-trace=')) {
                $fromTrace = substr($arg, 13);
            } elseif (str_starts_with($arg, '--ignore=')) {
                $ignoreRaw = substr($arg, 9);
                $ignoreFields = array_map('trim', explode(',', $ignoreRaw));
            }
        }

        if ($fromTrace !== '') {
            return $this->fromTrace($fromTrace, $ignoreFields);
        }

        $name = trim((string) ($args[0] ?? ''));
        if ($name === '') {
            $this->write('Usage: php siro make:test <name> [--unit]');
            $this->write('       php siro make:test --from-trace=<trace_id> [--ignore=id,created_at]');
            return 1;
        }

        $name = preg_replace('/[^a-zA-Z0-9_]+/', '', $name) ?? $name;
        $name = trim($name, '_');
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
        $suite = $isUnit ? 'Unit' : 'Feature';
        $this->write('  Run: vendor/bin/phpunit --testsuite=' . $suite . ' --filter=' . $className . $suffix);
        return 0;
    }

    /**
     * Generate test from a captured trace.
     *
     * @param array<int, string> $ignoreFields
     */
    private function fromTrace(string $traceId, array $ignoreFields): int
    {
        $tracesDir = $this->getTracesDir($this->basePath);
        $traceFile = $this->findTraceById($tracesDir, $traceId);

        if ($traceFile === null) {
            $this->write('  ⚠ Trace not found: ' . $traceId);
            return 1;
        }

        $data = json_decode((string) file_get_contents($traceFile), true);
        if (!is_array($data)) {
            $this->write('  ⚠ Invalid trace file.');
            return 1;
        }

        $method = strtoupper($this->safeStr($data['method'] ?? 'GET'));
        $apiPath = $this->safeStr($data['path'] ?? '/');
        $path = $apiPath;
        $status = is_numeric($data['status'] ?? null) ? (int) $data['status'] : 200;
        $bodyRaw = $this->safeStr($data['request_body'] ?? '');
        $authHeader = $this->safeStr($data['auth_header'] ?? '');

        // Parse body: try JSON first
        $bodyArray = [];
        $bodyJson = '[]';
        if ($bodyRaw !== '' && $bodyRaw !== '[]' && $bodyRaw !== '{}') {
            $decoded = json_decode($bodyRaw, true);
            if (is_array($decoded)) {
                $bodyArray = $decoded;
                $bodyJson = (string) json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            }
        }

        // Parse response to extract structure
        $responseRaw = $this->safeStr($data['response_body'] ?? '');
        $responseKeys = [];
        if ($responseRaw !== '' && $responseRaw !== '{}') {
            $responseDecoded = json_decode($responseRaw, true);
            if (is_array($responseDecoded)) {
                $responseKeys = $this->extractKeys($responseDecoded, $ignoreFields);
            }
        }

        // Generate test method name from path
        $testName = 'test_' . strtolower($method) . '_' . trim(preg_replace('/[^a-zA-Z0-9]+/', '_', $apiPath), '_');
        $testName = substr($testName, 0, 80);

        // Status assertion method
        $statusAssert = match (true) {
            $status === 200 => "assertOk()",
            $status === 201 => "assertCreated()",
            $status === 204 => "assertNoContent()",
            $status === 401 => "assertUnauthorized()",
            $status === 403 => "assertForbidden()",
            $status === 404 => "assertNotFound()",
            $status === 422 => "assertValidationError()",
            default => "assertStatus($status)",
        };

        // HTTP method helper
        $httpHelper = strtolower($method);
        if (!in_array($httpHelper, ['get', 'post', 'put', 'delete', 'patch'], true)) {
            $httpHelper = 'get';
        }

        // Generate test file
        $testBody = $this->safeStr($bodyJson);
        $hasAuth = $authHeader !== '';
        $authHeaderVar = $hasAuth ? "\n        \$headers = \$this->authenticate();" : '';
        $authArg = $hasAuth ? ', $headers' : '';

        // Generate assertJsonStructure calls
        $assertStructure = '';
        if ($responseKeys !== []) {
            $keysStr = var_export($responseKeys, true);
            $indented = str_replace("\n", "\n        ", $keysStr);
            $assertStructure = "\n        \$response->assertJsonPath('success', " . ($status < 400 ? 'true' : 'false') . ");";
        }

        $bodyArg = $bodyArray !== [] ? var_export($bodyArray, true) : '[]';
        $bodyArg = str_replace("\n", "\n        ", (string) $bodyArg);

        $content = <<<PHP
<?php

declare(strict_types=1);

namespace App\Tests\Feature;

use App\Tests\TestCase;

final class FromTrace{$traceId}Test extends TestCase
{
    public function {$testName}(): void
    {{$authHeaderVar}
        \$response = \$this->{$httpHelper}('{$path}', {$bodyArg}{$authArg});
        \$response->{$statusAssert};

        // Verify JSON structure
        \$body = \$response->json();
        \$this->assertArrayHasKey('success', \$body);
        \$this->assertArrayHasKey('message', \$body);
        {$assertStructure}
    }
}

PHP;

        $dir = $this->basePath . DIRECTORY_SEPARATOR . 'tests' . DIRECTORY_SEPARATOR . 'Feature';
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        $filename = 'FromTrace' . $traceId . 'Test.php';
        $path = $dir . DIRECTORY_SEPARATOR . $filename;

        file_put_contents($path, $content);
        $this->write('Generated: tests/Feature/' . $filename);
        $this->write('  Run: vendor/bin/phpunit --testsuite=Feature --filter=FromTrace' . $traceId);
        $this->write('');
        $this->write('  This test reproduces the exact request from trace: ' . $traceId);
        $this->write('  Request: ' . $method . ' ' . $apiPath . ' → ' . $status);
        $this->write('  Status: ' . $status);
        if ($hasAuth) {
            $this->write('  Auth:  Bearer token (auto-fetched via authenticate())');
        }
        if ($bodyArray !== []) {
            $this->write('  Body:  ' . count($bodyArray) . ' fields');
        }

        return 0;
    }

    /**
     * Extract JSON keys recursively for structure assertion.
     *
     * @param array<string, mixed> $data
     * @param array<int, string> $ignore
     * @return array<string, mixed>
     */
    private function extractKeys(array $data, array $ignore): array
    {
        $keys = [];
        foreach ($data as $key => $value) {
            if (in_array($key, $ignore, true)) {
                continue;
            }
            if (is_array($value)) {
                $sub = $this->extractKeys($value, $ignore);
                if ($sub !== []) {
                    $keys[$key] = $sub;
                } else {
                    $keys[$key] = 'array';
                }
            } else {
                $keys[$key] = gettype($value);
            }
        }
        return $keys;
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

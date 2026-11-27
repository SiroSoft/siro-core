<?php

declare(strict_types=1);

namespace Siro\Core\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Siro\Core\Commands\ApiContractCommand;

/**
 * G7: api:contract --strict --coverage is the CI gate for spec ↔ routes.
 *
 * --strict alone only checks spec→routes (a spec that OMITS routes still
 * passes — proven by testCoverageFailsWhenRouteHasNoSpecOperation, which
 * passes with --strict alone). --coverage closes the gap by checking
 * routes→spec. make:openapi must regenerate the committed spec byte for
 * byte, and the generated Swagger UI must serve 200 at /docs.
 */
final class ApiContractStrictTest extends TestCase
{
    private string $fixture;

    private string $tmp = '';

    /** @var resource|null */
    private $serverProcess = null;

    protected function setUp(): void
    {
        $this->fixture = dirname(__DIR__) . '/fixtures/contract-app';
        putenv('JWT_SECRET=placeholder_jwt_secret_for_tests_only_0000');
        putenv('APP_KEY=placeholder_app_key_for_tests_only_0000');
    }

    protected function tearDown(): void
    {
        $this->stopServer();
        if ($this->tmp !== '' && is_dir($this->tmp)) {
            $this->removeDir($this->tmp);
        }
    }

    public function testStrictFailsWhenSpecOperationHasNoRoute(): void
    {
        $dir = $this->copyFixture();
        $spec = $this->readSpec($dir);
        $spec['paths']['/api/ghost'] = ['get' => ['responses' => ['200' => ['description' => 'ok']]]];
        $this->writeSpec($dir, $spec);

        [$code, $output] = $this->runContract($dir, ['--strict']);
        $this->assertSame(1, $code);
        $this->assertStringContainsString('6 passed', $output);
        $this->assertStringContainsString('1 failed', $output);
    }

    public function testCoverageFailsWhenRouteHasNoSpecOperation(): void
    {
        $dir = $this->copyFixture();
        $spec = $this->readSpec($dir);
        unset($spec['paths']['/contract_widgets/{id}']['delete']);
        $this->writeSpec($dir, $spec);

        // --strict alone passes: the gap --coverage exists to close.
        [$code] = $this->runContract($dir, ['--strict']);
        $this->assertSame(0, $code);

        [$code, $output] = $this->runContract($dir, ['--strict', '--coverage']);
        $this->assertSame(1, $code);
        $this->assertStringContainsString('Route not covered by spec: DELETE /contract_widgets/{id}', $output);
        $this->assertStringContainsString('1 failed', $output);
    }

    public function testStrictAndCoveragePassOnCommittedFixture(): void
    {
        // Read-only on the committed fixture (api:contract never writes).
        [$code, $output] = $this->runContract($this->fixture, ['--strict', '--coverage']);
        $this->assertSame(0, $code);
        $this->assertStringContainsString('6 passed, 0 warnings, 0 failed', $output);
    }

    public function testCoverageAlonePassesOnCommittedFixture(): void
    {
        [$code] = $this->runContract($this->fixture, ['--coverage']);
        $this->assertSame(0, $code, '--coverage must not change --strict semantics when off.');
    }

    public function testRegenerateMatchesCommittedSpecByteForByte(): void
    {
        $dir = $this->copyFixture();
        ob_start();
        $code = (new \Siro\Core\Console($dir))->run(['siro', 'make:openapi', '--force']);
        ob_end_clean();
        $this->assertSame(0, $code, 'make:openapi must succeed on the fixture app.');
        $this->assertSame(
            (string) file_get_contents($this->fixture . '/docs/openapi/openapi.json'),
            (string) file_get_contents($dir . '/docs/openapi/openapi.json'),
            'Regenerated spec must be byte-identical to the committed spec.'
        );
    }

    public function testDocsServes200(): void
    {
        $dir = $this->copyFixture();
        ob_start();
        $code = (new \Siro\Core\Console($dir))->run(['siro', 'make:openapi', '--force', '--with-swagger']);
        ob_end_clean();
        $this->assertSame(0, $code);
        $this->assertFileExists($dir . '/public/docs/index.html');
        $this->assertFileExists($dir . '/public/docs.html');

        $port = $this->startServer($dir . '/public');
        $docs = $this->httpGet('http://127.0.0.1:' . $port . '/docs');
        $this->assertNotNull($docs, 'GET /docs must respond.');
        $this->assertSame(200, $docs[0]);
        $this->assertStringContainsStringIgnoringCase('swagger', $docs[1]);
        $spec = $this->httpGet('http://127.0.0.1:' . $port . '/openapi.json');
        $this->assertNotNull($spec, 'GET /openapi.json must respond.');
        $this->assertSame(200, $spec[0]);
    }

    /** @return array{int, string} */
    private function runContract(string $basePath, array $args): array
    {
        ob_start();
        $code = (new ApiContractCommand($basePath))->run($args);
        return [$code, ob_get_clean() ?: ''];
    }

    private function copyFixture(): string
    {
        $this->tmp = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'siro_contract_strict_' . uniqid('', true);
        mkdir($this->tmp, 0777, true);
        $this->copyDir($this->fixture, $this->tmp);
        return $this->tmp;
    }

    /** @return array<string, mixed> */
    private function readSpec(string $dir): array
    {
        $spec = json_decode((string) file_get_contents($dir . '/docs/openapi/openapi.json'), true);
        $this->assertIsArray($spec);
        /** @var array<string, mixed> $spec */
        return $spec;
    }

    /** @param array<string, mixed> $spec */
    private function writeSpec(string $dir, array $spec): void
    {
        file_put_contents(
            $dir . '/docs/openapi/openapi.json',
            json_encode($spec, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        );
    }

    /**
     * Start php -S on a random free port (proc_open ARRAY form — no sh -c
     * wrapper, so SIGTERM reaches the server itself) and return the port.
     */
    private function startServer(string $docroot): int
    {
        $port = 0;
        for ($i = 0; $i < 20; $i++) {
            $candidate = random_int(18100, 18299);
            $probe = @fsockopen('127.0.0.1', $candidate, $errno, $errstr, 0.2);
            if ($probe === false) {
                $port = $candidate;
                break;
            }
            fclose($probe);
        }
        if ($port === 0) {
            $this->markTestSkipped('Could not find a free port for the docs server.');
        }
        $descriptors = [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']];
        $process = proc_open(['php8.4', '-S', '127.0.0.1:' . $port, '-t', $docroot], $descriptors, $pipes, $docroot);
        if (!is_resource($process)) {
            $this->markTestSkipped('Could not start docs server.');
        }
        $this->serverProcess = $process;
        $ready = false;
        for ($i = 0; $i < 50; $i++) {
            $sock = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.2);
            if (is_resource($sock)) {
                fclose($sock);
                $ready = true;
                break;
            }
            usleep(100000);
        }
        if (!$ready) {
            $this->markTestSkipped('Docs server did not become ready.');
        }
        return $port;
    }

    private function stopServer(): void
    {
        if (!is_resource($this->serverProcess)) {
            return;
        }
        proc_terminate($this->serverProcess);
        $deadline = microtime(true) + 2.0;
        while (microtime(true) < $deadline) {
            $status = proc_get_status($this->serverProcess);
            if (!$status['running']) {
                break;
            }
            usleep(100000);
        }
        $status = proc_get_status($this->serverProcess);
        if ($status['running']) {
            proc_terminate($this->serverProcess, 9);
        }
        proc_close($this->serverProcess);
        $this->serverProcess = null;
    }

    /** @return array{int, string}|null [status, body] */
    private function httpGet(string $url): ?array
    {
        $context = stream_context_create(['http' => ['timeout' => 5, 'ignore_errors' => true]]);
        $body = @file_get_contents($url, false, $context);
        if ($body === false || !isset($http_response_header[0])) {
            return null;
        }
        $status = (int) substr((string) $http_response_header[0], 9, 3);
        return [$status, $body];
    }

    private function copyDir(string $src, string $dst): void
    {
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($src, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );
        foreach ($it as $entry) {
            /** @var \SplFileInfo $entry */
            $target = $dst . DIRECTORY_SEPARATOR . $it->getSubPathName();
            if ($entry->isDir()) {
                mkdir($target, 0777, true);
            } else {
                copy($entry->getPathname(), $target);
            }
        }
    }

    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($it as $entry) {
            /** @var \SplFileInfo $entry */
            $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
        }
        rmdir($dir);
    }
}

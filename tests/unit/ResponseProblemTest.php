<?php

declare(strict_types=1);

namespace Siro\Core\Tests\Unit;

use RuntimeException;
use Siro\Core\AuthorizationException;
use Siro\Core\Config;
use Siro\Core\Env;
use Siro\Core\MalformedBodyException;
use Siro\Core\ModelNotFoundException;
use Siro\Core\Request;
use Siro\Core\Response;
use Siro\Core\Tests\TestCase;
use Siro\Core\ValidationException;

final class ResponseProblemTest extends TestCase
{
    protected function tearDown(): void
    {
        Config::reset();
        Env::reset();
        putenv('SIRO_PROBLEM_DETAILS_DEFAULT');
        unset($_ENV['SIRO_PROBLEM_DETAILS_DEFAULT']);
        parent::tearDown();
    }

    private function makeRequest(string $accept = '', int $version = 1): Request
    {
        $headers = $accept !== '' ? ['Accept' => $accept] : [];
        $request = new Request('GET', '/api/widgets/42', [], $headers, [], '127.0.0.1');
        $request->setVersion($version);
        return $request;
    }

    public function testProblemShape(): void
    {
        $response = Response::problem(
            'Validation Failed',
            422,
            'Name is required.',
            'https://siro.dev/problems/validation-failed',
            '/api/widgets',
            ['errors' => ['name' => ['required']]],
        );

        $this->assertSame(422, $response->statusCode());
        $this->assertTrue($response->isProblem());
        $payload = $response->payload();
        $this->assertSame('https://siro.dev/problems/validation-failed', $payload['type']);
        $this->assertSame('Validation Failed', $payload['title']);
        $this->assertSame(422, $payload['status']);
        $this->assertSame('Name is required.', $payload['detail']);
        $this->assertSame('/api/widgets', $payload['instance']);
        $this->assertSame(['name' => ['required']], $payload['errors']);
    }

    public function testProblemContentTypeHeader(): void
    {
        $response = Response::problem('Not Found', 404, 'Nope.', 'https://siro.dev/problems/not-found');
        $headers = $response->headers();
        $this->assertSame('application/problem+json', $headers['Content-Type'] ?? null);
        $this->assertSame('application/problem+json', $response->getHeader('Content-Type'));
    }

    public function testProblemExtensionsCannotOverrideStandardFields(): void
    {
        $response = Response::problem('Title', 400, 'Detail', 'about:blank', '', [
            'status' => 999,
            'title' => 'Hijacked',
            'custom' => 'kept',
        ]);
        $payload = $response->payload();
        $this->assertSame(400, $payload['status']);
        $this->assertSame('Title', $payload['title']);
        $this->assertSame('kept', $payload['custom']);
    }

    public function testEnvelopeIsNotAProblem(): void
    {
        $error = Response::error('Boom', 500);
        $this->assertFalse($error->isProblem());
        $this->assertArrayNotHasKey('Content-Type', $error->headers());

        $success = Response::success(['a' => 1]);
        $this->assertFalse($success->isProblem());
    }

    public function testProblemSendEmitsJsonBody(): void
    {
        $response = Response::problem('Forbidden', 403, 'Nope.', 'https://siro.dev/problems/forbidden', '/api/x');
        ob_start();
        $response->send();
        $output = ob_get_clean() ?: '';
        /** @var array<string, mixed> $decoded */
        $decoded = json_decode($output, true);
        $this->assertIsArray($decoded);
        $this->assertSame('https://siro.dev/problems/forbidden', $decoded['type']);
        $this->assertSame(403, $decoded['status']);
    }

    public function testFromExceptionValidation(): void
    {
        $e = new ValidationException(['name' => ['required']], 'Name is required.');
        $response = Response::fromException($e, $this->makeRequest());
        $this->assertSame(422, $response->statusCode());
        $payload = $response->payload();
        $this->assertSame('https://siro.dev/problems/validation-failed', $payload['type']);
        $this->assertSame('Validation Failed', $payload['title']);
        $this->assertSame(['name' => ['required']], $payload['errors']);
        $this->assertSame('/api/widgets/42', $payload['instance']);
    }

    public function testFromExceptionNotFound(): void
    {
        $e = new ModelNotFoundException('App\\Models\\Widget', 42);
        $response = Response::fromException($e, $this->makeRequest());
        $this->assertSame(404, $response->statusCode());
        $this->assertSame('https://siro.dev/problems/not-found', $response->payload()['type']);
    }

    public function testFromExceptionForbidden(): void
    {
        $e = new AuthorizationException('Nope.');
        $response = Response::fromException($e, $this->makeRequest());
        $this->assertSame(403, $response->statusCode());
        $this->assertSame('https://siro.dev/problems/forbidden', $response->payload()['type']);
    }

    public function testFromExceptionMalformedBody(): void
    {
        $e = new MalformedBodyException();
        $response = Response::fromException($e, $this->makeRequest());
        $this->assertSame(400, $response->statusCode());
        $this->assertSame('https://siro.dev/problems/malformed-body', $response->payload()['type']);
    }

    public function testFromExceptionGenericNeverLeaksMessage(): void
    {
        $e = new RuntimeException('db password is hunter2 at 10.0.0.9');
        $response = Response::fromException($e, $this->makeRequest());
        $this->assertSame(500, $response->statusCode());
        $payload = $response->payload();
        $this->assertSame('https://siro.dev/problems/internal-server-error', $payload['type']);
        $this->assertSame('Internal Server Error', $payload['detail']);
        $this->assertStringNotContainsString('hunter2', (string) json_encode($payload));
    }

    public function testWantsProblemDetailsAcceptHeader(): void
    {
        $this->assertTrue(Response::wantsProblemDetails($this->makeRequest('application/problem+json')));
        $this->assertTrue(Response::wantsProblemDetails(
            $this->makeRequest('application/json, application/problem+json;q=0.9')
        ));
        $this->assertTrue(Response::wantsProblemDetails(
            $this->makeRequest('Application/Problem+JSON; charset=utf-8')
        ));
        $this->assertFalse(Response::wantsProblemDetails($this->makeRequest('application/json')));
        $this->assertFalse(Response::wantsProblemDetails($this->makeRequest('')));
        // A versioned vnd type alone is not a problem request; the version
        // is what VersionMiddleware resolves from it.
        $this->assertFalse(Response::wantsProblemDetails(
            $this->makeRequest('application/vnd.siro.v2+json')
        ));
    }

    public function testWantsProblemDetailsVersionTwo(): void
    {
        $this->assertTrue(Response::wantsProblemDetails($this->makeRequest('', 2)));
        $this->assertFalse(Response::wantsProblemDetails($this->makeRequest('', 1)));
    }

    public function testWantsProblemDetailsConfigDefault(): void
    {
        Config::set('api.problem_details_default', true);
        $this->assertTrue(Response::wantsProblemDetails($this->makeRequest()));
        Config::set('api.problem_details_default', false);
        $this->assertFalse(Response::wantsProblemDetails($this->makeRequest()));
    }

    public function testWantsProblemDetailsEnvDefault(): void
    {
        putenv('SIRO_PROBLEM_DETAILS_DEFAULT=1');
        $_ENV['SIRO_PROBLEM_DETAILS_DEFAULT'] = '1';
        $this->assertTrue(Response::wantsProblemDetails($this->makeRequest()));
    }
}

<?php

declare(strict_types=1);

namespace Siro\Core\Tests\Unit;

use Siro\Core\Attributes\Body;
use Siro\Core\Attributes\QueryParam;
use Siro\Core\Commands\MakeOpenApiCommand;
use Siro\Core\Request;
use Siro\Core\Response;
use Siro\Core\Tests\TestCase;

/**
 * OpenAPI generator reads #[Body] / #[QueryParam] attributes.
 */
final class OpenApiAttributesTest extends TestCase
{
    private MakeOpenApiCommand $command;

    protected function setUp(): void
    {
        parent::setUp();
        $this->command = new MakeOpenApiCommand(sys_get_temp_dir());
    }

    /** @return array{body: array<string, mixed>, query: array<string, mixed>} */
    private function extractFields(string $handler): array
    {
        $m = new \ReflectionMethod($this->command, 'extractAttributeFields');
        $m->setAccessible(true);
        /** @var array{body: array<string, mixed>, query: array<string, mixed>} $result */
        $result = $m->invoke($this->command, $handler);
        return $result;
    }

    public function testBodyAttributesExtracted(): void
    {
        $fields = $this->extractFields(OpenApiWidgetController::class . '@store');

        $this->assertArrayHasKey('email', $fields['body']);
        $this->assertSame(['required', 'email'], $fields['body']['email']['rules']);
        $this->assertSame('string', $fields['body']['email']['type']);
        $this->assertArrayHasKey('age', $fields['body']);
        $this->assertSame('integer', $fields['body']['age']['type']);
        $this->assertFalse($fields['body']['age']['required']);
    }

    public function testQueryAttributesExtracted(): void
    {
        $fields = $this->extractFields(OpenApiWidgetController::class . '@index');

        $this->assertArrayHasKey('search', $fields['query']);
        $this->assertSame('Filter text', $fields['query']['search']['description']);
    }

    public function testUnknownHandlerReturnsEmpty(): void
    {
        $fields = $this->extractFields('NopeController@missing');

        $this->assertSame([], $fields['body']);
        $this->assertSame([], $fields['query']);
    }

    public function testRequestSchemaUsesAttributes(): void
    {
        $m = new \ReflectionMethod($this->command, 'buildRequestSchema');
        $m->setAccessible(true);
        $schemaName = $m->invoke($this->command, OpenApiWidgetController::class . '@store', 'post');

        $this->assertIsString($schemaName);
        $ref = new \ReflectionProperty($this->command, 'schemas');
        $ref->setAccessible(true);
        /** @var array<string, mixed> $schemas */
        $schemas = $ref->getValue($this->command);
        $schema = $schemas[$schemaName];

        $this->assertArrayHasKey('email', $schema['properties']);
        $this->assertContains('email', $schema['required']);
        $this->assertSame('integer', $schema['properties']['age']['type']);
        $this->assertNotContains('age', $schema['required'] ?? []);
    }
}

final class OpenApiWidgetController
{
    #[QueryParam('search', required: false, description: 'Filter text')]
    public function index(Request $request): Response
    {
        return Response::success([]);
    }

    #[Body('email', rules: 'required|email', description: 'Login email')]
    #[Body('age', type: 'integer', required: false)]
    public function store(Request $request): Response
    {
        return Response::created(['id' => 1]);
    }
}

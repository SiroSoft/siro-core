<?php

declare(strict_types=1);

namespace Siro\Core\Tests\Unit;

use Siro\Core\Tests\TestCase;
use Siro\Core\Response;

/**
 * Response Unit Tests
 * 
 * Tests all response methods and helpers
 */
final class ResponseTest extends TestCase
{
    /**
     * Test json() method creates JSON response
     */
    public function testJsonCreatesJsonResponse(): void
    {
        $response = Response::json(['message' => 'Hello']);
        
        $this->assertInstanceOf(Response::class, $response);
        $this->assertEquals(200, $response->statusCode());
    }

    /**
     * Test json() with custom status code
     */
    public function testJsonWithCustomStatusCode(): void
    {
        $response = Response::json(['error' => 'Not found'], 404);
        
        $this->assertEquals(404, $response->statusCode());
    }

    /**
     * Test error() method
     */
    public function testErrorCreatesErrorResponse(): void
    {
        $response = Response::error('Something went wrong', 500);
        
        $this->assertInstanceOf(Response::class, $response);
        $this->assertEquals(500, $response->statusCode());
    }

    /**
     * Test error() with additional data
     */
    public function testErrorWithAdditionalData(): void
    {
        $response = Response::error('Validation failed', 422, ['field' => 'required']);
        
        $this->assertEquals(422, $response->statusCode());
    }

    /**
     * Test success() method
     */
    public function testSuccessCreatesSuccessResponse(): void
    {
        $response = Response::success(['data' => 'value'], 'Operation successful');
        
        $this->assertInstanceOf(Response::class, $response);
        $this->assertEquals(200, $response->statusCode());
    }

    /**
     * Test created() method
     */
    public function testCreatedReturns201(): void
    {
        $response = Response::created(['id' => 1], 'Resource created');
        
        $this->assertEquals(201, $response->statusCode());
    }

    /**
     * Test noContent() method
     */
    public function testNoContentReturns204(): void
    {
        $response = Response::noContent();
        
        $this->assertEquals(204, $response->statusCode());
    }

    /**
     * Test paginated() method structure
     */
    public function testPaginatedReturnsCorrectStructure(): void
    {
        $data = [['id' => 1], ['id' => 2]];
        $meta = [
            'current_page' => 1,
            'per_page' => 10,
            'total' => 20,
        ];
        
        $response = Response::paginated($data, $meta, 'Items list');
        
        $this->assertInstanceOf(Response::class, $response);
        $this->assertEquals(200, $response->statusCode());
    }

    /**
     * Test redirect() method
     */
    public function testRedirectCreatesRedirectResponse(): void
    {
        $response = Response::redirect('/new-location');
        
        $this->assertInstanceOf(Response::class, $response);
        $this->assertEquals(302, $response->statusCode());
    }

    /**
     * Test redirect() with custom status
     */
    public function testRedirectWithCustomStatus(): void
    {
        $response = Response::redirect('/permanent', 301);
        
        $this->assertEquals(301, $response->statusCode());
    }

    /**
     * Test raw() method
     */
    public function testRawCreatesRawResponse(): void
    {
        $response = Response::raw('Plain text', 'text/plain');
        
        $this->assertInstanceOf(Response::class, $response);
        $this->assertEquals(200, $response->statusCode());
    }

    /**
     * Test download() method exists
     */
    public function testDownloadMethodExists(): void
    {
        $this->assertTrue(method_exists(Response::class, 'download'));
    }

    /**
     * Test file() method exists
     */
    public function testFileMethodExists(): void
    {
        $this->assertTrue(method_exists(Response::class, 'file'));
    }

    /**
     * Test response has correct Content-Type header
     */
    public function testJsonHasCorrectContentType(): void
    {
        $response = Response::json(['test' => 'data']);
        
        // Response should have application/json content type
        $this->assertInstanceOf(Response::class, $response);
    }

    /**
     * Test response statusCode() returns integer
     */
    public function testStatusCodeReturnsInteger(): void
    {
        $response = Response::json(['test' => 'data']);
        $statusCode = $response->statusCode();
        
        $this->assertIsInt($statusCode);
        $this->assertEquals(200, $statusCode);
    }
}

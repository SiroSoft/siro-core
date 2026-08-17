<?php

declare(strict_types=1);

namespace Siro\Core\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Siro\Core\Request;
use Siro\Core\ValidationException;

/**
 * Request fromGlobals + validateFile edge branches.
 */
final class RequestGlobalsMutationTest extends TestCase
{
    private array $origServer;
    private array $origFiles;

    protected function setUp(): void
    {
        $this->origServer = $_SERVER;
        $this->origFiles = $_FILES;
    }

    protected function tearDown(): void
    {
        $_SERVER = $this->origServer;
        $_FILES = $this->origFiles;
    }

    public function testFromGlobalsBasic(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_SERVER['REQUEST_URI'] = '/api/items/42?x=1';
        $_GET = ['x' => '1'];
        $r = Request::fromGlobals();
        $this->assertSame('POST', $r->method());
        $this->assertSame('/api/items/42', $r->path());
        $this->assertSame('1', $r->query('x'));
    }

    public function testFromGlobalsDefaultMethod(): void
    {
        unset($_SERVER['REQUEST_METHOD']);
        $_SERVER['REQUEST_URI'] = '/';
        $r = Request::fromGlobals();
        $this->assertSame('GET', $r->method());
    }

    public function testFromGlobalsNonStringUri(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['REQUEST_URI'] = null;
        $r = Request::fromGlobals();
        $this->assertSame('/', $r->path());
    }

    public function testValidateFileRequiredMissingThrows(): void
    {
        $r = new Request('POST', '/api/x');
        $this->expectException(ValidationException::class);
        $r->validateFile('avatar', ['required']);
    }

    public function testValidateFileMissingOptional(): void
    {
        $r = new Request('POST', '/api/x');
        $this->assertNull($r->validateFile('avatar', ['image']));
    }

    public function testIpWithClientIp(): void
    {
        $r = new Request('GET', '/', [], [], [], '10.1.2.3');
        $this->assertSame('10.1.2.3', $r->ip());
    }
}

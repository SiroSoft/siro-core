<?php

declare(strict_types=1);

namespace Siro\Core\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Siro\Core\Cache;
use Siro\Core\Config;
use Siro\Core\Container;
use Siro\Core\Env;
use Siro\Core\Request;
use Siro\Core\Response;

/**
 * Tiny coverage for final small branches.
 */
final class FinalPushMutationTest extends TestCase
{
    private string $basePath;

    protected function setUp(): void
    {
        parent::setUp();
        if (!defined('BASE_PATH')) {
            define('BASE_PATH', dirname(__DIR__, 2));
        }
        Env::reset();
        putenv('APP_ENV=testing');
        putenv('APP_KEY=this_is_a_sufficiently_long_app_key_12345678');
        $this->basePath = sys_get_temp_dir() . '/siro_fin_' . uniqid();
        mkdir($this->basePath . '/config', 0777, true);
        file_put_contents($this->basePath . '/config/app.php', "<?php\nreturn ['name' => 'Test', 'debug' => true];\n");
    }

    protected function tearDown(): void
    {
        putenv('APP_ENV');
        putenv('APP_KEY');
        Env::reset();
        Cache::reset();
        if (is_dir($this->basePath)) {
            system('rm -rf ' . escapeshellarg($this->basePath));
        }
        parent::tearDown();
    }

    public function testConfigSetThenGet(): void
    {
        Config::load($this->basePath . '/config');
        Config::set('app.debug', false);
        $this->assertFalse(Config::get('app.debug'));
    }

    public function testRequestHeaderMissing(): void
    {
        $req = new Request('GET', '/x');
        $this->assertSame('fallback', $req->header('X-Nothing', 'fallback'));
    }

    public function testResponseSuccessBody(): void
    {
        $resp = Response::success(['ok' => true]);
        $payload = $resp->payload();
        $this->assertIsArray($payload);
    }

    public function testConfigGetEmptyKey(): void
    {
        Config::load($this->basePath . '/config');
        $this->assertNull(Config::get(''));
    }

    public function testResponseHeaderRoundtrip(): void
    {
        $resp = Response::success()->header('X-Custom', 'yes');
        $this->assertSame('yes', $resp->getHeader('X-Custom'));
        $this->assertSame(200, $resp->getStatusCode());
        $this->assertTrue($resp->isFileResponse() === false);
    }

    public function testResponseWithHeaders(): void
    {
        $resp = Response::success()->withHeaders(['X-A' => '1', 'X-B' => '2']);
        $headers = $resp->getHeaders();
        $this->assertIsArray($headers);
    }

    public function testContainerMakeUnresolvable(): void
    {
        $c = new Container();
        $this->expectException(\ReflectionException::class);
        $c->make('NoSuchClass_' . uniqid());
    }

    public function testContainerCallInvalidString(): void
    {
        $c = new Container();
        $this->expectException(\RuntimeException::class);
        $c->call('InvalidCallableName' . uniqid());
    }

    public function testContainerCallInvalidArray(): void
    {
        $c = new Container();
        $this->expectException(\RuntimeException::class);
        $c->call([]);
    }

    public function testContainerHas(): void
    {
        $c = new Container();
        $this->assertFalse($c->has('Nothing_' . uniqid()));
    }
}

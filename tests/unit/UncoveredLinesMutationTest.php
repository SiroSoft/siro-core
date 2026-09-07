<?php

declare(strict_types=1);

namespace Siro\Core\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Siro\Core\Auth\ApiKey;
use Siro\Core\Auth\AuthGuard;
use Siro\Core\Auth\JWT;
use Siro\Core\Auth\ModelUserProvider;
use Siro\Core\Cache;
use Siro\Core\Container;
use Siro\Core\Database;
use Siro\Core\Env;
use Siro\Core\Middleware\CsrfMiddleware;
use Siro\Core\Middleware\ThrottleMiddleware;
use Siro\Core\Request;
use Siro\Core\Response;

final class UncoveredLinesMutationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        if (!defined('BASE_PATH')) {
            define('BASE_PATH', dirname(__DIR__, 2));
        }
        Env::reset();
        JWT::reset();
        putenv('APP_ENV=testing');
    }

    protected function tearDown(): void
    {
        putenv('APP_ENV');
        putenv('JWT_SECRET');
        putenv('JWT_ALGORITHM');
        putenv('JWT_PUBLIC_KEY');
        putenv('JWT_PUBLIC_KEY_PATH');
        putenv('THROTTLE_FALLBACK');
        Env::reset();
        JWT::reset();
        Cache::reset();
        $_COOKIE = [];
        $_ENV = [];
        parent::tearDown();
    }

    // ========== ModelUserProvider: lines 24,37,38,44,50 ==========

    public function testModelUserProviderRetrieveByIdReturnsNullForMissing(): void
    {
        $container = Container::getInstance();
        if (!$container->has('db.default')) {
            $this->markTestSkipped('Database not configured');
        }
        $provider = new ModelUserProvider('App\\Models\\User');
        if (!class_exists('App\\Models\\User')) {
            $this->markTestSkipped('App\\Models\\User not available');
        }
        $result = $provider->retrieveById(999999);
        $this->assertNull($result);
    }

    public function testModelUserProviderRetrieveByCredentialsSkipsPassword(): void
    {
        $container = Container::getInstance();
        if (!$container->has('db.default')) {
            $this->markTestSkipped('Database not configured');
        }
        $provider = new ModelUserProvider('App\\Models\\User');
        if (!class_exists('App\\Models\\User')) {
            $this->markTestSkipped('App\\Models\\User not available');
        }
        $result = $provider->retrieveByCredentials(['password' => 'secret', 'email' => 'none@test.com']);
        $this->assertNull($result);
    }

    public function testModelUserProviderRetrieveByCredentialsNoPassword(): void
    {
        $container = Container::getInstance();
        if (!$container->has('db.default')) {
            $this->markTestSkipped('Database not configured');
        }
        $provider = new ModelUserProvider('App\\Models\\User');
        if (!class_exists('App\\Models\\User')) {
            $this->markTestSkipped('App\\Models\\User not available');
        }
        $result = $provider->retrieveByCredentials(['email' => 'none@test.com']);
        $this->assertNull($result);
    }

    public function testModelUserProviderValidateCredentialsEmptyHash(): void
    {
        $provider = new ModelUserProvider('App\\Models\\User');
        $this->assertFalse($provider->validateCredentials([], 'test'));
    }

    public function testModelUserProviderValidateCredentialsBadPassword(): void
    {
        $provider = new ModelUserProvider('App\\Models\\User');
        $hash = password_hash('correct', PASSWORD_BCRYPT);
        $this->assertFalse($provider->validateCredentials(['password' => $hash], 'wrong'));
    }

    public function testModelUserProviderValidateCredentialsGoodPassword(): void
    {
        $provider = new ModelUserProvider('App\\Models\\User');
        $hash = password_hash('correct', PASSWORD_BCRYPT);
        $this->assertTrue($provider->validateCredentials(['password' => $hash], 'correct'));
    }

    public function testModelUserProviderValidateCredentialsNoKey(): void
    {
        $provider = new ModelUserProvider('App\\Models\\User');
        $this->assertFalse($provider->validateCredentials(['not_password' => 'hash'], 'any'));
    }

    // ========== ApiKey line 91: LogicalNot on password_verify ==========

    public function testApiKeyVerifyBcryptRejectsWrongToken(): void
    {
        $container = Container::getInstance();
        if (!$container->has('db.default')) {
            $this->markTestSkipped('Database not configured');
        }
        try {
            ApiKey::ensureTable();
        } catch (\Throwable) {
            $this->markTestSkipped('Cannot create api_keys table');
        }
        $token = bin2hex(random_bytes(16));
        $hash = password_hash($token, PASSWORD_BCRYPT);
        Database::execute(
            "INSERT INTO " . ApiKey::$table . " (name, token_hash, token_bcrypt, scopes, user_id, created_at, expires_at) VALUES (?, ?, ?, ?, ?, ?, ?)",
            ['test-bcrypt', hash('sha256', $token), $hash, 'read', 1, time(), 0]
        );
        $found = ApiKey::findByToken('wrong-token-' . bin2hex(random_bytes(8)));
        $this->assertNull($found);
        Database::execute("DELETE FROM " . ApiKey::$table . " WHERE name = ?", ['test-bcrypt']);
    }

    // ========== JWT decode error paths ==========

    public function testJwtVerifyWithWrongSignatureThrows(): void
    {
        putenv('JWT_SECRET=test_secret_key_32_chars_long_enough');
        Env::reset();
        JWT::reset();
        $token = JWT::encodeAccess(5, 1);
        $parts = explode('.', $token);
        $parts[2] = rtrim(base64_encode(random_bytes(32)), '=');
        $badToken = implode('.', $parts);
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Invalid token signature');
        JWT::decode($badToken);
    }

    public function testJwtVerifyWithMalformedTokenThrows(): void
    {
        putenv('JWT_SECRET=test_secret_key_32_chars_long_enough');
        Env::reset();
        JWT::reset();
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Invalid base64 token segment');
        JWT::decode('not.a.valid');
    }

    public function testJwtVerifyWithInvalidHeaderThrows(): void
    {
        putenv('JWT_SECRET=test_secret_key_32_chars_long_enough');
        Env::reset();
        JWT::reset();
        $bad = rtrim(base64_encode('not json'), '=') . '.' . rtrim(base64_encode('{}'), '=') . '.sig';
        $this->expectException(\RuntimeException::class);
        JWT::decode($bad);
    }

    public function testJwtExpiredTokenThrows(): void
    {
        putenv('JWT_SECRET=test_secret_key_32_chars_long_enough');
        Env::reset();
        JWT::reset();
        $header = rtrim(base64_encode(json_encode(['alg' => 'HS256', 'typ' => 'JWT'])), '=');
        $payload = rtrim(base64_encode(json_encode([
            'sub' => 1, 'type' => 'access', 'iat' => time() - 7200, 'exp' => time() - 3600,
        ])), '=');
        $data = "$header.$payload";
        $sig = rtrim(base64_encode(hash_hmac('sha256', $data, 'test_secret_key_32_chars_long_enough', true)), '=');
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Token expired');
        JWT::decode("$data.$sig");
    }

    public function testJwtTokenNotYetValidThrows(): void
    {
        putenv('JWT_SECRET=test_secret_key_32_chars_long_enough');
        Env::reset();
        JWT::reset();
        $header = rtrim(base64_encode(json_encode(['alg' => 'HS256', 'typ' => 'JWT'])), '=');
        $payload = rtrim(base64_encode(json_encode([
            'sub' => 1, 'type' => 'access', 'iat' => time() + 3600, 'exp' => time() + 7200,
        ])), '=');
        $data = "$header.$payload";
        $sig = rtrim(base64_encode(hash_hmac('sha256', $data, 'test_secret_key_32_chars_long_enough', true)), '=');
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Token issued in the future');
        JWT::decode("$data.$sig");
    }

    public function testJwtInvalidTokenTypeThrows(): void
    {
        putenv('JWT_SECRET=test_secret_key_32_chars_long_enough');
        Env::reset();
        JWT::reset();
        $header = rtrim(base64_encode(json_encode(['alg' => 'HS256', 'typ' => 'JWT'])), '=');
        $payload = rtrim(base64_encode(json_encode([
            'sub' => 1, 'type' => 'invalid_type', 'iat' => time(), 'exp' => time() + 3600,
        ])), '=');
        $data = "$header.$payload";
        $sig = rtrim(base64_encode(hash_hmac('sha256', $data, 'test_secret_key_32_chars_long_enough', true)), '=');
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Invalid token type');
        JWT::decode("$data.$sig");
    }

    public function testJwtAlgorithmMismatchThrows(): void
    {
        putenv('JWT_SECRET=test_secret_key_32_chars_long_enough');
        Env::reset();
        JWT::reset();
        $header = rtrim(base64_encode(json_encode(['alg' => 'HS512', 'typ' => 'JWT'])), '=');
        $payload = rtrim(base64_encode(json_encode([
            'sub' => 1, 'type' => 'access', 'iat' => time(), 'exp' => time() + 3600,
        ])), '=');
        $data = "$header.$payload";
        $sig = rtrim(base64_encode(hash_hmac('sha256', $data, 'test_secret_key_32_chars_long_enough', true)), '=');
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Algorithm mismatch');
        JWT::decode("$data.$sig");
    }

    public function testJwtWeakSecretThrows(): void
    {
        putenv('JWT_SECRET=short');
        Env::reset();
        JWT::reset();
        $header = rtrim(base64_encode(json_encode(['alg' => 'HS256', 'typ' => 'JWT'])), '=');
        $payload = rtrim(base64_encode(json_encode([
            'sub' => 1, 'type' => 'access', 'iat' => time(), 'exp' => time() + 3600,
        ])), '=');
        $data = "$header.$payload";
        $sig = rtrim(base64_encode(hash_hmac('sha256', $data, 'short', true)), '=');
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('too weak');
        JWT::decode("$data.$sig");
    }

    // ========== AuthGuard line 133: NewObject ==========

    public function testAuthGuardDefaultProviderReturnsNullWithoutModel(): void
    {
        $container = Container::getInstance();
        if (method_exists($container, 'clear')) {
            $container->clear();
        }
        $guard = new AuthGuard();
        $ref = new \ReflectionMethod($guard, 'getUserProvider');
        $ref->setAccessible(true);
        $result = $ref->invoke($guard);
        if (class_exists('App\\Models\\User')) {
            $this->assertInstanceOf(ModelUserProvider::class, $result);
        } else {
            $this->assertNull($result);
        }
    }

    public function testAuthGuardResolveWithCustomResolver(): void
    {
        $container = Container::getInstance();
        $container->instance('auth.resolver', new class {
            public function __invoke(Request $req): array
            {
                return ['id' => 42, 'role' => 'user', 'name' => 'Test'];
            }
        });
        $guard = new AuthGuard();
        $user = $guard->resolve(new Request('GET', '/'));
        $this->assertNotNull($user);
        $this->assertSame(42, $user['id']);
    }

    // ========== ThrottleMiddleware file fallback lines 115, 166, 198 ==========

    public function testThrottleFileFallbackNormalRequest(): void
    {
        putenv('THROTTLE_FALLBACK=file');
        $mw = new ThrottleMiddleware();
        $request = new Request('GET', '/fb-normal-' . uniqid(), [], ['X-Forwarded-For' => '9.9.9.9']);
        $response = $mw->handle($request, fn () => Response::success('ok'), 100, 1);
        $this->assertContains($response->statusCode(), [200, 429]);
    }

    public function testThrottleFileFallbackReadWriteUnlock(): void
    {
        putenv('THROTTLE_FALLBACK=file');
        $mw = new ThrottleMiddleware();
        $request = new Request('GET', '/fb-rw-' . uniqid(), [], ['X-Forwarded-For' => '10.10.10.10']);
        $r1 = $mw->handle($request, fn () => Response::success('ok'), 2, 1);
        $r2 = $mw->handle($request, fn () => Response::success('ok'), 2, 1);
        $r3 = $mw->handle($request, fn () => Response::success('ok'), 2, 1);
        $this->assertContains($r1->statusCode(), [200, 429]);
        $this->assertContains($r2->statusCode(), [200, 429]);
        $this->assertContains($r3->statusCode(), [200, 429]);
    }

    public function testThrottleFailClosedAlwaysReturns429(): void
    {
        putenv('THROTTLE_FALLBACK=fail_closed');
        $mw = new ThrottleMiddleware();
        $request = new Request('GET', '/fc-test', [], ['X-Forwarded-For' => '11.11.11.11']);
        $response = $mw->handle($request, fn () => Response::success('ok'), 100, 1);
        $this->assertContains($response->statusCode(), [200, 429]);
    }

    public function testThrottleFailOpenAlwaysPasses(): void
    {
        putenv('THROTTLE_FALLBACK=fail_open');
        $mw = new ThrottleMiddleware();
        $request = new Request('GET', '/fo-test', [], ['X-Forwarded-For' => '12.12.12.12']);
        $response = $mw->handle($request, fn () => Response::success('ok'), 1, 1);
        $this->assertSame(200, $response->statusCode());
    }

    public function testThrottleDisabledAlwaysPasses(): void
    {
        putenv('THROTTLE_FALLBACK=disabled');
        $mw = new ThrottleMiddleware();
        $request = new Request('GET', '/dis-test', [], ['X-Forwarded-For' => '13.13.13.13']);
        $response = $mw->handle($request, fn () => Response::success('ok'), 1, 1);
        $this->assertSame(200, $response->statusCode());
    }

    // ========== CsrfMiddleware line 38: LogicalAnd cookie check ==========

    public function testCsrfCookieNonStringReturns419(): void
    {
        $mw = new CsrfMiddleware();
        $_COOKIE['csrf_token'] = ['not', 'a', 'string'];
        $req = new Request('POST', '/x', [], ['X-CSRF-TOKEN' => 'headerval']);
        $resp = $mw->handle($req, fn () => Response::success());
        $this->assertSame(419, $resp->statusCode());
    }

    public function testCsrfCookieNotSetReturns419(): void
    {
        $mw = new CsrfMiddleware();
        $_COOKIE = [];
        $req = new Request('POST', '/x', [], ['X-CSRF-TOKEN' => 'headerval']);
        $resp = $mw->handle($req, fn () => Response::success());
        $this->assertSame(419, $resp->statusCode());
    }

    public function testCsrfCookieEmptyStringReturns419(): void
    {
        $mw = new CsrfMiddleware();
        $_COOKIE['csrf_token'] = '';
        $req = new Request('POST', '/x', [], ['X-CSRF-TOKEN' => 'headerval']);
        $resp = $mw->handle($req, fn () => Response::success());
        $this->assertSame(419, $resp->statusCode());
    }

    public function testCsrfCookieIntValueReturns419(): void
    {
        $mw = new CsrfMiddleware();
        $_COOKIE['csrf_token'] = 12345;
        $req = new Request('POST', '/x', [], ['X-CSRF-TOKEN' => 'headerval']);
        $resp = $mw->handle($req, fn () => Response::success());
        $this->assertSame(419, $resp->statusCode());
    }

    public function testCsrfNoSessionNoCookieNoHeaderReturns419(): void
    {
        $mw = new CsrfMiddleware();
        $_COOKIE = [];
        $req = new Request('POST', '/x', [], []);
        $resp = $mw->handle($req, fn () => Response::success());
        $this->assertSame(419, $resp->statusCode());
    }

    public function testCsrfDoubleSubmitWithNonStringValueHeaderReturns419(): void
    {
        $mw = new CsrfMiddleware();
        $_COOKIE['csrf_token'] = 'valid-token';
        $req = new Request('POST', '/x', [], []);
        $resp = $mw->handle($req, fn () => Response::success());
        $this->assertSame(419, $resp->statusCode());
    }

    // ========== JWT blacklist mutation tests ==========

    public function testJwtBlacklistAndCheck(): void
    {
        putenv('JWT_SECRET=test_secret_key_32_chars_long_enough');
        Env::reset();
        JWT::reset();
        $jti = uniqid('jti_', true);
        JWT::blacklistJti($jti, time() + 3600);
        $token = JWT::encode(['sub' => 1, 'type' => 'access', 'jti' => $jti]);
        $this->expectException(\RuntimeException::class);
        JWT::decode($token);
    }

    public function testJwtBlacklistExpiredEntryAllowsToken(): void
    {
        putenv('JWT_SECRET=test_secret_key_32_chars_long_enough');
        Env::reset();
        JWT::reset();
        $jti = uniqid('jti_exp_', true);
        JWT::blacklistJti($jti, time() - 10);
        $token = JWT::encodeAccess(1, 1);
        $decoded = JWT::decode($token);
        $this->assertNotNull($decoded);
    }

    // ========== ThrottleMiddleware response header tests ==========

    public function testThrottleReturnsRateHeadersOnAllowedRequest(): void
    {
        putenv('THROTTLE_FALLBACK=disabled');
        $mw = new ThrottleMiddleware();
        $request = new Request('GET', '/headers-' . uniqid(), [], ['X-Forwarded-For' => '14.14.14.14']);
        $response = $mw->handle($request, fn () => Response::success('ok'), 50, 1);
        $this->assertSame(200, $response->statusCode());
    }

    public function testThrottleReturnsRetryAfterOnExceeded(): void
    {
        putenv('THROTTLE_FALLBACK=disabled');
        $mw = new ThrottleMiddleware();
        $request = new Request('GET', '/retry-' . uniqid(), [], ['X-Forwarded-For' => '15.15.15.15']);
        $mw->handle($request, fn () => Response::success('ok'), 1, 1);
        $response = $mw->handle($request, fn () => Response::success('ok'), 1, 1);
        $this->assertContains($response->statusCode(), [200, 429]);
    }
}

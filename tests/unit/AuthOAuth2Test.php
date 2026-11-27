<?php

declare(strict_types=1);

namespace Siro\Core\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Siro\Core\Auth\JWT;
use Siro\Core\Auth\OAuth2\AuthorizationServer;
use Siro\Core\Auth\OAuth2\OAuthClient;
use Siro\Core\Auth\OAuth2\ScopeRepository;
use Siro\Core\Commands\MakeOAuthClientCommand;
use Siro\Core\Commands\MakeOAuthKeysCommand;
use Siro\Core\Middleware\OAuthScopeMiddleware;
use Siro\Core\Request;
use Siro\Core\Response;
use Siro\Core\Tests\OAuth2TestTrait;

/**
 * Unit tests for the G2 OAuth2 bridge (league/oauth2-server, direction B).
 *
 * Grants are exercised in-process through `AuthorizationServer`; the real-HTTP
 * end-to-end proof lives in `tests/integration/OAuth2E2eTest.php`.
 */
final class AuthOAuth2Test extends TestCase
{
    use OAuth2TestTrait;

    public static function setUpBeforeClass(): void
    {
        self::setUpOAuth2Class();
    }

    public static function tearDownAfterClass(): void
    {
        self::tearDownOAuth2Class();
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpOAuth2();
    }

    // ---- Client registry ----

    public function testCreateClientReturnsSecretOnce(): void
    {
        $this->assertNotSame('', $this->oauthClient['client_secret']);
        $this->assertTrue(OAuthClient::validateSecret(
            $this->oauthClient['client_id'],
            $this->oauthClient['client_secret'],
        ));
        $this->assertSame('Test Partner', $this->oauthClient['name']);
    }

    public function testCreatePublicClientHasNoSecret(): void
    {
        $this->assertSame('', $this->oauthPublicClient['client_secret']);
        $this->assertFalse($this->oauthPublicClient['is_confidential']);
    }

    public function testCreateClientRequiresName(): void
    {
        $this->expectException(\RuntimeException::class);
        OAuthClient::create(['name' => '']);
    }

    public function testCreateClientRequiresRedirectUriForAuthCodeGrant(): void
    {
        $this->expectException(\RuntimeException::class);
        OAuthClient::create([
            'name' => 'No redirect',
            'grants' => ['authorization_code'],
            'redirect_uris' => [],
        ]);
    }

    public function testValidateSecretWrongSecretFails(): void
    {
        $this->assertFalse(OAuthClient::validateSecret(
            $this->oauthClient['client_id'],
            'wrong-secret',
        ));
        $this->assertFalse(OAuthClient::validateSecret('oauth_nope', 'whatever'));
    }

    public function testRevokeClientBlocksValidation(): void
    {
        // Revoke a throwaway client: the shared class clients must stay alive
        // for the other tests.
        $client = OAuthClient::create([
            'name' => 'Doomed',
            'redirect_uris' => ['http://127.0.0.1:18999/callback'],
            'grants' => ['client_credentials'],
            'scopes' => ['read'],
        ]);
        $this->assertTrue(OAuthClient::validateSecret($client['client_id'], $client['client_secret']));
        $this->assertTrue(OAuthClient::revoke($client['client_id']));
        $this->assertFalse(OAuthClient::validateSecret($client['client_id'], $client['client_secret']));
    }

    public function testListClientsHidesSecret(): void
    {
        $list = OAuthClient::list();
        $this->assertGreaterThanOrEqual(2, count($list));
        $ids = array_column($list, 'client_id');
        $this->assertContains($this->oauthClient['client_id'], $ids);
        $this->assertContains($this->oauthPublicClient['client_id'], $ids);
        foreach ($list as $row) {
            $this->assertArrayNotHasKey('secret_hash', $row);
            $this->assertArrayHasKey('client_id', $row);
        }
    }

    // ---- Scopes ----

    public function testUnknownScopeReturnsNull(): void
    {
        $repo = new ScopeRepository();
        $this->assertNull($repo->getScopeEntityByIdentifier('admin'));
        $this->assertNotNull($repo->getScopeEntityByIdentifier('read'));
    }

    public function testJwtScopeHelpers(): void
    {
        $token = JWT::encodeAccess(1, 1, 3600, null, 'read write');
        $claims = JWT::decode($token);
        $this->assertSame(['read', 'write'], JWT::scopes($claims));
        $this->assertTrue(JWT::hasScope($claims, 'write'));
        $this->assertFalse(JWT::hasScope($claims, 'admin'));

        // League-style `scopes` array claim is understood too.
        $this->assertSame(['read'], JWT::scopes(['scopes' => ['read']]));
        $this->assertSame([], JWT::scopes([]));
    }

    // ---- client_credentials grant (in-process) ----

    public function testClientCredentialsGrantIssuesToken(): void
    {
        $response = $this->oauthTokenRequest([
            'grant_type' => 'client_credentials',
            'client_id' => $this->oauthClient['client_id'],
            'client_secret' => $this->oauthClient['client_secret'],
            'scope' => 'read',
        ]);
        $this->assertSame(200, $response->statusCode());
        $payload = $this->oauthTokenPayload($response);
        $this->assertSame('Bearer', $payload['token_type'] ?? null);
        $this->assertSame(3600, $payload['expires_in'] ?? null);
        $this->assertArrayNotHasKey('refresh_token', $payload);

        $claims = self::decodeJwtPayload((string) ($payload['access_token'] ?? ''));
        $this->assertSame($this->oauthClient['client_id'], $claims['aud'] ?? null);
        $this->assertSame(['read'], $claims['scopes'] ?? null);
    }

    public function testClientCredentialsGrantWrongSecretFails(): void
    {
        $response = $this->oauthTokenRequest([
            'grant_type' => 'client_credentials',
            'client_id' => $this->oauthClient['client_id'],
            'client_secret' => 'wrong',
        ]);
        // League returns 401 for invalid_client (RFC 6749 §5.2, Basic auth).
        $this->assertSame(401, $response->statusCode());
        $payload = $this->oauthTokenPayload($response);
        $this->assertSame('invalid_client', $payload['error'] ?? null);
    }

    public function testClientCredentialsGrantUnknownScopeFails(): void
    {
        $response = $this->oauthTokenRequest([
            'grant_type' => 'client_credentials',
            'client_id' => $this->oauthClient['client_id'],
            'client_secret' => $this->oauthClient['client_secret'],
            'scope' => 'admin',
        ]);
        $this->assertSame(400, $response->statusCode());
        $payload = $this->oauthTokenPayload($response);
        $this->assertSame('invalid_scope', $payload['error'] ?? null);
    }

    public function testClientCredentialsGrantRequiresConfidentialClient(): void
    {
        $response = $this->oauthTokenRequest([
            'grant_type' => 'client_credentials',
            'client_id' => $this->oauthPublicClient['client_id'],
            'client_secret' => '',
        ]);
        // League returns 401 for invalid_client (RFC 6749 §5.2, Basic auth).
        $this->assertSame(401, $response->statusCode());
        $payload = $this->oauthTokenPayload($response);
        $this->assertSame('invalid_client', $payload['error'] ?? null);
    }

    public function testScopeEscalationBeyondClientAllowlistIsNarrowed(): void
    {
        // Client allowlist is ['read']; asking for write must not grant it.
        // League's token response carries no `scope` field; the granted scopes
        // live in the JWT `scopes` claim.
        $response = $this->oauthTokenRequest([
            'grant_type' => 'client_credentials',
            'client_id' => $this->oauthClient['client_id'],
            'client_secret' => $this->oauthClient['client_secret'],
            'scope' => 'read write',
        ]);
        $this->assertSame(200, $response->statusCode());
        $payload = $this->oauthTokenPayload($response);
        $claims = self::decodeJwtPayload((string) ($payload['access_token'] ?? ''));
        $this->assertSame(['read'], $claims['scopes'] ?? null);
    }

    // ---- authorization_code + refresh_token grants (in-process) ----

    /** @return array{0: string, 1: string} [access_token, refresh_token] */
    private function issueAuthCodeTokens(): array
    {
        [$verifier, $challenge] = self::pkcePair();
        $authRequest = AuthorizationServer::validateAuthorizationRequest(new Request(
            'GET',
            '/oauth/authorize',
            [
                'response_type' => 'code',
                'client_id' => $this->oauthPublicClient['client_id'],
                'redirect_uri' => 'myapp://callback',
                'scope' => 'read',
                'state' => 'xyz',
                'code_challenge' => $challenge,
                'code_challenge_method' => 'S256',
            ],
        ));
        $redirect = AuthorizationServer::completeAuthorizationRequest($authRequest, 1, true);
        $this->assertSame(302, $redirect->statusCode());
        $location = $redirect->headers()['Location'] ?? '';
        $this->assertStringContainsString('myapp://callback', $location);
        parse_str((string) parse_url($location, PHP_URL_QUERY), $query);
        $this->assertSame('xyz', $query['state'] ?? null);
        $code = (string) ($query['code'] ?? '');
        $this->assertNotSame('', $code);

        $response = $this->oauthTokenRequest([
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => 'myapp://callback',
            'client_id' => $this->oauthPublicClient['client_id'],
            'code_verifier' => $verifier,
        ]);
        $this->assertSame(200, $response->statusCode());
        $payload = $this->oauthTokenPayload($response);
        $this->assertNotSame('', (string) ($payload['access_token'] ?? ''));
        $this->assertNotSame('', (string) ($payload['refresh_token'] ?? ''));
        return [(string) $payload['access_token'], (string) $payload['refresh_token']];
    }

    public function testAuthorizationCodeFlowWithPkce(): void
    {
        [$accessToken] = $this->issueAuthCodeTokens();
        $claims = self::decodeJwtPayload($accessToken);
        $this->assertSame('1', (string) ($claims['sub'] ?? ''));
        $this->assertSame(['read'], $claims['scopes'] ?? null);
    }

    public function testRefreshTokenGrantRotates(): void
    {
        [, $refreshToken] = $this->issueAuthCodeTokens();

        $response = $this->oauthTokenRequest([
            'grant_type' => 'refresh_token',
            'refresh_token' => $refreshToken,
            'client_id' => $this->oauthPublicClient['client_id'],
        ]);
        $this->assertSame(200, $response->statusCode());
        $payload = $this->oauthTokenPayload($response);
        $this->assertNotSame('', (string) ($payload['access_token'] ?? ''));

        // Old refresh token is revoked by rotation: reuse must fail.
        $reuse = $this->oauthTokenRequest([
            'grant_type' => 'refresh_token',
            'refresh_token' => $refreshToken,
            'client_id' => $this->oauthPublicClient['client_id'],
        ]);
        $this->assertSame(400, $reuse->statusCode());
        $this->assertSame('invalid_grant', $this->oauthTokenPayload($reuse)['error'] ?? null);
    }

    public function testRefreshTokenScopeEscalationFails(): void
    {
        [, $refreshToken] = $this->issueAuthCodeTokens();
        $response = $this->oauthTokenRequest([
            'grant_type' => 'refresh_token',
            'refresh_token' => $refreshToken,
            'client_id' => $this->oauthPublicClient['client_id'],
            'scope' => 'write',
        ]);
        $this->assertSame(400, $response->statusCode());
        $this->assertSame('invalid_scope', $this->oauthTokenPayload($response)['error'] ?? null);
    }

    // ---- middleware ----

    private function issueClientCredentialsToken(): string
    {
        $response = $this->oauthTokenRequest([
            'grant_type' => 'client_credentials',
            'client_id' => $this->oauthClient['client_id'],
            'client_secret' => $this->oauthClient['client_secret'],
            'scope' => 'read',
        ]);
        $payload = $this->oauthTokenPayload($response);
        return (string) ($payload['access_token'] ?? '');
    }

    public function testMiddlewareRejectsMissingToken(): void
    {
        $middleware = new OAuthScopeMiddleware();
        $response = $middleware->handle(
            new Request('GET', '/api/partner/data'),
            static fn (Request $r): Response => Response::success(['ok' => true]),
            'read',
        );
        $this->assertSame(401, $response->statusCode());
    }

    public function testMiddlewareAllowsRequiredScope(): void
    {
        $token = $this->issueClientCredentialsToken();
        $middleware = new OAuthScopeMiddleware();
        $response = $middleware->handle(
            new Request('GET', '/api/partner/data', [], ['Authorization' => 'Bearer ' . $token]),
            static fn (Request $r): Response => Response::success(['ok' => true]),
            'read',
        );
        $this->assertSame(200, $response->statusCode());
    }

    public function testMiddlewareRejectsMissingScope(): void
    {
        $token = $this->issueClientCredentialsToken();
        $middleware = new OAuthScopeMiddleware();
        $response = $middleware->handle(
            new Request('GET', '/api/partner/write', [], ['Authorization' => 'Bearer ' . $token]),
            static fn (Request $r): Response => Response::success(['ok' => true]),
            'write',
        );
        $this->assertSame(403, $response->statusCode());
        $payload = $response->payload();
        $this->assertSame('insufficient_scope', $payload['meta']['error_code'] ?? null);
        $this->assertStringContainsString(
            'insufficient_scope',
            $response->headers()['WWW-Authenticate'] ?? '',
        );
    }

    public function testMiddlewareRejectsRevokedToken(): void
    {
        $token = $this->issueClientCredentialsToken();
        $revoke = AuthorizationServer::handleRevokeRequest(new Request(
            'POST',
            '/oauth/revoke',
            [],
            [],
            [
                'token' => $token,
                'token_type_hint' => 'access_token',
                'client_id' => $this->oauthClient['client_id'],
                'client_secret' => $this->oauthClient['client_secret'],
            ],
        ));
        $this->assertSame(200, $revoke->statusCode());

        $middleware = new OAuthScopeMiddleware();
        $response = $middleware->handle(
            new Request('GET', '/api/partner/data', [], ['Authorization' => 'Bearer ' . $token]),
            static fn (Request $r): Response => Response::success(['ok' => true]),
            'read',
        );
        $this->assertSame(401, $response->statusCode());
    }

    public function testMiddlewareAcceptsCoreJwtWithScope(): void
    {
        $token = JWT::encodeAccess(1, 1, 3600, null, 'read');
        $middleware = new OAuthScopeMiddleware();
        $response = $middleware->handle(
            new Request('GET', '/api/partner/data', [], ['Authorization' => 'Bearer ' . $token]),
            static fn (Request $r): Response => Response::success(['ok' => true]),
            'read',
        );
        $this->assertSame(200, $response->statusCode());
    }

    public function testMiddlewareRejectsCoreJwtWithoutScope(): void
    {
        $token = JWT::encodeAccess(1, 1, 3600, null, 'read');
        $middleware = new OAuthScopeMiddleware();
        $response = $middleware->handle(
            new Request('GET', '/api/partner/write', [], ['Authorization' => 'Bearer ' . $token]),
            static fn (Request $r): Response => Response::success(['ok' => true]),
            'write',
        );
        $this->assertSame(403, $response->statusCode());
    }

    public function testMiddlewareRejectsRefreshTokenAsAccess(): void
    {
        $token = JWT::encodeRefresh(1, 1, 3600);
        $middleware = new OAuthScopeMiddleware();
        $response = $middleware->handle(
            new Request('GET', '/api/partner/data', [], ['Authorization' => 'Bearer ' . $token]),
            static fn (Request $r): Response => Response::success(['ok' => true]),
            'read',
        );
        $this->assertSame(401, $response->statusCode());
    }

    // ---- revoke endpoint ----

    public function testRevokeUnknownTokenStillReturns200(): void
    {
        $response = AuthorizationServer::handleRevokeRequest(new Request(
            'POST',
            '/oauth/revoke',
            [],
            [],
            [
                'token' => 'does-not-exist',
                'client_id' => $this->oauthClient['client_id'],
                'client_secret' => $this->oauthClient['client_secret'],
            ],
        ));
        $this->assertSame(200, $response->statusCode());
    }

    public function testRevokeRequiresClientAuth(): void
    {
        $token = $this->issueClientCredentialsToken();
        $response = AuthorizationServer::handleRevokeRequest(new Request(
            'POST',
            '/oauth/revoke',
            [],
            [],
            ['token' => $token, 'client_id' => $this->oauthClient['client_id'], 'client_secret' => 'wrong'],
        ));
        $this->assertSame(401, $response->statusCode());

        // Token must still be usable: the failed revoke did nothing.
        $this->assertNotNull(AuthorizationServer::validateRequest(new Request(
            'GET',
            '/api/partner/data',
            [],
            ['Authorization' => 'Bearer ' . $token],
        )));
    }

    // ---- commands ----

    public function testMakeOAuthClientCommand(): void
    {
        $base = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'siro_oauth_cmd_' . uniqid('', true);
        mkdir($base . '/config', 0777, true);
        file_put_contents(
            $base . '/config/database.php',
            "<?php return ['driver' => 'sqlite', 'database' => '" . self::$oauthDbFile . "'];",
        );

        $command = new MakeOAuthClientCommand($base);
        ob_start();
        $code = $command->run(['Cmd Partner', '--redirect-uri=https://example.test/cb', '--scopes=read']);
        $output = (string) ob_get_clean();
        $this->assertSame(0, $code);
        $this->assertStringContainsString('Client ID:', $output);
        $this->assertStringContainsString('Client secret:', $output);
    }

    public function testMakeOAuthKeysCommand(): void
    {
        $base = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'siro_oauth_keys_' . uniqid('', true);
        mkdir($base . '/storage', 0777, true);
        file_put_contents($base . '/.env', "APP_ENV=testing\n");

        $command = new MakeOAuthKeysCommand($base);
        ob_start();
        $code = $command->run([]);
        $output = (string) ob_get_clean();
        $this->assertSame(0, $code);
        $this->assertStringContainsString('OAuth2 keys generated', $output);
        $this->assertFileExists($base . '/storage/oauth-private.key');
        $this->assertFileExists($base . '/storage/oauth-public.key');
        $env = (string) file_get_contents($base . '/.env');
        $this->assertStringContainsString('OAUTH_ENCRYPTION_KEY=', $env);
        $this->assertStringContainsString('OAUTH_PRIVATE_KEY_PATH=', $env);

        // Second run without --force must refuse.
        ob_start();
        $code2 = $command->run([]);
        ob_get_clean();
        $this->assertSame(1, $code2);
    }
}

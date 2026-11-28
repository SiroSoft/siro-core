<?php

declare(strict_types=1);

namespace Siro\Core\Auth\OAuth2;

use DateInterval;
use Defuse\Crypto\Exception\WrongKeyOrModifiedCiphertextException;
use Defuse\Crypto\Key;
use Laminas\Diactoros\Response as DiactorosResponse;
use Laminas\Diactoros\ServerRequest;
use League\OAuth2\Server\AuthorizationServer as LeagueAuthorizationServer;
use League\OAuth2\Server\CryptKey;
use League\OAuth2\Server\Exception\OAuthServerException;
use League\OAuth2\Server\Grant\AuthCodeGrant;
use League\OAuth2\Server\Grant\ClientCredentialsGrant;
use League\OAuth2\Server\Grant\RefreshTokenGrant;
use League\OAuth2\Server\RequestTypes\AuthorizationRequestInterface;
use League\OAuth2\Server\ResourceServer as LeagueResourceServer;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use RuntimeException;
use Siro\Core\Auth\OAuth2\Entities\UserEntity;
use Siro\Core\Config;
use Siro\Core\Env;
use Siro\Core\Request;
use Siro\Core\Response;

/**
 * OAuth2 bridge: builds league/oauth2-server from framework config and adapts
 * it to the framework's own Request/Response world.
 *
 * The league library is OPTIONAL (`composer suggest`): every public entry
 * point here guards with `isAvailable()` / `isEnabled()` so an app without
 * the library (or without keys configured) behaves exactly as before —
 * `AuthorizationServer::isEnabled()` is false and no league class is touched.
 *
 * Key handling:
 * - Private/public keys come from `OAUTH_PRIVATE_KEY[_PATH]` /
 *   `OAUTH_PUBLIC_KEY[_PATH]` (contents win over path, path may be `file://`).
 * - The encryption key comes from `OAUTH_ENCRYPTION_KEY` (defuse ascii-safe
 *   string from `make:oauth-keys`, or any string — league falls back to
 *   password-based encryption for plain strings).
 *
 * @phpstan-type OAuth2Config array{
 *   private_key: string,
 *   public_key: string,
 *   encryption_key: string,
 *   scopes: array<int, string>,
 *   default_scope: string,
 *   access_token_ttl: int,
 *   refresh_token_ttl: int,
 *   auth_code_ttl: int,
 * }
 */
final class AuthorizationServer
{
    /** @var array<string, mixed> */
    private static array $configCache = [];

    private function __construct()
    {
    }

    public static function resetConfigCache(): void
    {
        self::$configCache = [];
    }

    /** Coerce a mixed value to string; non-stringable values become $default. */
    private static function toString(mixed $value, string $default = ''): string
    {
        return is_scalar($value) ? (string) $value : $default;
    }

    /** Coerce a mixed value to int; non-numeric values fall back to $default. */
    private static function toInt(mixed $value, int $default): int
    {
        if (is_int($value)) {
            return $value;
        }
        if (is_numeric($value)) {
            return (int) $value;
        }
        return $default;
    }

    public static function isAvailable(): bool
    {
        return class_exists(LeagueAuthorizationServer::class)
            && class_exists(ServerRequest::class);
    }

    public static function isEnabled(): bool
    {
        if (!self::isAvailable()) {
            return false;
        }
        try {
            $config = self::config();
            return $config['private_key'] !== '' && $config['encryption_key'] !== '';
        } catch (\Throwable) {
            return false;
        }
    }

    /** @return array<int, string> */
    public static function defaultScopes(): array
    {
        $config = self::config();
        /** @var array<int, string> $scopes */
        $scopes = $config['scopes'];
        return $scopes;
    }

    public static function defaultScope(): string
    {
        $config = self::config();
        $default = $config['default_scope'];
        if ($default !== '') {
            return $default;
        }
        $scopes = self::defaultScopes();
        return $scopes[0] ?? 'read';
    }

    /**
     * Resolve OAuth2 config: defaults → config/oauth.php → env → overrides.
     *
     * @param array<string, mixed> $overrides
     * @return OAuth2Config
     */
    public static function config(array $overrides = []): array
    {
        $cacheKey = md5((string) json_encode($overrides));
        if (isset(self::$configCache[$cacheKey])) {
            /** @var OAuth2Config $cached */

            $cached = self::$configCache[$cacheKey];
            return $cached;
        }

        $fileConfig = Config::get('oauth', []);
        if (!is_array($fileConfig)) {
            $fileConfig = [];
        }

        $privateKey = self::toString($overrides['private_key'] ?? Env::get('OAUTH_PRIVATE_KEY', ''));
        if ($privateKey === '') {
            $path = self::toString($overrides['private_key_path'] ?? Env::get('OAUTH_PRIVATE_KEY_PATH', ''));
            $privateKey = $path !== '' ? self::keyPath($path) : '';
        }
        $publicKey = self::toString($overrides['public_key'] ?? Env::get('OAUTH_PUBLIC_KEY', ''));
        if ($publicKey === '') {
            $path = self::toString($overrides['public_key_path'] ?? Env::get('OAUTH_PUBLIC_KEY_PATH', ''));
            $publicKey = $path !== '' ? self::keyPath($path) : '';
        }

        $scopes = $overrides['scopes'] ?? $fileConfig['scopes'] ?? ['read', 'write'];
        if (!is_array($scopes)) {
            $scopes = ['read', 'write'];
        }
        $scopes = array_values(array_unique(array_filter(array_map(
            static fn (mixed $s): string => strtolower(trim(is_scalar($s) ? (string) $s : '')),
            $scopes,
        ), static fn (string $s): bool => $s !== '')));
        if ($scopes === []) {
            $scopes = ['read', 'write'];
        }

        $resolved = [
            'private_key' => $privateKey,
            'public_key' => $publicKey,
            'encryption_key' => self::toString($overrides['encryption_key'] ?? Env::get('OAUTH_ENCRYPTION_KEY', '')),
            'scopes' => $scopes,
            'default_scope' => self::toString($overrides['default_scope'] ?? $fileConfig['default_scope'] ?? $scopes[0], $scopes[0]),
            'access_token_ttl' => self::toInt($overrides['access_token_ttl'] ?? $fileConfig['access_token_ttl'] ?? 3600, 3600),
            'refresh_token_ttl' => self::toInt($overrides['refresh_token_ttl'] ?? $fileConfig['refresh_token_ttl'] ?? 2592000, 2592000),
            'auth_code_ttl' => self::toInt($overrides['auth_code_ttl'] ?? $fileConfig['auth_code_ttl'] ?? 600, 600),
        ];
        self::$configCache[$cacheKey] = $resolved;
        return $resolved;
    }

    private static function keyPath(string $path): string
    {
        if (str_starts_with($path, 'file://')) {
            return $path;
        }
        return 'file://' . $path;
    }

    /** @return Key|string */
    private static function encryptionKey(string $raw): Key|string
    {
        if ($raw === '') {
            return '';
        }
        try {
            return Key::loadFromAsciiSafeString($raw);
        } catch (\Throwable) {
            // Plain string key: league falls back to password-based encryption.
            return $raw;
        }
    }

    /**
     * Build the league authorization server with all three grants enabled.
     *
     * @param array<string, mixed> $overrides
     */
    public static function create(array $overrides = []): LeagueAuthorizationServer
    {
        if (!self::isAvailable()) {
            throw new RuntimeException(
                'OAuth2 is not installed. Run: composer require league/oauth2-server laminas/laminas-diactoros',
            );
        }
        $config = self::config($overrides);
        if ($config['private_key'] === '' || $config['encryption_key'] === '') {
            throw new RuntimeException(
                'OAuth2 keys are not configured. Run: php siro make:oauth-keys',
            );
        }

        $server = new LeagueAuthorizationServer(
            new ClientRepository(),
            new AccessTokenRepository(),
            new ScopeRepository($config['scopes']),
            new CryptKey($config['private_key']),
            self::encryptionKey($config['encryption_key']),
        );
        $server->setDefaultScope(self::defaultScope());
        $accessTtl = new DateInterval('PT' . max(60, $config['access_token_ttl']) . 'S');

        $authCodeGrant = new AuthCodeGrant(
            new AuthCodeRepository(),
            new RefreshTokenRepository(),
            new DateInterval('PT' . max(60, $config['auth_code_ttl']) . 'S'),
        );
        $authCodeGrant->setRefreshTokenTTL(new DateInterval('PT' . max(60, $config['refresh_token_ttl']) . 'S'));
        $server->enableGrantType($authCodeGrant, $accessTtl);

        $server->enableGrantType(new ClientCredentialsGrant(), $accessTtl);

        $refreshGrant = new RefreshTokenGrant(new RefreshTokenRepository());
        $refreshGrant->setRefreshTokenTTL(new DateInterval('PT' . max(60, $config['refresh_token_ttl']) . 'S'));
        $server->enableGrantType($refreshGrant, $accessTtl);

        return $server;
    }

    /** @param array<string, mixed> $overrides */
    public static function resourceServer(array $overrides = []): LeagueResourceServer
    {
        if (!self::isAvailable()) {
            throw new RuntimeException(
                'OAuth2 is not installed. Run: composer require league/oauth2-server laminas/laminas-diactoros',
            );
        }
        $config = self::config($overrides);
        if ($config['public_key'] === '') {
            throw new RuntimeException(
                'OAuth2 public key is not configured. Run: php siro make:oauth-keys',
            );
        }
        return new LeagueResourceServer(
            new AccessTokenRepository(),
            new CryptKey($config['public_key']),
        );
    }

    /**
     * Create all OAuth2 tables (idempotent).
     */
    public static function createTables(): void
    {
        ClientRepository::createTable();
        AccessTokenRepository::createTable();
        RefreshTokenRepository::createTable();
        AuthCodeRepository::createTable();
    }

    /**
     * Validate a Bearer token and return its OAuth2 context, or null.
     *
     * Tries league's ResourceServer first (RS256 access tokens issued by the
     * token endpoint, revocation enforced by the repository). Falls back to
     * core JWT (HS256/RS256 tokens issued by the login flow) when the token
     * is not a league token. Used by `OAuthScopeMiddleware` and `AuthGuard`.
     *
     * @return array{token_id: string, client_id: string, user_id: int|null, scopes: array<int, string>, source: string}|null
     */
    public static function validateRequest(Request $request): ?array
    {
        $header = (string) $request->header('authorization', '');
        if (!preg_match('/^Bearer\s+(.+)$/i', $header, $matches)) {
            return null;
        }
        $token = trim($matches[1]);
        if ($token === '') {
            return null;
        }

        if (self::isEnabled()) {
            try {
                $psr = self::resourceServer()->validateAuthenticatedRequest(
                    self::psrRequestFromFramework($request),
                );
                $scopes = $psr->getAttribute('oauth_scopes');
                $userId = $psr->getAttribute('oauth_user_id');
                return [
                    'token_id' => self::toString($psr->getAttribute('oauth_access_token_id')),
                    'client_id' => self::toString($psr->getAttribute('oauth_client_id')),
                    'user_id' => is_numeric($userId) && (int) $userId > 0 ? (int) $userId : null,
                    'scopes' => is_array($scopes) ? array_values(array_map(static fn (mixed $v): string => is_scalar($v) ? (string) $v : '', $scopes)) : [],
                    'source' => 'oauth2',
                ];
            } catch (\Throwable) {
                // Not a league token (or revoked/expired) — try core JWT below.
            }
        }

        try {
            /** @var array<string, mixed> $claims */
            $claims = \Siro\Core\Auth\JWT::decode($token);
            if (($claims['type'] ?? '') !== \Siro\Core\Auth\JWT::TYPE_ACCESS) {
                return null;
            }
            $sub = $claims['sub'] ?? 0;
            return [
                'token_id' => is_string($claims['jti'] ?? null) ? (string) $claims['jti'] : '',
                'client_id' => '',
                'user_id' => is_numeric($sub) && (int) $sub > 0 ? (int) $sub : null,
                'scopes' => \Siro\Core\Auth\JWT::scopes($claims),
                'source' => 'jwt',
            ];
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Handle POST /oauth/token (all three grants) via the league server.
     */
    public static function handleTokenRequest(Request $request): Response
    {
        $server = self::create();
        try {
            $psrResponse = $server->respondToAccessTokenRequest(
                self::psrRequestFromFramework($request),
                new DiactorosResponse(),
            );
        } catch (OAuthServerException $e) {
            $psrResponse = $e->generateHttpResponse(new DiactorosResponse());
        }
        return self::frameworkResponseFromPsr($psrResponse);
    }

    /**
     * Handle POST /oauth/revoke (RFC 7009).
     *
     * The client must authenticate (Basic auth or body client_id + secret).
     * Unknown or already-dead tokens still return 200 — the endpoint never
     * leaks whether a token existed. Only tokens belonging to the
     * authenticated client are revoked, and only after their signature (access
     * token) or encryption (refresh token) has been verified.
     */
    public static function handleRevokeRequest(Request $request): Response
    {
        $body = $request->body();
        $token = trim(self::toString($body['token'] ?? ''));
        $hint = strtolower(trim(self::toString($body['token_type_hint'] ?? '')));

        $client = self::authenticateClient($request);
        if ($client === null) {
            return Response::error('Invalid client', 401, [
                'client' => ['Client authentication failed.'],
            ], 'invalid_client');
        }

        if ($token === '') {
            return Response::error('Missing token', 400, [
                'token' => ['The token parameter is required.'],
            ], 'invalid_request');
        }

        if ($hint === '' || $hint === 'access_token') {
            self::revokeAccessTokenIfOwned($token, $client['client_id']);
        }
        if ($hint === '' || $hint === 'refresh_token') {
            self::revokeRefreshTokenIfOwned($token, $client['client_id']);
        }

        return Response::json([], 200);
    }

    /**
     * Step 1 of the authorization_code flow: validate the authorize request.
     */
    public static function validateAuthorizationRequest(Request $request): AuthorizationRequestInterface
    {
        return self::create()->validateAuthorizationRequest(
            self::psrRequestFromFramework($request),
        );
    }

    /**
     * Step 2 of the authorization_code flow: attach the resource owner, approve
     * (or deny), and return the redirect response with the code.
     */
    public static function completeAuthorizationRequest(
        AuthorizationRequestInterface $authRequest,
        int $userId,
        bool $approved,
    ): Response {
        $user = new UserEntity();
        $user->setIdentifier((string) $userId);
        $authRequest->setUser($user);
        $authRequest->setAuthorizationApproved($approved);

        $psrResponse = self::create()->completeAuthorizationRequest(
            $authRequest,
            new DiactorosResponse(),
        );
        return self::frameworkResponseFromPsr($psrResponse);
    }

    /**
     * Convert a league OAuthServerException (e.g. a wrong redirect_uri on the
     * authorize endpoint) into a proper framework error response.
     *
     * RFC 6749 §4.1.2.1: when the redirect_uri is invalid the server MUST NOT
     * redirect — it answers the resource owner directly. League's
     * generateHttpResponse() does exactly that (a 401 invalid_client JSON
     * body when there is no safe redirect target), so this helper only adapts
     * it to a framework Response. Wrap the authorize route in try/catch and
     * return this — see docs/OAUTH2.md — otherwise the exception escapes as
     * a 500 (O2).
     */
    public static function authorizationErrorResponse(OAuthServerException $e): Response
    {
        return self::frameworkResponseFromPsr(
            $e->generateHttpResponse(new DiactorosResponse()),
        );
    }

    /**
     * Authenticate an OAuth2 client from Basic auth or the request body.
     *
     * @return array{client_id: string}|null
     */
    private static function authenticateClient(Request $request): ?array
    {
        $clientId = '';
        $clientSecret = null;

        $header = (string) $request->header('authorization', '');
        if (preg_match('/^Basic\s+(.+)$/i', $header, $m) === 1) {
            $decoded = base64_decode(trim($m[1]), true);
            if (is_string($decoded) && str_contains($decoded, ':')) {
                [$clientId, $clientSecret] = explode(':', $decoded, 2);
            }
        }

        $body = $request->body();
        if ($clientId === '' && isset($body['client_id'])) {
            $clientId = trim(self::toString($body['client_id']));
            $clientSecret = isset($body['client_secret']) ? self::toString($body['client_secret']) : null;
        }

        if ($clientId === '') {
            return null;
        }

        $repo = new ClientRepository();
        if (!$repo->validateClient($clientId, $clientSecret, null)) {
            return null;
        }
        return ['client_id' => $clientId];
    }

    private static function revokeAccessTokenIfOwned(string $token, string $clientId): void
    {
        try {
            $psr = self::resourceServer()->validateAuthenticatedRequest(
                self::psrRequestFromFramework(
                    new Request('GET', '/', [], ['Authorization' => 'Bearer ' . $token]),
                ),
            );
            if (self::toString($psr->getAttribute('oauth_client_id')) !== $clientId) {
                return;
            }
            $tokenId = self::toString($psr->getAttribute('oauth_access_token_id'));
            (new AccessTokenRepository())->revokeAccessToken($tokenId);
            \Siro\Core\Auth\JWT::blacklistJti($tokenId, time() + 3600);
        } catch (\Throwable) {
            // Already revoked/expired/invalid: nothing to do (RFC 7009: 200).
        }
    }

    private static function revokeRefreshTokenIfOwned(string $token, string $clientId): void
    {
        try {
            $config = self::config();
            $key = self::encryptionKey($config['encryption_key']);
            if ($key instanceof Key) {
                $decrypted = \Defuse\Crypto\Crypto::decrypt($token, $key);
            } else {
                $decrypted = \Defuse\Crypto\Crypto::decryptWithPassword($token, (string) $key);
            }
            /** @var array<string, mixed>|null $data */
            $data = json_decode($decrypted, true);
            if (!is_array($data) || self::toString($data['client_id'] ?? '') !== $clientId) {
                return;
            }
            $refreshId = self::toString($data['refresh_token_id'] ?? '');
            if ($refreshId === '') {
                return;
            }
            (new RefreshTokenRepository())->revokeRefreshToken($refreshId);
        } catch (WrongKeyOrModifiedCiphertextException) {
            // Not a refresh token (or wrong key): ignore.
        } catch (\Throwable) {
            // Ignore: RFC 7009 says unknown tokens still return 200.
        }
    }

    /**
     * Build a PSR-7 server request from a framework request.
     */
    public static function psrRequestFromFramework(Request $request): ServerRequestInterface
    {
        $uri = 'http://localhost' . $request->path();
        $query = $request->queryAll();
        if ($query !== []) {
            $uri .= '?' . http_build_query($query);
        }

        /** @var array<non-empty-string, string> $headers */
        $headers = $request->headersAll();
        $serverRequest = new ServerRequest([], [], $uri, $request->method(), 'php://temp', $headers);
        $serverRequest = $serverRequest->withParsedBody($request->body());
        return $serverRequest->withQueryParams($query);
    }

    /**
     * Convert a league PSR-7 response to a framework response.
     */
    public static function frameworkResponseFromPsr(ResponseInterface $psr): Response
    {
        $status = $psr->getStatusCode();
        $body = (string) $psr->getBody();
        $location = $psr->getHeaderLine('Location');

        if ($location !== '') {
            // League redirects to the client's REGISTERED redirect_uri (any
            // host). Do NOT use Response::redirect() here: it rewrites
            // cross-host URLs to '/' when they differ from APP_URL, which
            // would break the authorization flow. League has already
            // validated this URI against the client's registered list.
            $response = new Response([], $status);
            $response->withHeaders(['Location' => $location]);
            return $response;
        }

        $contentType = $psr->getHeaderLine('Content-Type');
        if (str_contains($contentType, 'application/json')) {
            /** @var array<string, mixed>|null $decoded */
            $decoded = json_decode($body, true);
            $response = Response::json(is_array($decoded) ? $decoded : [], $status);
        } else {
            $response = new Response([], $status);
        }

        $extraHeaders = [];
        foreach (['Cache-Control', 'Pragma'] as $name) {
            $value = $psr->getHeaderLine($name);
            if ($value !== '') {
                $extraHeaders[$name] = $value;
            }
        }
        if ($extraHeaders !== []) {
            $response->withHeaders($extraHeaders);
        }
        return $response;
    }
}

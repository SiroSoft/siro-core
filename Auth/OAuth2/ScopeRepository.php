<?php

declare(strict_types=1);

namespace Siro\Core\Auth\OAuth2;

use League\OAuth2\Server\Entities\ClientEntityInterface;
use League\OAuth2\Server\Entities\ScopeEntityInterface;
use League\OAuth2\Server\Repositories\ScopeRepositoryInterface;
use Siro\Core\Auth\OAuth2\Entities\ClientEntity;
use Siro\Core\Auth\OAuth2\Entities\ScopeEntity;

/**
 * League scope repository.
 *
 * The allowlist comes from `AuthorizationServer::defaultScopes()` (config
 * `oauth.scopes`, default `['read', 'write']`). An unknown scope identifier
 * returns null, which makes league reject the request with `invalid_scope` —
 * scope names are never invented at runtime.
 *
 * `finalizeScopes()` only ever NARROWS: the granted set is the intersection
 * of the requested scopes and the scopes the client is allowed to use (a
 * client with an empty allowlist may use every configured scope). A client
 * can never escalate its own scopes by asking for more.
 */
final class ScopeRepository implements ScopeRepositoryInterface
{
    /** @var array<int, string> */
    private array $allowedScopes;

    /** @param array<int, string>|null $allowedScopes Null = config default. */
    public function __construct(?array $allowedScopes = null)
    {
        $this->allowedScopes = $allowedScopes ?? AuthorizationServer::defaultScopes();
    }

    public function getScopeEntityByIdentifier(string $identifier): ?ScopeEntityInterface
    {
        $identifier = strtolower(trim($identifier));
        if ($identifier === '' || !in_array($identifier, $this->allowedScopes, true)) {
            return null;
        }

        $entity = new ScopeEntity();
        $entity->setIdentifier($identifier);
        return $entity;
    }

    /**
     * @param ScopeEntityInterface[] $scopes
     * @return ScopeEntityInterface[]
     */
    public function finalizeScopes(
        array $scopes,
        string $grantType,
        ClientEntityInterface $clientEntity,
        ?string $userIdentifier = null,
        ?string $authCodeId = null,
    ): array {
        $clientAllowed = $clientEntity instanceof ClientEntity
            ? $clientEntity->getAllowedScopes()
            : [];
        // Empty client allowlist = no restriction beyond the configured scopes.
        $allowed = $clientAllowed === [] ? $this->allowedScopes : $clientAllowed;

        $requested = [];
        foreach ($scopes as $scope) {
            $id = strtolower(trim($scope->getIdentifier()));
            if ($id !== '' && in_array($id, $allowed, true) && in_array($id, $this->allowedScopes, true)) {
                $requested[$id] = $scope;
            }
        }

        if ($requested === []) {
            // No scope (left) requested: grant the default scope if allowed.
            $default = AuthorizationServer::defaultScope();
            if ($default !== '' && in_array($default, $allowed, true) && in_array($default, $this->allowedScopes, true)) {
                $entity = new ScopeEntity();
                $entity->setIdentifier($default);
                return [$entity];
            }
            return [];
        }

        return array_values($requested);
    }
}

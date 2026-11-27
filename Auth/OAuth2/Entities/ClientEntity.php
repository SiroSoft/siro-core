<?php

declare(strict_types=1);

namespace Siro\Core\Auth\OAuth2\Entities;

use League\OAuth2\Server\Entities\ClientEntityInterface;
use League\OAuth2\Server\Entities\Traits\ClientTrait;
use League\OAuth2\Server\Entities\Traits\EntityTrait;

/**
 * Concrete OAuth2 client entity (league ships only the interface + trait).
 *
 * Extra state beyond the league interface: the scopes and grant types this
 * client is allowed to use. The repositories fill these from the
 * `oauth_clients` row so `ScopeRepository::finalizeScopes()` and
 * `ClientRepository::validateClient()` can enforce them.
 */
final class ClientEntity implements ClientEntityInterface
{
    use ClientTrait;
    use EntityTrait;

    /** @var array<int, string> */
    private array $allowedScopes = [];

    /** @var array<int, string> */
    private array $allowedGrants = [];

    public function setName(string $name): void
    {
        $this->name = $name;
    }

    /** @param string|string[] $redirectUri */
    public function setRedirectUri(string|array $redirectUri): void
    {
        $this->redirectUri = $redirectUri;
    }

    public function setConfidential(bool $isConfidential): void
    {
        $this->isConfidential = $isConfidential;
    }

    /** @param array<int, string> $allowedScopes */
    public function setAllowedScopes(array $allowedScopes): void
    {
        $this->allowedScopes = $allowedScopes;
    }

    /** @return array<int, string> */
    public function getAllowedScopes(): array
    {
        return $this->allowedScopes;
    }

    /** @param array<int, string> $allowedGrants */
    public function setAllowedGrants(array $allowedGrants): void
    {
        $this->allowedGrants = $allowedGrants;
    }

    /** @return array<int, string> */
    public function getAllowedGrants(): array
    {
        return $this->allowedGrants;
    }
}

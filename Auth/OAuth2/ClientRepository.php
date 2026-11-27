<?php

declare(strict_types=1);

namespace Siro\Core\Auth\OAuth2;

use League\OAuth2\Server\Entities\ClientEntityInterface;
use League\OAuth2\Server\Repositories\ClientRepositoryInterface;
use Siro\Core\Auth\OAuth2\Entities\ClientEntity;
use Siro\Core\Database;

/**
 * League client repository backed by the `oauth_clients` table.
 *
 * Enforces, at validation time, that the client exists, is not revoked, and
 * is allowed to use the requested grant type. Redirect URIs are enforced by
 * league's `AuthCodeGrant` against the URIs carried on the entity.
 */
final class ClientRepository implements ClientRepositoryInterface
{
    public function getClientEntity(string $clientIdentifier): ?ClientEntityInterface
    {
        $row = OAuthClient::findById($clientIdentifier);
        if ($row === null || (int) ($row['revoked'] ?? 0) !== 0) {
            return null;
        }

        $clientId = (string) ($row['client_id'] ?? '');
        if ($clientId === '') {
            return null;
        }
        $entity = new ClientEntity();
        $entity->setIdentifier($clientId);
        $entity->setName((string) ($row['name'] ?? ''));
        $uris = OAuthClient::redirectUris($row);
        $entity->setRedirectUri(count($uris) === 1 ? $uris[0] : $uris);
        $entity->setConfidential((int) ($row['is_confidential'] ?? 1) !== 0);
        $entity->setAllowedScopes(OAuthClient::scopes($row));
        $entity->setAllowedGrants(OAuthClient::grants($row));

        return $entity;
    }

    public function validateClient(
        string $clientIdentifier,
        ?string $clientSecret,
        ?string $grantType,
    ): bool {
        $row = OAuthClient::findById($clientIdentifier);
        if ($row === null || (int) ($row['revoked'] ?? 0) !== 0) {
            return false;
        }

        if ($grantType !== null && $grantType !== '') {
            $grants = OAuthClient::grants($row);
            if (!in_array(strtolower($grantType), $grants, true)) {
                return false;
            }
        }

        if ((int) ($row['is_confidential'] ?? 1) !== 0) {
            if ($clientSecret === null || $clientSecret === '') {
                return false;
            }
            return OAuthClient::validateSecret($clientIdentifier, $clientSecret);
        }

        // Public client (mobile/SPA): no secret — PKCE is the proof.
        return true;
    }

    /**
     * Create the oauth_clients table (delegates to the client registry).
     */
    public static function createTable(): void
    {
        OAuthClient::createTable();
    }
}

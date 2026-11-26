<?php

declare(strict_types=1);

namespace Siro\Core\Auth\OAuth2\Entities;

use League\OAuth2\Server\Entities\AccessTokenEntityInterface;
use League\OAuth2\Server\Entities\Traits\AccessTokenTrait;
use League\OAuth2\Server\Entities\Traits\EntityTrait;
use League\OAuth2\Server\Entities\Traits\TokenEntityTrait;

/**
 * Concrete OAuth2 access token entity (league ships only the interface + trait).
 *
 * The JWT itself is built by league's `AccessTokenTrait::convertToJWT()` (RS256,
 * signed with the OAuth private key). We deliberately do NOT override it: the
 * token carries league's `scopes` array claim, which `BearerTokenValidator`
 * reads back. `OAuthScopeMiddleware` accepts both that claim and core JWT's
 * `scope` string claim.
 */
final class AccessTokenEntity implements AccessTokenEntityInterface
{
    use AccessTokenTrait;
    use EntityTrait;
    use TokenEntityTrait;
}

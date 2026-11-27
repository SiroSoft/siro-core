<?php

declare(strict_types=1);

namespace Siro\Core\Auth\OAuth2\Entities;

use League\OAuth2\Server\Entities\RefreshTokenEntityInterface;
use League\OAuth2\Server\Entities\Traits\EntityTrait;
use League\OAuth2\Server\Entities\Traits\RefreshTokenTrait;

/** Concrete OAuth2 refresh token entity (league ships only the interface + trait). */
final class RefreshTokenEntity implements RefreshTokenEntityInterface
{
    use EntityTrait;
    use RefreshTokenTrait;
}

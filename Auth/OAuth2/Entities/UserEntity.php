<?php

declare(strict_types=1);

namespace Siro\Core\Auth\OAuth2\Entities;

use League\OAuth2\Server\Entities\Traits\EntityTrait;
use League\OAuth2\Server\Entities\UserEntityInterface;

/**
 * Concrete OAuth2 user entity (league ships only the interface + trait).
 * Used to attach the resource owner to an authorization request.
 */
final class UserEntity implements UserEntityInterface
{
    use EntityTrait;
}

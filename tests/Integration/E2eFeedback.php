<?php

declare(strict_types=1);

namespace Siro\Core\Tests\Integration;

use Siro\Core\Model;

/**
 * Trial model for a NEW SiroERP module ("Customer Feedback"). The app has no
 * Feedback model today, so this is the shape a new module would take:
 * final class, $table + $fillable, no hand-written controller — one
 * apiResource() line in routes/api.php.
 */

final class E2eFeedback extends Model
{
    protected string $table = 'customer_feedback';

    /** @var array<int, string> */
    protected array $fillable = ['id', 'customer_id', 'rating', 'message', 'status'];

    public bool $timestamps = false;
}

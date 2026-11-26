<?php

declare(strict_types=1);

namespace Siro\Core\Tests\Integration;

use Siro\Core\Model;

/**
 * Autoloadable trial model for the committed contract fixture app
 * (`tests/fixtures/contract-app/routes/api.php`).
 *
 * Unlike G3's `E2eFeedback` (defined at the bottom of ApiCrudE2eTest.php
 * and only available while that test file is loaded), this class lives in
 * its own PSR-4 file so `api:contract` / `make:openapi` can load the
 * fixture routes in any process (CI included).
 *
 * Shape mirrors a NEW SiroERP module: final class, $table + $fillable,
 * no hand-written controller — one `apiResource()` line.
 */
final class ContractWidget extends Model
{
    protected string $table = 'contract_widgets';

    /** @var array<int, string> */
    protected array $fillable = ['id', 'name', 'status'];

    public bool $timestamps = false;
}

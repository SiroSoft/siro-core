<?php

declare(strict_types=1);

namespace Siro\Core\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Siro\Core\DB\QueryBuilder;
use Siro\Core\Auth\ApiKey;
use Siro\Core\Middleware\ApiKeyMiddleware;

/**
 * Batch, Cursor, ApiKey Tests
 */
final class BatchCursorApiKeyTest extends TestCase
{
    public function testQueryBuilderHasUpdateWhereIn(): void
    {
        $this->assertTrue(method_exists(QueryBuilder::class, 'updateWhereIn'));
    }

    public function testQueryBuilderHasDeleteWhereIn(): void
    {
        $this->assertTrue(method_exists(QueryBuilder::class, 'deleteWhereIn'));
    }

    public function testQueryBuilderHasInsertMany(): void
    {
        $this->assertTrue(method_exists(QueryBuilder::class, 'insertMany'));
    }

    public function testQueryBuilderHasCursorPaginate(): void
    {
        $this->assertTrue(method_exists(QueryBuilder::class, 'cursorPaginate'));
    }

    public function testApiKeyClassExists(): void
    {
        $this->assertTrue(class_exists(ApiKey::class));
    }

    public function testApiKeyHasCreate(): void
    {
        $this->assertTrue(method_exists(ApiKey::class, 'create'));
    }

    public function testApiKeyHasValidate(): void
    {
        $this->assertTrue(method_exists(ApiKey::class, 'validate'));
    }

    public function testApiKeyHasRevoke(): void
    {
        $this->assertTrue(method_exists(ApiKey::class, 'revoke'));
    }

    public function testApiKeyHasHasScope(): void
    {
        $this->assertTrue(method_exists(ApiKey::class, 'hasScope'));
    }

    public function testApiKeyHasCreateTable(): void
    {
        $this->assertTrue(method_exists(ApiKey::class, 'createTable'));
    }

    public function testApiKeyHasListForUser(): void
    {
        $this->assertTrue(method_exists(ApiKey::class, 'listForUser'));
    }

    public function testApiKeyHasRevokeAllForUser(): void
    {
        $this->assertTrue(method_exists(ApiKey::class, 'revokeAllForUser'));
    }

    public function testApiKeyMiddlewareExists(): void
    {
        $this->assertTrue(class_exists(ApiKeyMiddleware::class));
    }

    public function testApiKeyMiddlewareHasHandle(): void
    {
        $this->assertTrue(method_exists(ApiKeyMiddleware::class, 'handle'));
    }
}
<?php

declare(strict_types=1);

namespace Siro\Core\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Siro\Core\DB\Relations\HasOne;
use Siro\Core\DB\Relations\BelongsToMany;
use Siro\Core\DB\Relations\HasMany;
use Siro\Core\DB\Relations\BelongsTo;

/**
 * Relations Unit Tests
 */
final class RelationsTest extends TestCase
{
    public function testHasOneClassExists(): void
    {
        $this->assertTrue(class_exists(HasOne::class));
    }

    public function testHasOneHasGetMethod(): void
    {
        $this->assertTrue(method_exists(HasOne::class, 'get'));
    }

    public function testHasOneHasQueryMethod(): void
    {
        $this->assertTrue(method_exists(HasOne::class, 'query'));
    }

    public function testBelongsToManyClassExists(): void
    {
        $this->assertTrue(class_exists(BelongsToMany::class));
    }

    public function testBelongsToManyHasGetMethod(): void
    {
        $this->assertTrue(method_exists(BelongsToMany::class, 'get'));
    }

    public function testBelongsToManyHasAttachMethod(): void
    {
        $this->assertTrue(method_exists(BelongsToMany::class, 'attach'));
    }

    public function testBelongsToManyHasDetachMethod(): void
    {
        $this->assertTrue(method_exists(BelongsToMany::class, 'detach'));
    }

    public function testBelongsToManyHasSyncMethod(): void
    {
        $this->assertTrue(method_exists(BelongsToMany::class, 'sync'));
    }

    public function testBelongsToManyHasHasMethod(): void
    {
        $this->assertTrue(method_exists(BelongsToMany::class, 'has'));
    }

    public function testBelongsToManyHasToggleMethod(): void
    {
        $this->assertTrue(method_exists(BelongsToMany::class, 'toggle'));
    }

    public function testHasManyClassExists(): void
    {
        $this->assertTrue(class_exists(HasMany::class));
    }

    public function testBelongsToClassExists(): void
    {
        $this->assertTrue(class_exists(BelongsTo::class));
    }
}
<?php

declare(strict_types=1);

namespace Siro\Core\Tests\Unit;

use Siro\Core\Tests\TestCase;
use Siro\Core\Model;

/**
 * Model Unit Tests
 * 
 * Tests Model layer functionality
 */
final class ModelTest extends TestCase
{
    /**
     * Test Model class exists
     */
    public function testModelClassExists(): void
    {
        $this->assertTrue(class_exists(Model::class));
    }

    /**
     * Test Model has find method
     */
    public function testModelHasFindMethod(): void
    {
        $this->assertTrue(method_exists(Model::class, 'find'));
    }

    /**
     * Test Model has where method
     */
    public function testModelHasWhereMethod(): void
    {
        $this->assertTrue(method_exists(Model::class, 'where'));
    }

    /**
     * Test Model has create method
     */
    public function testModelHasCreateMethod(): void
    {
        $this->assertTrue(method_exists(Model::class, 'create'));
    }

    /**
     * Test Model has update method
     */
    public function testModelHasUpdateMethod(): void
    {
        $this->assertTrue(method_exists(Model::class, 'update'));
    }

    /**
     * Test Model has delete method
     */
    public function testModelHasDeleteMethod(): void
    {
        $this->assertTrue(method_exists(Model::class, 'delete'));
    }

    /**
     * Test Model has getTable method
     */
    public function testModelHasGetTableMethod(): void
    {
        $this->assertTrue(method_exists(Model::class, 'getTable'));
    }

    /**
     * Test Model has query method
     */
    public function testModelHasQueryMethod(): void
    {
        $this->assertTrue(method_exists(Model::class, 'query'));
    }

    /**
     * Test Model has toArray method
     */
    public function testModelHasToArrayMethod(): void
    {
        $this->assertTrue(method_exists(Model::class, 'toArray'));
    }

    /**
     * Test Model has hasMany method
     */
    public function testModelHasHasManyMethod(): void
    {
        $this->assertTrue(method_exists(Model::class, 'hasMany'));
    }

    /**
     * Test Model has belongsTo method
     */
    public function testModelHasBelongsToMethod(): void
    {
        $this->assertTrue(method_exists(Model::class, 'belongsTo'));
    }

    /**
     * Test Model has save method
     */
    public function testModelHasSaveMethod(): void
    {
        $this->assertTrue(method_exists(Model::class, 'save'));
    }

    /**
     * Test Model has fill method
     */
    public function testModelHasFillMethod(): void
    {
        $this->assertTrue(method_exists(Model::class, 'fill'));
    }

    /**
     * Test Model has getAttribute method
     */
    public function testModelHasGetAttributeMethod(): void
    {
        $this->assertTrue(method_exists(Model::class, 'getAttribute'));
    }

    /**
     * Test Model has setAttribute method
     */
    public function testModelHasSetAttributeMethod(): void
    {
        $this->assertTrue(method_exists(Model::class, 'setAttribute'));
    }

    /**
     * Test Model has getHidden method
     */
    public function testModelHasGetHiddenMethod(): void
    {
        $this->assertTrue(method_exists(Model::class, 'getHidden'));
    }

    /**
     * Test Model has setCasts method
     */
    public function testModelHasSetCastsMethod(): void
    {
        $this->assertTrue(method_exists(Model::class, 'setCasts'));
    }

    /**
     * Test Model has setFillable method
     */
    public function testModelHasSetFillableMethod(): void
    {
        $this->assertTrue(method_exists(Model::class, 'setFillable'));
    }

    /**
     * Test Model has setHidden method
     */
    public function testModelHasSetHiddenMethod(): void
    {
        $this->assertTrue(method_exists(Model::class, 'setHidden'));
    }

    /**
     * Test Model has all method
     */
    public function testModelHasAllMethod(): void
    {
        $this->assertTrue(method_exists(Model::class, 'all'));
    }

    /**
     * Test Model has first method
     */
    public function testModelHasFirstMethod(): void
    {
        $this->assertTrue(method_exists(Model::class, 'first'));
    }

    /**
     * Test Model has count method
     */
    public function testModelHasCountMethod(): void
    {
        $this->assertTrue(method_exists(Model::class, 'count'));
    }

    /**
     * Test Model has paginate method
     */
    public function testModelHasPaginateMethod(): void
    {
        $this->assertTrue(method_exists(Model::class, 'paginate'));
    }

    /**
     * Test Model has orderBy method
     */
    public function testModelHasOrderByMethod(): void
    {
        $this->assertTrue(method_exists(Model::class, 'orderBy'));
    }

    /**
     * Test Model has limit method
     */
    public function testModelHasLimitMethod(): void
    {
        $this->assertTrue(method_exists(Model::class, 'limit'));
    }

    /**
     * Test Model has offset method
     */
    public function testModelHasOffsetMethod(): void
    {
        $this->assertTrue(method_exists(Model::class, 'offset'));
    }

    /**
     * Test Model has select method
     */
    public function testModelHasSelectMethod(): void
    {
        $this->assertTrue(method_exists(Model::class, 'select'));
    }

    /**
     * Test Model is abstract
     */
    public function testModelIsAbstract(): void
    {
        $reflection = new \ReflectionClass(Model::class);
        $this->assertTrue($reflection->isAbstract());
    }
}

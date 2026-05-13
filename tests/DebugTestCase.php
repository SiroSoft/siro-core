<?php

declare(strict_types=1);

namespace Siro\Core\Tests;

use PHPUnit\Framework\TestCase as BaseTestCase;

abstract class DebugTestCase extends BaseTestCase
{
    use TestHelper;

    protected function setUp(): void
    {
        parent::setUp();
        $this->resetStaticState();
    }

    protected function tearDown(): void
    {
        $this->resetStaticState();
        parent::tearDown();
    }
}

<?php

declare(strict_types=1);

namespace Siro\Core\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Siro\Core\DB\EagerLoader;

final class EagerLoaderTest extends TestCase
{
    public function testLoaderCanBeInstantiated(): void
    {
        $loader = new EagerLoader(\stdClass::class);
        $this->assertInstanceOf(EagerLoader::class, $loader);
    }
}

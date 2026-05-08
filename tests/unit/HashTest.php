<?php

declare(strict_types=1);

namespace Siro\Core\Tests\Unit;

use Siro\Core\Tests\TestCase;
use Siro\Core\Hash;

final class HashTest extends TestCase
{
    public function testMake(): void
    {
        $hash = Hash::make('password123');
        $this->assertIsString($hash);
        $this->assertNotEmpty($hash);
        $this->assertStringStartsWith('$2y$', $hash);
    }

    public function testCheck(): void
    {
        $hash = Hash::make('correct_password');
        $this->assertTrue(Hash::check('correct_password', $hash));
        $this->assertFalse(Hash::check('wrong_password', $hash));
    }

    public function testNeedsRehash(): void
    {
        $hash = Hash::make('test', ['cost' => 4]);
        $needsRehash = Hash::needsRehash($hash, ['cost' => 12]);
        $this->assertIsBool($needsRehash);
    }

    public function testInfo(): void
    {
        $hash = Hash::make('test');
        $info = Hash::info($hash);
        $this->assertArrayHasKey('algo', $info);
        $this->assertArrayHasKey('cost', $info);
        $this->assertSame('bcrypt', $info['algo']);
    }

    public function testDifferentPasswordsProduceDifferentHashes(): void
    {
        $hash1 = Hash::make('password1');
        $hash2 = Hash::make('password2');
        $this->assertNotSame($hash1, $hash2);
    }

    public function testSamePasswordProducesDifferentHashes(): void
    {
        $hash1 = Hash::make('same_password');
        $hash2 = Hash::make('same_password');
        $this->assertNotSame($hash1, $hash2);
        $this->assertTrue(Hash::check('same_password', $hash1));
        $this->assertTrue(Hash::check('same_password', $hash2));
    }
}

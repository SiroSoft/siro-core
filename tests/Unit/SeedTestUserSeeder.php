<?php

declare(strict_types=1);

namespace Siro\Core\Tests\Unit;

use Siro\Core\Seeder;

final class SeedTestUserSeeder extends Seeder
{
    public function run(): void
    {
        $this->insertIgnore('seed_users', ['email' => 'dup@example.com']);
        $this->insertIgnore('seed_users', ['email' => 'dup@example.com']);
    }
}

<?php

declare(strict_types=1);

namespace Siro\Core\Tests\Unit;

use Siro\Core\Seeder;

final class SeedTestCallerSeeder extends Seeder
{
    public function run(): void
    {
        $this->call(SeedTestUserSeeder::class);
    }
}

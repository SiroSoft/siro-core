<?php

declare(strict_types=1);

namespace Siro\Core\Tests\Unit;

use Siro\Core\Seeder;

final class SeedTestInsertSeeder extends Seeder
{
    public int $insertedId = 0;

    public function run(): void
    {
        $this->insertedId = $this->insert('seed_users', ['email' => 'b@example.com']);
    }
}

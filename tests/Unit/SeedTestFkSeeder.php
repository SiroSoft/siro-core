<?php

declare(strict_types=1);

namespace Siro\Core\Tests\Unit;

use Siro\Core\Seeder;

final class SeedTestFkSeeder extends Seeder
{
    public function run(): void
    {
        // user_id 999 does not exist — FK violation must throw.
        $this->insertIgnore('seed_posts', ['user_id' => 999, 'title' => 'orphan']);
    }
}

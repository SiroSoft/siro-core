<?php

declare(strict_types=1);

namespace Siro\Core\Tests\Unit;

use Siro\Core\Testing\Factory;
use Siro\Core\Testing\Faker;

final class ToolUserFactory extends Factory
{
    protected string $model = ToolUser::class;

    public function definition(): array
    {
        return [
            'name' => Faker::vnFullName(),
            'email' => Faker::email(),
            'role' => 'user',
        ];
    }
}

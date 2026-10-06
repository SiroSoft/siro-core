<?php

declare(strict_types=1);

namespace Siro\Core\Testing;

use Siro\Core\Model;

/**
 * Abstract model factory base.
 *
 * Extend per model, describe columns in definition() with Faker helpers,
 * then create rows without hand-writing seed arrays:
 *
 *   final class UserFactory extends Factory
 *   {
 *       protected string $model = User::class;
 *
 *       public function definition(): array
 *       {
 *           return [
 *               'name' => Faker::vnFullName(),
 *               'email' => Faker::email(),
 *               'password' => password_hash('secret', PASSWORD_BCRYPT),
 *           ];
 *       }
 *
 *       public function admin(): static
 *       {
 *           return $this->with(['role' => 'admin']);
 *       }
 *   }
 *
 *   $user = UserFactory::new()->create();          // persisted Model
 *   $users = UserFactory::new()->count(5)->create(); // array of Models
 *   $attrs = UserFactory::new()->make();             // attributes only, no DB
 *
 * @package Siro\Core\Testing
 */
abstract class Factory
{
    /** @var class-string<Model> */
    protected string $model = Model::class;

    private int $count = 1;

    /** @var array<string, mixed> */
    private array $overrides = [];

    /** @var array<int, callable(array<string, mixed>): array<string, mixed>> */
    private array $states = [];

    public static function new(): static
    {
        $class = static::class;
        return new $class();
    }

    public function count(int $count): static
    {
        $this->count = max(1, $count);
        return $this;
    }

    /**
     * Override attributes (last write wins over definition and states).
     *
     * @param array<string, mixed> $attributes
     */
    public function with(array $attributes): static
    {
        $this->overrides = [...$this->overrides, ...$attributes];
        return $this;
    }

    /**
     * Transform generated attributes. Receives the current attributes,
     * returns the modified set.
     *
     * @param callable(array<string, mixed>): array<string, mixed> $state
     */
    public function state(callable $state): static
    {
        $this->states[] = $state;
        return $this;
    }

    /**
     * @return array<string, mixed>
     */
    abstract public function definition(): array;

    /**
     * Build one attribute set without touching the database.
     *
     * @return array<string, mixed>
     */
    public function raw(): array
    {
        $attributes = $this->definition();
        foreach ($this->states as $state) {
            $attributes = $state($attributes);
        }
        return [...$attributes, ...$this->overrides];
    }

    /**
     * Build attribute sets without persisting. Single array when count is 1,
     * list of arrays otherwise.
     *
     * @return array<string, mixed>|array<int, array<string, mixed>>
     */
    public function make(): mixed
    {
        if ($this->count === 1) {
            return $this->raw();
        }
        $rows = [];
        for ($i = 0; $i < $this->count; $i++) {
            $rows[] = $this->raw();
        }
        return $rows;
    }

    /**
     * Persist and return the model (or a list of models when count() > 1).
     *
     * @return Model|array<int, Model>
     */
    public function create(): mixed
    {
        $modelClass = $this->model;
        if ($this->count === 1) {
            return $this->persistOne($modelClass);
        }
        $models = [];
        for ($i = 0; $i < $this->count; $i++) {
            $models[] = $this->persistOne($modelClass);
        }
        return $models;
    }

    /**
     * @param class-string<Model> $modelClass
     */
    private function persistOne(string $modelClass): Model
    {
        /** @var Model $instance */
        $instance = new $modelClass();
        $instance->fill($this->raw());
        if (!$instance->save()) {
            throw new \RuntimeException("Factory failed to persist [{$modelClass}].");
        }
        return $instance;
    }
}

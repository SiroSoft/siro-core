<?php

declare(strict_types=1);

namespace Siro\Core;

use PDO;
use RuntimeException;
use Siro\Core\DB\QueryBuilder;

/**
 * Base Model class for database operations.
 * Provides convenient methods for CRUD operations with automatic column detection.
 */
abstract class Model
{
    /** @var string Table name (auto-detected if not set) */
    protected string $table = '';

    /** @var array<int, string> Hidden fields that should not be included in toArray() */
    protected array $hidden = [];

    /** @var array<string, string> Type casts for attributes */
    protected array $casts = [];

    /** @var array<int, string> Fillable fields for mass assignment */
    protected array $fillable = [];

    /** @var array<string, mixed> Model attributes */
    private array $attributes = [];

    /** @var bool Whether the model exists in the database */
    private bool $exists = false;

    /**
     * Create a new model instance.
     *
     * @param array<string, mixed> $attributes
     */
    public function __construct(array $attributes = [])
    {
        $this->fill($attributes);
    }

    /**
     * Get the table name (auto-detect from class name if not set).
     */
    public function getTable(): string
    {
        if ($this->table !== '') {
            return $this->table;
        }

        // Auto-detect: App\Models\User -> users
        $className = basename(str_replace('\\', '/', static::class));
        $tableName = strtolower(preg_replace('/(?<!^)[A-Z]/', '_$0', $className)) . 's';
        
        return $tableName;
    }

    /**
     * Fill the model with an array of attributes.
     *
     * @param array<string, mixed> $attributes
     */
    public function fill(array $attributes): self
    {
        foreach ($attributes as $key => $value) {
            if ($this->isFillable($key)) {
                $this->setAttribute($key, $value);
            }
        }

        return $this;
    }

    /**
     * Check if an attribute is fillable.
     */
    private function isFillable(string $key): bool
    {
        if ($this->fillable === []) {
            return true; // All fields fillable if no fillable specified
        }

        return in_array($key, $this->fillable, true);
    }

    /**
     * Set an attribute value.
     */
    public function setAttribute(string $key, mixed $value): void
    {
        $this->attributes[$key] = $value;
    }

    /**
     * Get an attribute value.
     */
    public function getAttribute(string $key): mixed
    {
        if (!array_key_exists($key, $this->attributes)) {
            return null;
        }

        $value = $this->attributes[$key];

        // Apply casts
        if (isset($this->casts[$key])) {
            return $this->castAttribute($key, $value);
        }

        return $value;
    }

    /**
     * Cast an attribute to a native PHP type.
     */
    private function castAttribute(string $key, mixed $value): mixed
    {
        if ($value === null) {
            return null;
        }

        return match ($this->casts[$key]) {
            'int', 'integer' => (int) $value,
            'float', 'double', 'real' => (float) $value,
            'string' => (string) $value,
            'bool', 'boolean' => (bool) $value,
            default => $value,
        };
    }

    /**
     * Magic getter for attributes.
     */
    public function __get(string $name): mixed
    {
        return $this->getAttribute($name);
    }

    /**
     * Magic setter for attributes.
     */
    public function __set(string $name, mixed $value): void
    {
        $this->setAttribute($name, $value);
    }

    /**
     * Check if an attribute exists.
     */
    public function __isset(string $name): bool
    {
        return array_key_exists($name, $this->attributes);
    }

    /**
     * Convert model to array (excluding hidden fields).
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $array = [];

        foreach ($this->attributes as $key => $value) {
            if (!in_array($key, $this->hidden, true)) {
                $array[$key] = $this->getAttribute($key);
            }
        }

        return $array;
    }

    /**
     * Find a model by its primary key.
     *
     * @return static|null
     */
    public static function find(int|string $id): ?self
    {
        $instance = new static();
        $table = $instance->getTable();

        $result = Database::table($table)->where('id', '=', $id)->first();

        if ($result === null) {
            return null;
        }

        $model = new static($result);
        $model->exists = true;

        return $model;
    }

    /**
     * Start a new query builder instance.
     */
    public static function query(): QueryBuilder
    {
        $instance = new static();
        return Database::table($instance->getTable());
    }

    /**
     * Begin querying the model.
     */
    public static function where(string $column, mixed $operatorOrValue, mixed $value = null): QueryBuilder
    {
        $instance = new static();
        $query = Database::table($instance->getTable());

        if (func_num_args() === 2) {
            return $query->where($column, $operatorOrValue);
        }

        return $query->where($column, $operatorOrValue, $value);
    }

    /**
     * Get all models from the database.
     *
     * @return array<int, static>
     */
    public static function all(): array
    {
        $instance = new static();
        $results = Database::table($instance->getTable())->get();

        return array_map(
            fn (array $row): self => self::hydrate($row),
            $results
        );
    }

    /**
     * Create a new model and save it to the database.
     *
     * @param array<string, mixed> $attributes
     * @return static
     */
    public static function create(array $attributes): self
    {
        $instance = new static($attributes);
        $instance->save();

        return $instance;
    }

    /**
     * Save the model to the database.
     */
    public function save(): bool
    {
        $data = $this->getDirty();

        if ($data === []) {
            return true;
        }

        if (!$this->exists) {
            // Insert new record
            $id = Database::table($this->getTable())->insert($data);
            
            if ($id !== 0) {
                $this->setAttribute('id', $id);
                $this->exists = true;
            }

            return true;
        }

        // Update existing record
        $affected = Database::table($this->getTable())
            ->where('id', '=', $this->getAttribute('id'))
            ->update($data);

        return $affected > 0;
    }

    /**
     * Update the model in the database.
     *
     * @param array<string, mixed> $attributes
     */
    public function update(array $attributes): bool
    {
        $this->fill($attributes);
        return $this->save();
    }

    /**
     * Delete the model from the database.
     */
    public function delete(): bool
    {
        if (!$this->exists) {
            return false;
        }

        $affected = Database::table($this->getTable())
            ->where('id', '=', $this->getAttribute('id'))
            ->delete();

        if ($affected > 0) {
            $this->exists = false;
            return true;
        }

        return false;
    }

    /**
     * Get the changed attributes.
     *
     * @return array<string, mixed>
     */
    private function getDirty(): array
    {
        return $this->attributes;
    }

    /**
     * Hydrate a model from an array.
     *
     * @param array<string, mixed> $attributes
     * @return static
     */
    public static function hydrate(array $attributes): self
    {
        $model = new static($attributes);
        $model->exists = true;

        return $model;
    }

    /**
     * Dynamically handle calls to the model.
     *
     * @param array<int, mixed> $parameters
     */
    public static function __callStatic(string $method, array $parameters): mixed
    {
        $instance = new static();

        if (method_exists($instance, $method)) {
            return $instance->$method(...$parameters);
        }

        throw new RuntimeException(sprintf('Method %s::%s does not exist.', static::class, $method));
    }
}

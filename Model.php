<?php

declare(strict_types=1);

namespace Siro\Core;

use PDO;
use RuntimeException;
use Siro\Core\DB\ModelQueryBuilder;
use Siro\Core\DB\QueryBuilder;
use Siro\Core\DB\Relations\BelongsTo;
use Siro\Core\DB\Relations\HasMany;

/**
 * Base Model class for Eloquent-style ORM operations.
 *
 * Provides CRUD, scopes, relationships (hasMany, belongsTo), soft deletes,
 * attribute casting, mass assignment protection, and automatic column
 * detection from the database schema.
 *
 * @package Siro\Core
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

        return self::hydrate($result);
    }

    /**
     * Start a new query builder instance.
     */
    public static function query(): ModelQueryBuilder
    {
        $instance = new static();
        return new ModelQueryBuilder($instance->getTable(), static::class);
    }

    /**
     * Begin querying the model.
     */
    public static function where(string $column, mixed $operatorOrValue, mixed $value = null): ModelQueryBuilder
    {
        $query = self::query();

        if (func_num_args() === 2) {
            return $query->where($column, $operatorOrValue);
        }

        return $query->where($column, $operatorOrValue, $value);
    }

    /**
     * Execute a query and get all results as Model instances.
     *
     * @return array<int, static>
     */
    public static function all(): array
    {
        return self::hydrateAll(self::query()->get());
    }

    /**
     * Execute a query and get the first result as a Model instance.
     *
     * @return static|null
     */
    public static function first(): ?self
    {
        $rows = self::query()->limit(1)->get();
        return isset($rows[0]) ? self::hydrate($rows[0]) : null;
    }

    /**
     * Get Model results from a query builder.
     *
     * @return array<int, static>
     */
    public static function get(): array
    {
        return self::hydrateAll(self::query()->get());
    }

    /**
     * Paginate the results with model hydration.
     *
     * @param int $perPage Number of items per page
     * @param int $page Current page number
     * @return array{data: array<int, static>, meta: array{page:int, per_page:int, total:int, last_page:int}}
     */
    public static function paginate(int $perPage = 15, int $page = 1): array
    {
        $query = self::query();

        $total = $query->count();
        $perPage = max(1, $perPage);
        $page = max(1, $page);
        $offset = ($page - 1) * $perPage;
        $lastPage = $total > 0 ? (int) ceil($total / $perPage) : 1;

        $data = self::hydrateAll($query->limit($perPage)->offset($offset)->get());

        return [
            'data' => $data,
            'meta' => [
                'page' => $page,
                'per_page' => $perPage,
                'total' => $total,
                'last_page' => $lastPage,
            ],
        ];
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
     * Find a model by its primary key or throw an exception.
     *
     * @return static
     * @throws RuntimeException
     */
    public static function findOrFail(int|string $id): self
    {
        $model = self::find($id);

        if ($model === null) {
            throw new RuntimeException(sprintf('Model not found with id %s', (string) $id));
        }

        return $model;
    }

    /**
     * Find the first record matching the attributes or create it.
     *
     * @param array<string, mixed> $attributes
     * @param array<string, mixed> $values
     * @return static
     */
    public static function firstOrCreate(array $attributes, array $values = []): self
    {
        $existing = self::findByAttributes($attributes);
        if ($existing !== null) {
            return $existing;
        }

        return self::create([...$attributes, ...$values]);
    }

    /**
     * Find the first record matching the attributes or instantiate a new instance.
     *
     * @param array<string, mixed> $attributes
     * @param array<string, mixed> $values
     * @return static
     */
    public static function firstOrNew(array $attributes, array $values = []): self
    {
        $existing = self::findByAttributes($attributes);
        if ($existing !== null) {
            return $existing;
        }

        return new static([...$attributes, ...$values]);
    }

    /**
     * Update or create a record matching the attributes.
     *
     * @param array<string, mixed> $attributes
     * @param array<string, mixed> $values
     * @return static
     */
    public static function updateOrCreate(array $attributes, array $values = []): self
    {
        $existing = self::findByAttributes($attributes);
        if ($existing !== null) {
            $existing->update($values);
            return $existing;
        }

        return self::create([...$attributes, ...$values]);
    }

    /**
     * Find a record by arbitrary attributes.
     *
     * @param array<string, mixed> $attributes
     * @return static|null
     */
    private static function findByAttributes(array $attributes): ?self
    {
        $query = self::query();
        foreach ($attributes as $column => $value) {
            $query->where($column, '=', $value);
        }

        $rows = $query->limit(1)->get();
        $row = $rows[0] ?? null;
        return $row !== null ? self::hydrate($row) : null;
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

        $table = $this->getTable();
        $isNew = !$this->exists;

        // Fire saving event (can cancel)
        if (!Event::emit("{$table}.saving", $this)) {
            return false;
        }

        if ($isNew) {
            // Fire creating event (can cancel)
            if (!Event::emit("{$table}.creating", $this)) {
                return false;
            }

            // Insert new record
            $id = Database::table($table)->insert($data);

            if ($id !== 0) {
                $this->setAttribute('id', $id);
                $this->exists = true;
            }

            Event::emit("{$table}.created", $this);
        } else {
            // Fire updating event (can cancel)
            if (!Event::emit("{$table}.updating", $this)) {
                return false;
            }

            // Update existing record
            $affected = Database::table($table)
                ->where('id', '=', $this->getAttribute('id'))
                ->update($data);

            if ($affected > 0) {
                Event::emit("{$table}.updated", $this);
            }
        }

        Event::emit("{$table}.saved", $this);

        return true;
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

        $table = $this->getTable();

        // Fire deleting event (can cancel)
        if (!Event::emit("{$table}.deleting", $this)) {
            return false;
        }

        $affected = Database::table($table)
            ->where('id', '=', $this->getAttribute('id'))
            ->delete();

        if ($affected > 0) {
            $this->exists = false;
            Event::emit("{$table}.deleted", $this);
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
     * Hydrate all rows into Model instances.
     *
     * @param array<int, array<string, mixed>> $rows
     * @return array<int, static>
     */
    public static function hydrateAll(array $rows): array
    {
        return array_map(fn (array $row): self => self::hydrate($row), $rows);
    }

    /**
     * Hydrate a model from an array (bypasses fillable protection).
     *
     * @param array<string, mixed> $attributes
     * @return static
     */
    public static function hydrate(array $attributes): self
    {
        $model = new static();
        $model->forceFill($attributes);
        $model->exists = true;

        return $model;
    }

    /**
     * Fill the model with an array of attributes (bypasses fillable protection).
     *
     * @param array<string, mixed> $attributes
     */
    public function forceFill(array $attributes): self
    {
        foreach ($attributes as $key => $value) {
            $this->setAttribute($key, $value);
        }

        return $this;
    }

    /**
     * Define a one-to-many relationship.
     */
    protected function hasMany(string $relatedClass, string $foreignKey = '', string $localKey = 'id'): HasMany
    {
        $related = new $relatedClass();

        if ($foreignKey === '') {
            $foreignKey = $this->getForeignKeyName($relatedClass);
        }

        return new HasMany(
            $relatedClass,
            $foreignKey,
            $localKey,
            $this->getAttribute($localKey) ?? 0,
        );
    }

    /**
     * Define an inverse one-to-many relationship (belongs to).
     */
    protected function belongsTo(string $relatedClass, string $foreignKey = '', string $ownerKey = 'id'): BelongsTo
    {
        if ($foreignKey === '') {
            $foreignKey = $this->getForeignKeyName(static::class);
        }

        return new BelongsTo(
            $relatedClass,
            $foreignKey,
            $ownerKey,
            $this->getAttribute($foreignKey) ?? 0,
        );
    }

    /**
     * Get the default foreign key name for a model.
     */
    private function getForeignKeyName(string $modelClass): string
    {
        $shortName = basename(str_replace('\\', '/', $modelClass));
        return strtolower(preg_replace('/(?<!^)[A-Z]/', '_$0', $shortName)) . '_id';
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

        // Check for scope method
        $scopeMethod = 'scope' . ucfirst($method);
        if (method_exists($instance, $scopeMethod)) {
            $query = self::query();
            $instance->{$scopeMethod}($query, ...$parameters);
            return $query;
        }

        throw new RuntimeException(sprintf('Method %s::%s does not exist.', static::class, $method));
    }
}

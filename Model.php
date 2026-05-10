<?php

declare(strict_types=1);

namespace Siro\Core;

use PDO;
use RuntimeException;
use Siro\Core\DB\ModelQueryBuilder;
use Siro\Core\DB\Relations\BelongsTo;
use Siro\Core\DB\Relations\BelongsToMany;
use Siro\Core\DB\Relations\HasMany;
use Siro\Core\DB\Relations\HasOne;

/**
 * Base Model class for Eloquent-style ORM operations.
 *
 * Provides CRUD, scopes, relationships (hasMany, hasOne, belongsTo, belongsToMany),
 * soft deletes, attribute casting, mass assignment protection, and automatic column
 * detection from the database schema.
 *
 * @package Siro\Core
 */
/** @implements \ArrayAccess<string, mixed> */
abstract class Model implements \JsonSerializable, \ArrayAccess
{
    /** @var string Table name (auto-detected if not set) */
    protected string $table = '';

    /** @var array<int, string> Hidden fields that should not be included in toArray() */
    protected array $hidden = [];

    /** @var array<string, string> Type casts for attributes */
    protected array $casts = [];

    /** @var array<int, string> Fillable fields for mass assignment */
    protected array $fillable = [];

    /** @var array<string, array<int, string>> Relations to eager-load with their columns */
    protected static array $eagerLoads = [];

    /** @var array<string, mixed> Cached relation results */
    private array $relations = [];

    /** @var array<string, mixed> Model attributes */
    private array $attributes = [];

    /** @var array<string, mixed> Original attribute values (for dirty tracking) */
    private array $original = [];

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
            if ($key !== '') {
                trigger_error(
                    sprintf(
                        'Mass assignment attempt on unprotected model [%s]: "%s" was discarded because $fillable is empty. '
                        . 'Set $fillable on your model or use forceFill() for explicit assignment.',
                        static::class,
                        $key
                    ),
                    E_USER_WARNING
                );
            }
            return false;
        }

        return in_array($key, $this->fillable, true);
    }

    /**
     * Set the fillable attributes.
     *
     * @param array<int, string> $fillable
     */
    public function setFillable(array $fillable): self
    {
        $this->fillable = $fillable;
        return $this;
    }

    /**
     * Set the hidden attributes.
     *
     * @param array<int, string> $hidden
     */
    public function setHidden(array $hidden): self
    {
        $this->hidden = $hidden;
        return $this;
    }

    /**
     * Set the attribute casts.
     *
     * @param array<string, string> $casts
     */
    public function setCasts(array $casts): self
    {
        $this->casts = $casts;
        return $this;
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

    public function offsetExists(mixed $offset): bool
    {
        return array_key_exists($offset, $this->attributes);
    }

    public function offsetGet(mixed $offset): mixed
    {
        return $this->getAttribute($offset);
    }

    public function offsetSet(mixed $offset, mixed $value): void
    {
        $this->setAttribute($offset, $value);
    }

    public function offsetUnset(mixed $offset): void
    {
        unset($this->attributes[$offset]);
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
     * Serialize the model to JSON (implements JsonSerializable).
     *
     * @return array<string, mixed>
     */
    public function jsonSerialize(): mixed
    {
        return $this->toArray();
    }

    /**
     * Find a model by its primary key.
     *
     * @return static|null
     */
    #[\ReturnTypeWillChange]
    public static function find(int|string $id): ?static
    {
        /** @phpstan-ignore new.static */
        $instance = new static();
        $table = $instance->getTable();

        /** @var array<string, mixed>|null $result */
        $result = Database::table($table)->where('id', '=', $id)->first();

        if ($result === null) {
            return null;
        }

        /** @var array<string, mixed> $result */
        return self::hydrate($result);
    }

    /**
     * @return static
     */
    private static function createInstance(): self
    {
        /** @phpstan-ignore new.static */
        return new static();
    }

    /**
     * Start a new query builder instance.
     */
    public static function query(): ModelQueryBuilder
    {
        $instance = self::createInstance();
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
     * @return array<int, self>
     */
    public static function all(): array
    {
        return self::query()->get();
    }

    /**
     * Add an order by clause to the query.
     */
    public static function orderBy(string $column, string $direction = 'asc'): ModelQueryBuilder
    {
        return self::query()->orderBy($column, $direction);
    }

    /**
     * Set the limit for the query.
     */
    public static function limit(int $value): ModelQueryBuilder
    {
        return self::query()->limit($value);
    }

    /**
     * Set the offset for the query.
     */
    public static function offset(int $value): ModelQueryBuilder
    {
        return self::query()->offset($value);
    }

    /**
     * Specify columns to select.
     * @param string ...$columns
     */
    public static function select(...$columns): ModelQueryBuilder
    {
        return self::query()->select(...$columns);
    }

    /**
     * Retrieve the count of records.
     */
    public static function count(string $column = '*'): int
    {
        return self::query()->count($column);
    }

    /**
     * Execute a query and get the first result as a Model instance.
     *
     * @return self|null
     */
    public static function first(): ?self
    {
        return self::query()->first();
    }

    /**
     * Get Model results from a query builder.
     *
     * @return array<int, self>
     */
    public static function get(): array
    {
        return self::query()->get();
    }

    /**
     * Paginate the results with model hydration.
     *
     * @param int $perPage Number of items per page
     * @param int $page Current page number
     * @return array{data: array<int, self>, meta: array{page:int, per_page:int, total:int, last_page:int}}
     */
    public static function paginate(int $perPage = 15, int $page = 1): array
    {
        $query = self::query();

        $total = $query->count();
        $perPage = max(1, $perPage);
        $page = max(1, $page);
        $offset = ($page - 1) * $perPage;
        $lastPage = $total > 0 ? (int) ceil($total / $perPage) : 1;

        $data = $query->limit($perPage)->offset($offset)->get();

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
     * Get the hidden attributes.
     *
     * @return array<int, string>
     */
    public function getHidden(): array
    {
        return $this->hidden;
    }

    /**
     * Create a new model and save it to the database.
     *
     * @param array<string, mixed> $attributes
     * @return static
     */
    public static function create(array $attributes): self
    {
        $instance = self::createInstance();
        $instance->fill($attributes);
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
            /** @var static $existing */
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
            /** @var static $existing */
            return $existing;
        }

        $instance = self::createInstance();
        $instance->fill([...$attributes, ...$values]);
        return $instance;
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
            /** @var static $existing */
            return $existing;
        }

        return self::create([...$attributes, ...$values]);
    }

    /**
     * Find a record by arbitrary attributes.
     *
     * @param array<string, mixed> $attributes
     * @return self|null
     */
    private static function findByAttributes(array $attributes): ?self
    {
        $query = self::query();
        foreach ($attributes as $column => $value) {
            $query->where($column, '=', $value);
        }

        $rows = $query->limit(1)->get();
        $row = $rows[0] ?? null;
        return $row;
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
        $this->syncOriginal();

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
     * Specify relations to eager load.
     *
     * Usage: User::with('posts')->find(1)
     *        User::with('posts', 'comments')
     *        User::with(['posts' => ['id', 'title']])
     *
     * @param string|array<string, array<int, string>> ...$relations
     */
    public static function with(...$relations): ModelQueryBuilder
    {
        $query = self::query();
        /** @var array<string, array<int, string>> $eagerLoads */
        $eagerLoads = [];

        foreach ($relations as $key => $value) {
            if (is_string($value)) {
                if (is_string($key)) {
                    $eagerLoads[$key] = ['*'];
                } else {
                    $eagerLoads[$value] = ['*'];
                }
            } elseif (is_array($value)) {
                /** @var array<int, string> $cols */
                $cols = $value;
                $eagerLoads[(string) $key] = $cols;
            }
        }

        foreach ($eagerLoads as $relation => $columns) {
            $query->eagerLoad($relation, $columns);
        }

        return $query;
    }

    /**
     * Eager load relations on the current model instances.
     *
     * @param string|array<string, array<int, string>> ...$relations
     */
    public function load(...$relations): self
    {
        /** @var array<string, array<int, string>> $eagerLoads */
        $eagerLoads = [];
        foreach ($relations as $key => $value) {
            if (is_string($value)) {
                if (is_string($key)) {
                    $eagerLoads[$key] = ['*'];
                } else {
                    $eagerLoads[$value] = ['*'];
                }
            } elseif (is_array($value)) {
                /** @var array<int, string> $cols */
                $cols = $value;
                $eagerLoads[(string) $key] = $cols;
            }
        }

        $eager = new \Siro\Core\DB\EagerLoader(static::class);
        $eager->load($this, $eagerLoads);

        return $this;
    }

    /**
     * Get a cached relation value.
     */
    public function getRelation(string $name): mixed
    {
        return $this->relations[$name] ?? null;
    }

    /**
     * Set a cached relation value.
     */
    public function setRelation(string $name, mixed $value): void
    {
        $this->relations[$name] = $value;
    }

    /**
     * Get the changed attributes (diff against original).
     *
     * @return array<string, mixed>
     */
    private function getDirty(): array
    {
        $dirty = [];
        foreach ($this->attributes as $key => $value) {
            if (!array_key_exists($key, $this->original) || $this->original[$key] !== $value) {
                $dirty[$key] = $value;
            }
        }
        return $dirty;
    }

    /**
     * Sync original attributes with current state (called after save/hydrate).
     */
    public function syncOriginal(): self
    {
        $this->original = $this->attributes;
        return $this;
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
        $model = self::createInstance();
        $model->forceFill($attributes);
        $model->syncOriginal();
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
        /** @var self $related */
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
     * Define a one-to-one relationship.
     */
    protected function hasOne(string $relatedClass, string $foreignKey = '', string $localKey = 'id'): HasOne
    {
        $related = new $relatedClass();

        if ($foreignKey === '') {
            $foreignKey = $this->getForeignKeyName($relatedClass);
        }

        return new HasOne(
            $relatedClass,
            $foreignKey,
            $localKey,
            $this->getAttribute($localKey) ?? 0,
        );
    }

    /**
     * Define a many-to-many relationship.
     *
     * @param string $relatedClass The related model class
     * @param string $pivotTable The pivot table name
     * @param string $foreignKey The foreign key in pivot table referencing this model
     * @param string $relatedKey The foreign key in pivot table referencing related model
     */
    protected function belongsToMany(
        string $relatedClass,
        string $pivotTable = '',
        string $foreignKey = '',
        string $relatedKey = 'id'
    ): BelongsToMany {
        $related = new $relatedClass();

        if ($pivotTable === '') {
            /** @var self $related */
            $tables = [$this->getTable(), $related->getTable()];
            sort($tables);
            $pivotTable = $tables[0] . '_' . $tables[1];
        }

        if ($foreignKey === '') {
            $foreignKey = $this->getForeignKeyName(static::class);
        }

        return new BelongsToMany(
            $relatedClass,
            $pivotTable,
            $foreignKey,
            $relatedKey,
            'id',
            $this->getAttribute('id') ?? 0,
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
        $instance = self::createInstance();

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

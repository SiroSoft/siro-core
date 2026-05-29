<?php

declare(strict_types=1);

namespace Siro\Core;

use RuntimeException;
use Siro\Core\DB\ModelQueryBuilder;
use Siro\Core\ModelNotFoundException;

/** @implements \ArrayAccess<string, mixed> */
abstract class Model implements \JsonSerializable, \ArrayAccess
{
    use ModelSerialization;
    use ModelRelations;

    protected string $table = '';
    /** @var array<int, string> */
    protected array $hidden = [];
    /** @var array<string, string> */
    protected array $casts = [];
    /** @var array<int, string> */
    protected array $fillable = [];
    /** @var array<string, array<int, string>> */
    protected static array $eagerLoads = [];
    /** @var class-string<\Siro\Core\Observers\ModelObserver>[] */
    protected static array $observers = [];

    /** @var array<string, mixed> */
    private array $relations = [];
    /** @var array<string, mixed> */
    private array $attributes = [];
    /** @var array<string, mixed> */
    private array $original = [];
    private bool $exists = false;
    protected string $primaryKey = 'id';
    protected bool $timestamps = true;

    /** @var array<string, array<int|string, static>> */
    protected static array $identityMap = [];

    /** @var array<string, int> */
    private static array $relationAccessCount = [];
    private static bool $nPlusOneWarned = false;

    private const N_PLUS_ONE_THRESHOLD = 2;

    /** @param array<string, mixed> $attributes */
    public function __construct(array $attributes = [])
    {
        $this->fill($attributes);
    }

    public function getTable(): string
    {
        if ($this->table !== '') {
            return $this->table;
        }

        $className = basename(str_replace('\\', '/', static::class));
        $replaced = preg_replace('/(?<!^)[A-Z]/', '_$0', $className) ?? $className;
        return strtolower($replaced) . 's';
    }

    public function getKeyName(): string
    {
        return $this->primaryKey;
    }

    /** @param class-string<\Siro\Core\Observers\ModelObserver> $observerClass */
    public static function observe(string $observerClass): void
    {
        if (!in_array($observerClass, static::$observers, true)) {
            static::$observers[] = $observerClass;
        }
    }

    private function notifyObservers(string $hook): void
    {
        if (static::$observers === []) {
            return;
        }
        foreach (static::$observers as $class) {
            if (class_exists($class) && method_exists($class, $hook)) {
                $observer = new $class();
                $observer->{$hook}($this);
            }
        }
    }

    /** @param array<string, mixed> $attributes */
    public function fill(array $attributes): self
    {
        foreach ($attributes as $key => $value) {
            if ($this->isFillable($key)) {
                $this->setAttribute($key, $value);
            }
        }

        return $this;
    }

    private function isFillable(string $key): bool
    {
        if ($this->fillable === []) {
            if ($key !== '' && !\Siro\Core\Env::bool('APP_DEBUG', false)) {
                return false;
            }
            if ($key !== '') {
                trigger_error(
                    sprintf(
                        'Mass assignment attempt on unprotected model [%s]: "%s" was discarded because $fillable is empty.',
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

    /** @param array<int, string> $fillable */
    public function setFillable(array $fillable): self
    {
        $this->fillable = $fillable;
        return $this;
    }

    public function setAttribute(string $key, mixed $value): void
    {
        if ($this->fillable !== [] && !in_array($key, $this->fillable, true)) {
            return;
        }
        // Check for mutator method first
        $mutatorMethod = 'set' . Str::studly($key) . 'Attribute';
        if (method_exists($this, $mutatorMethod)) {
            // Call mutator directly - it should handle setting the value
            $this->{$mutatorMethod}($value);
            return;
        }

        $this->attributes[$key] = $value;
    }

    public function getAttribute(string $key): mixed
    {
        // Check for accessor method first
        $accessorMethod = 'get' . Str::studly($key) . 'Attribute';
        if (method_exists($this, $accessorMethod)) {
            return $this->{$accessorMethod}($this->attributes[$key] ?? null);
        }

        if (!array_key_exists($key, $this->attributes)) {
            return null;
        }

        $value = $this->attributes[$key];

        if (isset($this->casts[$key])) {
            return $this->castAttribute($key, $value);
        }

        return $value;
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
        $this->setAttribute(strval($offset), $value);
    }

    public function offsetUnset(mixed $offset): void
    {
        unset($this->attributes[$offset]);
    }

    public function __get(string $name): mixed
    {
        return $this->getAttribute($name);
    }

    public function __set(string $name, mixed $value): void
    {
        $this->setAttribute($name, $value);
    }

    public function __isset(string $name): bool
    {
        return $this->offsetExists($name);
    }

    /** @return static|null */
    /** @phpstan-return static|null */
    public static function find(int|string $id): ?static
    {
        if (!isset(static::$identityMap[static::class])) {
            static::$identityMap[static::class] = [];
        }
        $map = &static::$identityMap[static::class];
        if (isset($map[$id])) {
            return $map[$id];
        }

        /** @phpstan-ignore new.static (safe because Model is abstract) */
        $instance = new static();
        $key = $instance->getKeyName();
        /** @var ?static $result */
        $result = $instance->query()->where($key, '=', $id)->first();

        if ($result === null) {
            return null;
        }

        if (count($map) > 1000) {
            $map = array_slice($map, -800, null, true);
        }
        $map[$id] = $result;
        return $result;
    }

    /** @return static */
    private static function createInstance(): static
    {
        /** @phpstan-ignore new.static (safe because Model is abstract) */
        return new static();
    }

    public static function query(): ModelQueryBuilder
    {
        $instance = self::createInstance();
        return new ModelQueryBuilder($instance->getTable(), static::class);
    }

    public static function where(string $column, mixed $operatorOrValue, mixed $value = null): ModelQueryBuilder
    {
        $query = self::query();

        if (func_num_args() === 2) {
            return $query->where($column, $operatorOrValue);
        }

        return $query->where($column, $operatorOrValue, $value);
    }

    /** @return array<int, self> */
    public static function all(): array
    {
        return self::query()->get();
    }

    public static function orderBy(string $column, string $direction = 'asc'): ModelQueryBuilder
    {
        return self::query()->orderBy($column, $direction);
    }

    public static function limit(int $value): ModelQueryBuilder
    {
        return self::query()->limit($value);
    }

    public static function offset(int $value): ModelQueryBuilder
    {
        return self::query()->offset($value);
    }

    /** @param string ...$columns */
    public static function select(string ...$columns): ModelQueryBuilder
    {
        return self::query()->select(...$columns);
    }

    public static function count(string $column = '*'): int
    {
        return self::query()->count($column);
    }

    /** @return self|null */
    public static function first(): ?self
    {
        return self::query()->first();
    }

    /** @return array<int, self> */
    public static function get(): array
    {
        return self::query()->get();
    }

    /**
     * Memory-efficient iteration over all matching records.
     *
     * Yields one Model at a time without loading all into memory.
     * Usage: foreach (User::where('active', 1)->cursor() as $user) { ... }
     *
     * @return \Generator<int, self>
     */
    public static function cursor(): \Generator
    {
        return self::query()->cursor();
    }

    /**
     * @return array{data: array<int, self>, meta: array{page: int, per_page: int, total: int, last_page: int}}
     */
    public static function paginate(int $perPage = 15, int $page = 1): array
    {
        $query = self::query();
        $perPage = max(1, $perPage);
        $page = max(1, $page);
        $offset = ($page - 1) * $perPage;

        // Clone query for count to avoid state mutation
        $countQuery = clone $query;
        $total = $countQuery->count();
        $lastPage = $total > 0 ? (int) ceil($total / $perPage) : 1;

        /** @var array<int, self> $data */
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
     * Reload the model from the database.
     */
    public function refresh(): self
    {
        $key = $this->getKeyName();
        $id = $this->getAttribute($key);
        if ($id === null || !$this->exists) {
            return $this;
        }

        $fresh = static::query()->where($key, '=', $id)->first();
        if ($fresh !== null) {
            $this->attributes = $fresh->attributes;
            $this->original = $fresh->original;
            $this->relations = $fresh->relations;
            $this->exists = $fresh->exists;
        }

        return $this;
    }

    /**
     * Get a fresh instance from the database.
     */
    public function fresh(): ?static
    {
        $key = $this->getKeyName();
        $id = $this->getAttribute($key);
        if ($id === null || !$this->exists) {
            return null;
        }

        /** @var ?static $result */
        $result = static::query()->where($key, '=', $id)->first();
        return $result;
    }

    public function loadMissing(mixed ...$relations): self
    {
        $toLoad = [];
        foreach ($relations as $key => $value) {
            if (is_string($value)) {
                $name = is_string($key) ? $key : $value;
                if (!array_key_exists($name, $this->relations)) {
                    $toLoad[] = $value;
                }
            } elseif (is_array($value) && is_string($key)) {
                if (!array_key_exists($key, $this->relations)) {
                    $toLoad[$key] = $value;
                }
            }
        }

        if ($toLoad !== []) {
            $this->load(...$toLoad);
        }

        return $this;
    }

    /**
     * @param string|array<int, string> ...$attributes
     */
    public function append(string|array ...$attributes): self
    {
        foreach ($attributes as $attr) {
            if (is_string($attr) && !in_array($attr, $this->appends, true)) {
                $this->appends[] = $attr;
            } elseif (is_array($attr)) {
                foreach ($attr as $a) {
                    if (!in_array($a, $this->appends, true)) {
                        $this->appends[] = $a;
                    }
                }
            }
        }

        return $this;
    }

    /**
     * Get only the specified attributes.
     *
     * @param array<int, string> $keys
     * @return array<string, mixed>
     */
    public function only(array $keys): array
    {
        $result = [];
        foreach ($keys as $key) {
            $result[$key] = $this->getAttribute($key);
        }
        return $result;
    }

    /**
     * Get a new query builder without certain eager loads.
     *
     * @param mixed ...$relations
     */
    public static function without(mixed ...$relations): ModelQueryBuilder
    {
        return self::query();
    }

    /** @param array<string, mixed> $attributes */
    public static function create(array $attributes): static
    {
        $instance = self::createInstance();
        $instance->fill($attributes);
        $instance->save();

        return $instance;
    }

    /** @return static */
    public static function findOrFail(int|string $id): static
    {
        $model = self::find($id);

        if ($model === null) {
            throw new ModelNotFoundException(static::class, $id);
        }

        return $model;
    }

    /**
     * @param array<string, mixed> $attributes
     * @param array<string, mixed> $values
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
     * @param array<string, mixed> $attributes
     * @param array<string, mixed> $values
     */
    public static function firstOrNew(array $attributes, array $values = []): self
    {
        $existing = self::findByAttributes($attributes);
        if ($existing !== null) {
            return $existing;
        }

        $instance = self::createInstance();
        $instance->fill([...$attributes, ...$values]);
        return $instance;
    }

    /**
     * @param array<string, mixed> $attributes
     * @param array<string, mixed> $values
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

    /** @param array<string, mixed> $attributes */
    private static function findByAttributes(array $attributes): ?self
    {
        $query = self::query();
        foreach ($attributes as $column => $value) {
            $query->where($column, '=', $value);
        }

        $rows = $query->limit(1)->get();
        return $rows[0] ?? null;
    }

    public function save(): bool
    {
        $data = $this->getDirty();

        // Auto-set timestamps
        if ($this->timestamps) {
            $now = date('Y-m-d H:i:s');
            if (!$this->exists && !isset($data['created_at'])) {
                $data['created_at'] = $now;
                $this->attributes['created_at'] = $now;
            }
            if (!isset($data['updated_at'])) {
                $data['updated_at'] = $now;
                $this->attributes['updated_at'] = $now;
            }
        }

        if ($data === []) {
            return true;
        }

        $table = $this->getTable();
        $isNew = !$this->exists;
        $key = $this->getKeyName();

        $hasObservers = static::$observers !== [];

        if ($hasObservers) {
            $this->notifyObservers('saving');
        }
        if (!Event::emit("{$table}.saving", $this)) {
            return false;
        }

        if ($isNew) {
            if ($hasObservers) {
                $this->notifyObservers('creating');
            }
            if (!Event::emit("{$table}.creating", $this)) {
                return false;
            }

            $id = Database::table($table)->insert($data);

            if ($id !== 0) {
                $this->setAttribute($key, $id);
                $this->exists = true;
            }

            if ($hasObservers) {
                $this->notifyObservers('created');
            }
            Event::emit("{$table}.created", $this);
        } else {
            if ($hasObservers) {
                $this->notifyObservers('updating');
            }
            if (!Event::emit("{$table}.updating", $this)) {
                return false;
            }

            $affected = Database::table($table)
                ->where($key, '=', $this->getAttribute($key))
                ->update($data);

            if ($affected > 0) {
                if ($hasObservers) {
                    $this->notifyObservers('updated');
                }
                Event::emit("{$table}.updated", $this);
            }
        }

        if ($hasObservers) {
            $this->notifyObservers('saved');
        }
        Event::emit("{$table}.saved", $this);
        $this->syncOriginal();

        return true;
    }

    /** @param array<string, mixed> $attributes */
    public function update(array $attributes): bool
    {
        $this->fill($attributes);
        return $this->save();
    }

    public function delete(): bool
    {
        if (!$this->exists) {
            return false;
        }

        $table = $this->getTable();
        $key = $this->getKeyName();

        $this->notifyObservers('deleting');
        if (!Event::emit("{$table}.deleting", $this)) {
            return false;
        }

        $affected = Database::table($table)
            ->where($key, '=', $this->getAttribute($key))
            ->delete();

        if ($affected > 0) {
            $this->exists = false;
            $this->notifyObservers('deleted');
            Event::emit("{$table}.deleted", $this);
            return true;
        }

        return false;
    }

    public function forceDelete(): bool
    {
        $result = $this->delete();
        if ($result) {
            $this->notifyObservers('forceDeleted');
        }
        return $result;
    }

    /**
     * @param string|array<string, array<int, string>> ...$relations
     */
    public static function with(mixed ...$relations): ModelQueryBuilder
    {
        $query = self::query();

        foreach ($relations as $key => $value) {
            if (is_string($value)) {
                if (is_string($key)) {
                    $query->eagerLoad($key, ['*']);
                } else {
                    $query->eagerLoad($value, ['*']);
                }
            } elseif (is_array($value)) {
                /** @var array<int, string> $value */
                $query->eagerLoad((string) $key, $value);
            }
        }

        return $query;
    }

    /**
     * Load relation counts into the model.
     *
     * Usage:
     *   $user->loadCount('posts');
     *   $user->loadCount(['posts', 'comments']);
     *
     * @param string|array<int|string, (callable(\Siro\Core\DB\ModelQueryBuilder): void)|string> ...$relations
     */
    public function loadCount(string|array ...$relations): self
    {
        $counts = [];
        foreach ($relations as $rel) {
            if (is_string($rel)) {
                $counts[$rel] = ['alias' => $rel, 'callback' => null];
            } elseif (is_array($rel)) {
                foreach ($rel as $key => $value) {
                    if (is_string($key) && is_callable($value)) {
                        $counts[$key] = ['alias' => $key, 'callback' => $value];
                    } elseif (is_int($key) && is_string($value)) {
                        $counts[$value] = ['alias' => $value, 'callback' => null];
                    }
                }
            }
        }

        $q = static::query();
        $q->withCounts = $counts;
        $q->loadCountsIntoModels([$this]);

        return $this;
    }

    public function load(mixed ...$relations): self
    {
        $eager = new \Siro\Core\DB\EagerLoader(static::class);
        foreach ($relations as $key => $value) {
            if (is_string($value)) {
                if (is_string($key)) {
                    $eager->load($this, [$key => ['*']]);
                } else {
                    $eager->load($this, [$value => ['*']]);
                }
            } elseif (is_array($value)) {
                /** @var array<int, string> $value */
                $eager->load($this, [(string) $key => $value]);
            }
        }

        return $this;
    }

    public function getRelation(string $name): mixed
    {
        if (!array_key_exists($name, $this->relations) && !self::$nPlusOneWarned) {
            $class = static::class;
            self::$relationAccessCount[$class . '::' . $name] = (self::$relationAccessCount[$class . '::' . $name] ?? 0) + 1;
            if (self::$relationAccessCount[$class . '::' . $name] >= self::N_PLUS_ONE_THRESHOLD) {
                self::$nPlusOneWarned = true;
                $msg = "N+1 detected: {$class}::{$name} accessed " . self::$relationAccessCount[$class . '::' . $name] . " times without eager loading. Use Model::with('{$name}') to prevent N+1.";
                \Siro\Core\Logger::debug($msg);
            }
        }
        return $this->relations[$name] ?? null;
    }

    public static function resetRelationAccessCount(): void
    {
        self::$relationAccessCount = [];
        self::$nPlusOneWarned = false;
    }

    /** @return array<string, int> */
    public static function getRelationAccessCount(): array
    {
        return self::$relationAccessCount;
    }

    public function setRelation(string $name, mixed $value): void
    {
        $this->relations[$name] = $value;
    }

    /** @return array<string, mixed> */
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

    public function syncOriginal(): self
    {
        $this->original = $this->attributes;
        return $this;
    }

    public static function clearIdentityMap(): void
    {
        static::$identityMap = [];
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @return array<int, self>
     */
    public static function hydrateAll(array $rows): array
    {
        return array_map(fn (array $row): self => self::hydrate($row), $rows);
    }

    /** @param array<string, mixed> $attributes */
    public static function hydrate(array $attributes): self
    {
        $model = self::createInstance();
        $model->forceFill($attributes);
        $model->syncOriginal();
        $model->exists = true;

        return $model;
    }

    /** @param array<string, mixed> $attributes */
    protected function forceFill(array $attributes): self
    {
        foreach ($attributes as $key => $value) {
            // Bypass mutators by setting directly
            $this->attributes[$key] = $value;
        }

        return $this;
    }

    /**
     * @param array<int, mixed> $parameters
     */
    public static function __callStatic(string $method, array $parameters): mixed
    {
        $instance = self::createInstance();

        if (method_exists($instance, $method)) {
            return $instance->$method(...$parameters);
        }

        $scopeMethod = 'scope' . ucfirst($method);
        if (method_exists($instance, $scopeMethod)) {
            $query = self::query();
            $instance->{$scopeMethod}($query, ...$parameters);
            return $query;
        }

        if (method_exists(ModelQueryBuilder::class, $method)) {
            return self::query()->$method(...$parameters);
        }

        throw new RuntimeException(sprintf('Method %s::%s does not exist.', static::class, $method));
    }
}

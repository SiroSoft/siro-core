<?php

declare(strict_types=1);

namespace Siro\Core;

use RuntimeException;
use Siro\Core\DB\ModelQueryBuilder;

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

    /** @var array<string, mixed> */
    private array $relations = [];
    /** @var array<string, mixed> */
    private array $attributes = [];
    /** @var array<string, mixed> */
    private array $original = [];
    private bool $exists = false;

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
        return strtolower(preg_replace('/(?<!^)[A-Z]/', '_$0', $className)) . 's';
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
        $this->attributes[$key] = $value;
    }

    public function getAttribute(string $key): mixed
    {
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
        $this->setAttribute($offset, $value);
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
    public static function find(int|string $id): ?static
    {
        $instance = new static();
        $result = $instance->query()->where('id', '=', $id)->first();

        if ($result === null) {
            return null;
        }

        return $result;
    }

    private static function createInstance(): self
    {
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
     * @return array{data: array<int, self>, meta: array{page: int, per_page: int, total: int, last_page: int}}
     */
    public static function paginate(int $perPage = 15, int $page = 1): array
    {
        $query = self::query();

        $total = $query->count();
        $perPage = max(1, $perPage);
        $page = max(1, $page);
        $offset = ($page - 1) * $perPage;
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
            throw new RuntimeException(sprintf('Model not found with id %s', (string) $id));
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

        if ($data === []) {
            return true;
        }

        $table = $this->getTable();
        $isNew = !$this->exists;

        if (!Event::emit("{$table}.saving", $this)) {
            return false;
        }

        if ($isNew) {
            if (!Event::emit("{$table}.creating", $this)) {
                return false;
            }

            $id = Database::table($table)->insert($data);

            if ($id !== 0) {
                $this->setAttribute('id', $id);
                $this->exists = true;
            }

            Event::emit("{$table}.created", $this);
        } else {
            if (!Event::emit("{$table}.updating", $this)) {
                return false;
            }

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
     * @param string|array<string, array<int, string>> ...$relations
     */
    public static function with(mixed ...$relations): ModelQueryBuilder
    {
        $query = self::query();
        $eagerLoads = [];

        foreach ($relations as $key => $value) {
            if (is_string($value)) {
                if (is_string($key)) {
                    $eagerLoads[$key] = ['*'];
                } else {
                    $eagerLoads[$value] = ['*'];
                }
            } elseif (is_array($value)) {
                $eagerLoads[(string) $key] = $value;
            }
        }

        foreach ($eagerLoads as $relation => $columns) {
            $query->eagerLoad($relation, $columns);
        }

        return $query;
    }

    /**
     * @param string|array<string, array<int, string>> ...$relations
     */
    public function load(mixed ...$relations): self
    {
        $eagerLoads = [];
        foreach ($relations as $key => $value) {
            if (is_string($value)) {
                if (is_string($key)) {
                    $eagerLoads[$key] = ['*'];
                } else {
                    $eagerLoads[$value] = ['*'];
                }
            } elseif (is_array($value)) {
                $eagerLoads[(string) $key] = $value;
            }
        }

        $eager = new \Siro\Core\DB\EagerLoader(static::class);
        $eager->load($this, $eagerLoads);

        return $this;
    }

    public function getRelation(string $name): mixed
    {
        return $this->relations[$name] ?? null;
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

    /**
     * @param array<int, array<string, mixed>> $rows
     * @return array<int, static>
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
    public function forceFill(array $attributes): self
    {
        foreach ($attributes as $key => $value) {
            $this->setAttribute($key, $value);
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

        throw new RuntimeException(sprintf('Method %s::%s does not exist.', static::class, $method));
    }
}

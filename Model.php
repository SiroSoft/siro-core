<?php

declare(strict_types=1);

namespace Siro\Core;

use RuntimeException;
use Siro\Core\DB\ModelQueryBuilder;
use Siro\Core\ModelNotFoundException;

/**
 * @implements \ArrayAccess<string, mixed>
 * @property int $id
 */
abstract class Model implements \JsonSerializable, \ArrayAccess
{
    use ModelSerialization;
    use ModelRelations;

    protected string $table = '';
    /** @var array<int, string> */
    protected array $hidden = [];
    /** @var array<int, string> */
    protected array $fillable = [];
    /** @var array<string, array<int, string>> */
    protected static array $eagerLoads = [];

    /** @var array<int, string> Relations to auto-eager-load on every query */
    protected array $with = [];

    /** @var array<string, string> */
    protected array $casts = [
        'id' => 'int',
    ];

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

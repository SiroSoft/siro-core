<?php

declare(strict_types=1);

namespace Siro\Core;

use Closure;
use ReflectionClass;
use ReflectionNamedType;
use RuntimeException;

final class Container
{
    private const MAX_CIRCULAR_DEPTH = 64;
    private static ?Container $instance = null;

    /** @var array<string, array{reflectionClass: ReflectionClass<object>, constructorParams: array<int, \ReflectionParameter>|null}> */
    private static array $reflectionCache = [];
    /** @var array<string, (callable(): mixed)|class-string|string> */
    private array $bindings = [];
    /** @var array<string, true> */
    private array $singletons = [];
    /** @var array<string, object> */
    private array $instances = [];
    /** @var array<string, object> */
    private array $resolved = [];
    /** @var array<string, int> */
    private array $resolvingStack = [];
    /** @var array<string, array<int, string>> */
    private array $tags = [];
    /** @var array<string, array<int, \Closure>> */
    private array $reboundCallbacks = [];
    /** @var array<string, array<string, \Closure>> */
    private array $contextual = [];

    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public static function setInstance(?self $container): void
    {
        self::$instance = $container;
    }

    public function bind(string $abstract, callable|string|null $concrete = null): void
    {
        $this->bindings[$abstract] = $concrete ?? $abstract;
    }

    public function singleton(string $abstract, callable|string|null $concrete = null): void
    {
        $this->bindings[$abstract] = $concrete ?? $abstract;
        $this->singletons[$abstract] = true;
    }

    public function instance(string $abstract, object $instance): void
    {
        $this->instances[$abstract] = $instance;
        $this->singletons[$abstract] = true;
    }

    public function make(string $abstract, string $for = ''): object
    {
        if ($for !== '' && isset($this->contextual[$for][$abstract])) {
            $closure = $this->contextual[$for][$abstract];
            $resolved = $closure($this);
            if (is_object($resolved)) {
                return $resolved;
            }
        }

        if (isset($this->instances[$abstract])) {
            return $this->instances[$abstract];
        }

        if (isset($this->resolved[$abstract]) && isset($this->singletons[$abstract])) {
            return $this->resolved[$abstract];
        }

        $concrete = $this->bindings[$abstract] ?? $abstract;

        if ($concrete instanceof Closure) {
            /** @var object $object */
            $object = $concrete($this);
        } elseif (is_string($concrete)) {
            /** @var class-string $concrete */
            $object = $this->resolve($concrete);
        } else {
            throw new RuntimeException("Cannot resolve [{$abstract}].");
        }

        if (isset($this->singletons[$abstract])) {
            $this->resolved[$abstract] = $object;
            $this->fireRebound($abstract, $object);
        }

        return $object;
    }

    private function fireRebound(string $abstract, object $instance): void
    {
        $callbacks = $this->reboundCallbacks[$abstract] ?? [];
        foreach ($callbacks as $callback) {
            $callback($this, $instance);
        }
    }

    /** @param array<int, mixed> $parameters */
    private function callBoundCallback(callable $callback, array $parameters): mixed
    {
        return $callback(...$parameters);
    }

    public function has(string $abstract): bool
    {
        return isset($this->bindings[$abstract])
            || isset($this->instances[$abstract])
            || isset($this->resolved[$abstract]);
    }

    /**
     * @param array<int, mixed>|string $callable
     * @param array<int, mixed> $parameters
     */
    public function call(string|array $callable, array $parameters = []): mixed
    {
        if (is_string($callable)) {
            $parts = explode('@', $callable, 2);
            if (count($parts) === 2) {
                [$class, $method] = $parts;
                $instance = $this->make($class);
                return $instance->{$method}(...$parameters);
            }
            throw new RuntimeException('Invalid string callable. Use Class@method.');
        }

        if (count($callable) === 2) {
            [$class, $method] = $callable;
            $instance = is_object($class) ? $class : $this->make(is_string($class) ? $class : '');
            return $instance->{$method}(...$parameters);
        }

        throw new RuntimeException('Invalid callable.');
    }

    public function tag(string $abstract, string ...$tags): void
    {
        $normalized = $tags !== [] ? $tags : [$abstract];
        foreach ($normalized as $tag) {
            $this->tags[$tag][] = $abstract;
        }
    }

    /** @return array<int, object> */
    public function tagged(string $tag): array
    {
        $abstracts = $this->tags[$tag] ?? [];
        $result = [];
        foreach ($abstracts as $abstract) {
            if ($this->has($abstract) || class_exists($abstract)) {
                $result[] = $this->make($abstract);
            }
        }
        return $result;
    }

    public function rebound(string $abstract, \Closure $callback): void
    {
        $this->reboundCallbacks[$abstract][] = $callback;
    }

    /** @param array<string, \Closure> $concrete */
    public function when(string $class, array $concrete): void
    {
        foreach ($concrete as $abstract => $closure) {
            $this->contextual[$class][$abstract] = $closure;
        }
    }

    public function clear(): void
    {
        $this->bindings = [];
        $this->singletons = [];
        $this->instances = [];
        $this->resolved = [];
        $this->tags = [];
        $this->reboundCallbacks = [];
        $this->contextual = [];
    }

    /** @param class-string $class */
    private function resolve(string $class): object
    {
        $depth = ($this->resolvingStack[$class] ?? 0);
        if ($depth >= self::MAX_CIRCULAR_DEPTH) {
            $chain = implode(' -> ', array_keys($this->resolvingStack));
            throw new RuntimeException("Circular dependency detected: {$chain} -> {$class}");
        }

        $this->resolvingStack[$class] = $depth + 1;

        try {
            if (isset(self::$reflectionCache[$class])) {
                $cacheEntry = self::$reflectionCache[$class];
                $ref = $cacheEntry['reflectionClass'];
                $constructorParams = $cacheEntry['constructorParams'];
            } else {
                $ref = new ReflectionClass($class);

                if (!$ref->isInstantiable()) {
                    throw new RuntimeException("Class [{$class}] is not instantiable.");
                }

                $constructor = $ref->getConstructor();
                $constructorParams = $constructor !== null ? $constructor->getParameters() : null;
                self::$reflectionCache[$class] = [
                    'reflectionClass' => $ref,
                    'constructorParams' => $constructorParams,
                ];
            }

            if ($constructorParams === null) {
                return $ref->newInstance();
            }

            $deps = [];
            foreach ($constructorParams as $param) {
                $type = $param->getType();

                if ($type === null) {
                    $deps[] = $param->isDefaultValueAvailable() ? $param->getDefaultValue() : null;
                    continue;
                }

                if ($type instanceof ReflectionNamedType) {
                    if ($type->isBuiltin()) {
                        $deps[] = $param->isDefaultValueAvailable() ? $param->getDefaultValue() : null;
                        continue;
                    }

                    $typeName = $type->getName();

                    if ($typeName === self::class) {
                        $deps[] = $this;
                        continue;
                    }

                    try {
                        $deps[] = $this->make($typeName);
                    } catch (RuntimeException $e) {
                        if ($param->isDefaultValueAvailable()) {
                            $deps[] = $param->getDefaultValue();
                        } elseif ($type->allowsNull()) {
                            $deps[] = null;
                        } else {
                            throw $e;
                        }
                    }
                } else {
                    $deps[] = $param->isDefaultValueAvailable() ? $param->getDefaultValue() : null;
                }
            }

            return $ref->newInstanceArgs($deps);
        } finally {
            unset($this->resolvingStack[$class]);
        }
    }
}

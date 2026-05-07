<?php

declare(strict_types=1);

namespace Siro\Core;

use Closure;
use ReflectionClass;
use ReflectionNamedType;
use RuntimeException;

final class Container
{
    private static ?Container $instance = null;
    /** @var array<string, callable|class-string> */
    private array $bindings = [];
    /** @var array<string, true> */
    private array $singletons = [];
    /** @var array<string, object> */
    private array $instances = [];
    /** @var array<string, object> */
    private array $resolved = [];

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

    public function make(string $abstract): object
    {
        if (isset($this->instances[$abstract])) {
            return $this->instances[$abstract];
        }

        if (isset($this->resolved[$abstract]) && isset($this->singletons[$abstract])) {
            return $this->resolved[$abstract];
        }

        $concrete = $this->bindings[$abstract] ?? $abstract;

        if ($concrete instanceof Closure) {
            $object = $concrete($this);
        } elseif (is_string($concrete)) {
            $object = $this->resolve($concrete);
        } else {
            throw new RuntimeException("Cannot resolve [{$abstract}].");
        }

        if (isset($this->singletons[$abstract])) {
            $this->resolved[$abstract] = $object;
        }

        return $object;
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
            $instance = is_object($class) ? $class : $this->make($class);
            return $instance->{$method}(...$parameters);
        }

        throw new RuntimeException('Invalid callable.');
    }

    public function clear(): void
    {
        $this->bindings = [];
        $this->singletons = [];
        $this->instances = [];
        $this->resolved = [];
    }

    private function resolve(string $class): object
    {
        $ref = new ReflectionClass($class);

        if (!$ref->isInstantiable()) {
            throw new RuntimeException("Class [{$class}] is not instantiable.");
        }

        $constructor = $ref->getConstructor();

        if ($constructor === null) {
            return $ref->newInstance();
        }

        $deps = [];
        foreach ($constructor->getParameters() as $param) {
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
    }
}

<?php

declare(strict_types=1);

namespace App\Support;

use Closure;
use Psr\Container\ContainerInterface;
use RuntimeException;

/**
 * Minimal service container: each id maps to a factory that runs once.
 * All wiring lives in config/container.php so it can be read top to bottom.
 */
final class Container implements ContainerInterface
{
    /** @var array<string, Closure(self): mixed> */
    private array $factories = [];

    /** @var array<string, mixed> */
    private array $instances = [];

    /**
     * @param Closure(self): mixed $factory
     */
    public function set(string $id, Closure $factory): void
    {
        $this->factories[$id] = $factory;
        unset($this->instances[$id]);
    }

    public function instance(string $id, mixed $value): void
    {
        $this->instances[$id] = $value;
    }

    public function has(string $id): bool
    {
        return array_key_exists($id, $this->instances) || isset($this->factories[$id]);
    }

    public function get(string $id): mixed
    {
        if (array_key_exists($id, $this->instances)) {
            return $this->instances[$id];
        }
        if (!isset($this->factories[$id])) {
            throw new class ("No service registered for \"$id\".") extends RuntimeException implements
                \Psr\Container\NotFoundExceptionInterface {
            };
        }

        return $this->instances[$id] = ($this->factories[$id])($this);
    }

    /**
     * Typed accessor so callers get a concrete class back instead of mixed.
     *
     * @template T of object
     * @param class-string<T> $class
     * @return T
     */
    public function make(string $class): object
    {
        $service = $this->get($class);
        if (!$service instanceof $class) {
            throw new RuntimeException("Service \"$class\" has an unexpected type.");
        }

        return $service;
    }
}

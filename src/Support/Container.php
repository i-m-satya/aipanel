<?php

declare(strict_types=1);

namespace AIPanel\Support;

/**
 * Tiny service locator: lazy singletons keyed by class/interface name.
 */
final class Container
{
    /** @var array<string,callable> */
    private array $factories = [];

    /** @var array<string,mixed> */
    private array $instances = [];

    public function set(string $id, callable $factory): void
    {
        $this->factories[$id] = $factory;
        unset($this->instances[$id]);
    }

    public function get(string $id): mixed
    {
        if (array_key_exists($id, $this->instances)) {
            return $this->instances[$id];
        }
        if (!isset($this->factories[$id])) {
            throw new \RuntimeException("Service not registered: {$id}");
        }

        return $this->instances[$id] = ($this->factories[$id])($this);
    }

    public function has(string $id): bool
    {
        return isset($this->factories[$id]) || array_key_exists($id, $this->instances);
    }
}

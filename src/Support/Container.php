<?php

declare(strict_types=1);

namespace Reptilienmarkt\Support;

use RuntimeException;

/**
 * Minimaler Service-Container. Bewusst ohne Autowiring: Jede Abhaengigkeit wird
 * in config/container.php explizit verdrahtet, damit der Objektgraph lesbar bleibt.
 */
final class Container
{
    /** @var array<string, callable(self): mixed> */
    private array $factories = [];

    /** @var array<string, mixed> */
    private array $instances = [];

    /**
     * @param callable(self): mixed $factory
     */
    public function set(string $id, callable $factory): void
    {
        $this->factories[$id] = $factory;
        unset($this->instances[$id]);
    }

    public function has(string $id): bool
    {
        return isset($this->factories[$id]) || \array_key_exists($id, $this->instances);
    }

    /**
     * @template T of object
     *
     * @param class-string<T>|string $id
     *
     * @return ($id is class-string<T> ? T : mixed)
     */
    public function get(string $id): mixed
    {
        if (\array_key_exists($id, $this->instances)) {
            /** @var T */
            return $this->instances[$id];
        }

        if (!isset($this->factories[$id])) {
            throw new RuntimeException(\sprintf('Kein Service registriert fuer "%s".', $id));
        }

        $instance = ($this->factories[$id])($this);
        $this->instances[$id] = $instance;

        /** @var T */
        return $instance;
    }
}

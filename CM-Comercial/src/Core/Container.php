<?php
declare(strict_types=1);

namespace App\Core;

use Closure;
use RuntimeException;

final class Container
{
    /** @var array<string, mixed> */
    private array $instances = [];

    /** @var array<string, Closure(self):mixed> */
    private array $factories = [];

    public function set(string $id, callable $factory): void
    {
        $this->factories[$id] = Closure::fromCallable($factory);
        unset($this->instances[$id]);
    }

    public function singleton(string $id, callable $factory): void
    {
        $this->factories[$id] = function (self $container) use ($factory, $id): mixed {
            if (!array_key_exists($id, $container->instances)) {
                $container->instances[$id] = $factory($container);
            }
            return $container->instances[$id];
        };
    }

    public function get(string $id): mixed
    {
        if (array_key_exists($id, $this->instances)) {
            return $this->instances[$id];
        }
        if (!isset($this->factories[$id])) {
            throw new RuntimeException("Service '{$id}' is not registered.");
        }
        return ($this->factories[$id])($this);
    }

    public function has(string $id): bool
    {
        return array_key_exists($id, $this->instances) || isset($this->factories[$id]);
    }
}

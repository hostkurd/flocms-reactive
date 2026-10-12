<?php
declare(strict_types=1);
namespace FloCMS\Reactive;

use InvalidArgumentException;
use ReflectionClass;

final class Registry
{
    private array $components = [];

    public function register(string $name, string $class): void
    {
        if (preg_match('/^[a-z][a-z0-9]*(?:-[a-z0-9]+)*$/D', $name) !== 1
            || !is_subclass_of($class, Component::class)
            || !(new ReflectionClass($class))->isInstantiable()) {
            throw new InvalidArgumentException('Register a valid component name and instantiable Component class.');
        }
        if (isset($this->components[$name]) && $this->components[$name] !== $class) {
            throw new InvalidArgumentException('Component name already registered: ' . $name);
        }
        $this->components[$name] = $class;
    }

    public function create(string $name): Component
    {
        $class = $this->components[$name] ?? throw new \FloCMS\Core\HttpException(404);
        return new $class();
    }
}

<?php

namespace Tests\Container;

use Psr\Container\ContainerInterface;
use Tests\Exceptions\ContainerException;

class MockContainer implements ContainerInterface
{
    private array $services = [];
    private array $factories = [];
    private array $parameters = [];

    public function get(string $id)
    {
        if (isset($this->services[$id])) {
            return $this->services[$id];
        }

        if (isset($this->factories[$id])) {
            $this->services[$id] = $this->factories[$id]($this);
            return $this->services[$id];
        }

        throw new ContainerException("Service '$id' not found");
    }

    public function has(string $id): bool
    {
        return isset($this->services[$id]) || isset($this->factories[$id]);
    }

    public function setParameter(string $name, $value): self
    {
        $this->parameters[$name] = $value;
        return $this;
    }

    public function getParameter(string $name)
    {
        return $this->parameters[$name] ?? null;
    }

    public function addDefinition(string $id, callable $factory): self
    {
        $this->factories[$id] = $factory;
        return $this;
    }
}

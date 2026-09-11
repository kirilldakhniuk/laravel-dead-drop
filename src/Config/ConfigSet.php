<?php

declare(strict_types=1);

namespace DeadDrop\DeadDrop\Config;

use InvalidArgumentException;

/**
 * The reviewed config for every connection the planner knows about.
 */
final readonly class ConfigSet
{
    /**
     * @param  array<string, ConnectionConfig>  $connections  keyed by connection name
     */
    public function __construct(
        public array $connections,
    ) {}

    public function for(string $connection): ConnectionConfig
    {
        return $this->connections[$connection] ?? throw new InvalidArgumentException("No config for connection [$connection].");
    }

    public function has(string $connection): bool
    {
        return isset($this->connections[$connection]);
    }

    /** @return array<int, string> */
    public function connections(): array
    {
        return array_keys($this->connections);
    }
}

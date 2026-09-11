<?php

declare(strict_types=1);

namespace DeadDrop\DeadDrop\Schema;

use InvalidArgumentException;

final readonly class SchemaSet
{
    /**
     * @param  array<string, DatabaseSchema>  $schemas
     */
    public function __construct(
        public array $schemas,
    ) {}

    public function for(string $connection): DatabaseSchema
    {
        return $this->schemas[$connection] ?? throw new InvalidArgumentException("No schema for connection [$connection].");
    }

    /** @return array<int, string> */
    public function connections(): array
    {
        return array_keys($this->schemas);
    }
}

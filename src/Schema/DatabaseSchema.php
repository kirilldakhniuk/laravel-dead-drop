<?php

declare(strict_types=1);

namespace DeadDrop\DeadDrop\Schema;

final readonly class DatabaseSchema
{
    /**
     * @param  array<string, Table>  $tables
     */
    public function __construct(
        public string $connection,
        public string $driver,
        public array $tables,
    ) {}

    public function table(string $name): ?Table
    {
        return $this->tables[$name] ?? null;
    }

    /** @return array<int, string> */
    public function tableNames(): array
    {
        return array_keys($this->tables);
    }
}

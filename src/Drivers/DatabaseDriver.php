<?php

declare(strict_types=1);

namespace DeadDrop\DeadDrop\Drivers;

use DeadDrop\DeadDrop\Schema\ColumnType;
use Illuminate\Database\Connection;

interface DatabaseDriver
{
    public function name(): string;

    public function normaliseType(string $nativeType): ColumnType;

    /** @return array<string, int> table => estimated rows */
    public function estimatedRowCounts(Connection $connection): array;

    /**
     * The schema (or database) the connection's unqualified names resolve in,
     * so introspection can ignore tables that belong to another one. A null
     * means the driver cannot name it and every table is in scope.
     */
    public function currentSchema(Connection $connection): ?string;

    public function createKeyTable(Connection $connection, string $name, ColumnType $keyType): void;

    /**
     * Adds keys, ignoring the ones already present, and reports how many rows
     * the inserts actually created.
     *
     * @param  array<int, int|string>  $keys
     */
    public function insertKeys(Connection $connection, string $name, array $keys): int;

    public function countKeys(Connection $connection, string $name): int;

    public function dropKeyTable(Connection $connection, string $name): void;

    public function quote(string $identifier): string;
}

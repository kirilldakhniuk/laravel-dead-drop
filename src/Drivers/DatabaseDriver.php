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

    public function createKeyTable(Connection $connection, string $name, ColumnType $keyType): void;

    /** @param array<int, int|string> $keys */
    public function insertKeys(Connection $connection, string $name, array $keys): void;

    public function countKeys(Connection $connection, string $name): int;

    public function dropKeyTable(Connection $connection, string $name): void;

    public function quote(string $identifier): string;
}

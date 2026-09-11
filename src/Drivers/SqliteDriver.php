<?php

declare(strict_types=1);

namespace DeadDrop\DeadDrop\Drivers;

use DeadDrop\DeadDrop\Schema\ColumnType;
use Illuminate\Database\Connection;

final class SqliteDriver implements DatabaseDriver
{
    use NormalisesTypes;

    public function name(): string
    {
        return 'sqlite';
    }

    /** @return array<string, int> */
    public function estimatedRowCounts(Connection $connection): array
    {
        $counts = [];

        foreach ($connection->getSchemaBuilder()->getTableListing() as $table) {
            if (str_starts_with($table, 'sqlite_')) {
                continue;
            }

            $counts[$table] = (int) $connection->table($table)->count();
        }

        return $counts;
    }

    public function createKeyTable(Connection $connection, string $name, ColumnType $keyType): void
    {
        $type = $keyType === ColumnType::Integer ? 'INTEGER' : 'TEXT';

        $connection->statement("DROP TABLE IF EXISTS {$this->quote($name)}");
        $connection->statement("CREATE TEMPORARY TABLE {$this->quote($name)} (k {$type} PRIMARY KEY)");
    }

    /** @param array<int, int|string> $keys */
    public function insertKeys(Connection $connection, string $name, array $keys): void
    {
        if ($keys === []) {
            return;
        }

        foreach (array_chunk(array_values(array_unique($keys)), 500) as $chunk) {
            $connection->table($name)->insertOrIgnore(array_map(fn (int|string $k): array => ['k' => $k], $chunk));
        }
    }

    public function countKeys(Connection $connection, string $name): int
    {
        return (int) $connection->table($name)->count();
    }

    public function dropKeyTable(Connection $connection, string $name): void
    {
        $connection->statement("DROP TABLE IF EXISTS {$this->quote($name)}");
    }

    public function quote(string $identifier): string
    {
        return '"'.str_replace('"', '""', $identifier).'"';
    }
}

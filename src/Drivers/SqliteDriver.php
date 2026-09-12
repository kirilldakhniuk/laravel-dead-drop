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

        foreach ($connection->getSchemaBuilder()->getTableListing(schemaQualified: false) as $table) {
            $counts[$table] = (int) $connection->table($table)->count();
        }

        return $counts;
    }

    public function currentSchema(Connection $connection): string
    {
        return 'main';
    }

    public function createKeyTable(Connection $connection, string $name, ColumnType $keyType): void
    {
        $type = $keyType === ColumnType::Integer ? 'INTEGER' : 'TEXT';
        $table = $connection->getTablePrefix().$name;

        $connection->statement("DROP TABLE IF EXISTS temp.{$this->quote($table)}");
        $connection->statement("CREATE TEMPORARY TABLE {$this->quote($table)} (k {$type} PRIMARY KEY)");
    }

    /**
     * The key column is the table's primary key, so the rows `insertOrIgnore`
     * reports are exactly the keys that were not collected yet.
     *
     * @param  array<int, int|string>  $keys
     */
    public function insertKeys(Connection $connection, string $name, array $keys): int
    {
        if ($keys === []) {
            return 0;
        }

        $inserted = 0;

        foreach (array_chunk(array_values(array_unique($keys)), 500) as $chunk) {
            $inserted += $connection->table($name)->insertOrIgnore(array_map(fn (int|string $k): array => ['k' => $k], $chunk));
        }

        return $inserted;
    }

    public function countKeys(Connection $connection, string $name): int
    {
        return (int) $connection->table($name)->count();
    }

    public function dropKeyTable(Connection $connection, string $name): void
    {
        $table = $connection->getTablePrefix().$name;

        $connection->statement("DROP TABLE IF EXISTS temp.{$this->quote($table)}");
    }

    public function quote(string $identifier): string
    {
        return '"'.str_replace('"', '""', $identifier).'"';
    }
}

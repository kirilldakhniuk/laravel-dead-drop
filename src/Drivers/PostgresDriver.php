<?php

declare(strict_types=1);

namespace DeadDrop\DeadDrop\Drivers;

use DeadDrop\DeadDrop\Schema\ColumnType;
use Illuminate\Database\Connection;

final class PostgresDriver implements DatabaseDriver
{
    use NormalisesTypes;

    public function name(): string
    {
        return 'pgsql';
    }

    /** @return array<string, int> */
    public function estimatedRowCounts(Connection $connection): array
    {
        $rows = $connection->select(
            "select relname as t, greatest(reltuples, 0)::bigint as n from pg_class c
             join pg_namespace n on n.oid = c.relnamespace
             where c.relkind = 'r' and n.nspname = current_schema()",
        );

        $counts = [];

        foreach ($rows as $row) {
            /** @var object{t: string, n: int|string|null} $row */
            $counts[(string) $row->t] = (int) $row->n;
        }

        return $counts;
    }

    public function createKeyTable(Connection $connection, string $name, ColumnType $keyType): void
    {
        $type = $keyType === ColumnType::Integer ? 'BIGINT' : 'TEXT';
        $table = $connection->getTablePrefix().$name;

        $connection->statement("DROP TABLE IF EXISTS pg_temp.{$this->quote($table)}");
        $connection->statement("CREATE TEMPORARY TABLE {$this->quote($table)} (k {$type} PRIMARY KEY) ON COMMIT PRESERVE ROWS");
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
        $table = $connection->getTablePrefix().$name;

        $connection->statement("DROP TABLE IF EXISTS pg_temp.{$this->quote($table)}");
    }

    public function quote(string $identifier): string
    {
        return '"'.str_replace('"', '""', $identifier).'"';
    }
}

<?php

declare(strict_types=1);

namespace DeadDrop\DeadDrop\Drivers;

use DeadDrop\DeadDrop\Schema\ColumnType;
use Illuminate\Database\Connection;

final class MySqlDriver implements DatabaseDriver
{
    use NormalisesTypes;

    /**
     * The session modes that refuse values a source can nonetheless hold: a
     * legacy `0000-00-00 00:00:00` datetime, a truncated string, a division
     * by zero. A load copies what the source had, so they come off for the
     * load and go straight back on.
     *
     * @var list<string>
     */
    private const array RELAXED_MODES = [
        'STRICT_TRANS_TABLES',
        'STRICT_ALL_TABLES',
        'NO_ZERO_DATE',
        'NO_ZERO_IN_DATE',
        'ERROR_FOR_DIVISION_BY_ZERO',
    ];

    /**
     * The `sql_mode` each connection had before its load, keyed by connection
     * name, so a run against two connections restores each to its own.
     *
     * @var array<string, string>
     */
    private array $sqlModes = [];

    public function name(): string
    {
        return 'mysql';
    }

    /** @return array<string, int> */
    public function estimatedRowCounts(Connection $connection): array
    {
        $rows = $connection->select(
            'select TABLE_NAME as t, TABLE_ROWS as n from information_schema.TABLES where TABLE_SCHEMA = ?',
            [$connection->getDatabaseName()],
        );

        $counts = [];

        foreach ($rows as $row) {
            /** @var object{t: string, n: int|string|null} $row */
            $counts[(string) $row->t] = (int) $row->n;
        }

        return $counts;
    }

    public function currentSchema(Connection $connection): string
    {
        return $connection->getDatabaseName();
    }

    public function createKeyTable(Connection $connection, string $name, ColumnType $keyType): void
    {
        $type = $keyType === ColumnType::Integer ? 'BIGINT UNSIGNED' : 'VARCHAR(191)';
        $table = $connection->getTablePrefix().$name;

        $connection->statement("DROP TEMPORARY TABLE IF EXISTS {$this->quote($table)}");
        $connection->statement("CREATE TEMPORARY TABLE {$this->quote($table)} (k {$type} PRIMARY KEY) ENGINE=InnoDB");
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

        $connection->statement("DROP TEMPORARY TABLE IF EXISTS {$this->quote($table)}");
    }

    public function disableForeignKeyChecks(Connection $connection): void
    {
        $connection->statement('SET FOREIGN_KEY_CHECKS = 0');
    }

    public function enableForeignKeyChecks(Connection $connection): void
    {
        $connection->statement('SET FOREIGN_KEY_CHECKS = 1');
    }

    public function beginLoading(Connection $connection): void
    {
        $this->disableForeignKeyChecks($connection);

        $current = $connection->scalar('select @@SESSION.sql_mode');

        if (! is_string($current)) {
            return; // nothing to relax, and nothing to restore
        }

        $this->sqlModes[$connection->getName() ?? ''] = $current;

        $connection->statement('SET SESSION sql_mode = ?', [$this->relaxed($current)]);
    }

    public function endLoading(Connection $connection): void
    {
        $name = $connection->getName() ?? '';

        if (array_key_exists($name, $this->sqlModes)) {
            $connection->statement('SET SESSION sql_mode = ?', [$this->sqlModes[$name]]);

            unset($this->sqlModes[$name]);
        }

        $this->enableForeignKeyChecks($connection);
    }

    /**
     * The session's own `sql_mode` with only the strictness a faithful copy
     * cannot satisfy removed — every other mode the operator set is left
     * exactly where it was.
     */
    private function relaxed(string $sqlMode): string
    {
        $modes = array_filter(
            array_map(trim(...), explode(',', $sqlMode)),
            fn (string $mode): bool => $mode !== '' && ! in_array(strtoupper($mode), self::RELAXED_MODES, true),
        );

        return implode(',', $modes);
    }

    public function quote(string $identifier): string
    {
        return '`'.str_replace('`', '``', $identifier).'`';
    }
}

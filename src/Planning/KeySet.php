<?php

declare(strict_types=1);

namespace DeadDrop\DeadDrop\Planning;

use DeadDrop\DeadDrop\Drivers\DatabaseDriver;
use DeadDrop\DeadDrop\Schema\ColumnType;
use Illuminate\Database\Connection;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;

/**
 * The primary keys collected for one table, held in a temporary table on the
 * table's own connection so a traversal never keeps a row set in PHP memory.
 */
final readonly class KeySet
{
    public function __construct(
        private DatabaseDriver $driver,
        private Connection $db,
        public string $connection,
        public string $table,
        public ColumnType $type,
        public string $tableName,
    ) {}

    /**
     * Adds keys, ignoring the ones already collected, and reports how many
     * were new: a zero means this branch of the traversal has settled.
     *
     * @param  list<int|string>  $keys
     */
    public function add(array $keys): int
    {
        if ($keys === []) {
            return 0;
        }

        $before = $this->count();

        $this->driver->insertKeys($this->db, $this->tableName, $keys);

        return $this->count() - $before;
    }

    public function count(): int
    {
        return $this->driver->countKeys($this->db, $this->tableName);
    }

    /**
     * @param  callable(list<int|string>): void  $fn
     */
    public function chunk(int $size, callable $fn): void
    {
        $this->query()->orderBy('k')->chunk($size, function (Collection $rows) use ($fn): void {
            $keys = [];

            foreach ($rows as $row) {
                $key = $row->k ?? null;

                if (is_int($key) || is_string($key)) {
                    $keys[] = $key;
                }
            }

            $fn($keys);
        });
    }

    public function query(): Builder
    {
        return $this->db->table($this->tableName);
    }
}

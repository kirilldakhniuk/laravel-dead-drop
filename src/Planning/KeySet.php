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
    /**
     * How many keys are read out of a query, or out of this key set, at once.
     */
    private const int CHUNK = 5000;

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

        return $this->driver->insertKeys($this->db, $this->tableName, $keys);
    }

    /**
     * Adds the keys a query selects as `k`, reading them in chunks and adding
     * each one before the next is fetched, and reports how many were new. The
     * keys never all exist in PHP at once, which is the whole point of holding
     * them in a table.
     */
    public function fill(Builder $query): int
    {
        $added = 0;

        $query->chunk(self::CHUNK, function (Collection $rows) use (&$added): void {
            /** @var list<int|string> $keys */
            $keys = [];

            foreach ($rows as $row) {
                $key = $row->k ?? null;

                if (is_int($key) || is_string($key)) {
                    $keys[] = $key;
                }
            }

            $added += $this->add($keys);
        });

        return $added;
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

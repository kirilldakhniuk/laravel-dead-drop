<?php

declare(strict_types=1);

namespace DeadDrop\DeadDrop\Planning;

use DeadDrop\DeadDrop\Drivers\DatabaseDriver;
use DeadDrop\DeadDrop\Schema\ColumnType;
use Illuminate\Database\Connection;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;

final readonly class KeySet
{
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
     * Returns the number of newly inserted keys.
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
     * Collects an ordered query's `k` values and returns the number of new keys.
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
        $this->query()->chunkById($size, function (Collection $rows) use ($fn): void {
            $keys = [];

            foreach ($rows as $row) {
                $key = $row->k ?? null;

                if (is_int($key) || is_string($key)) {
                    $keys[] = $key;
                }
            }

            $fn($keys);
        }, 'k');
    }

    public function query(): Builder
    {
        return $this->db->table($this->tableName);
    }
}

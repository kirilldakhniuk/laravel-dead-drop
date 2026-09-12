<?php

declare(strict_types=1);

namespace DeadDrop\DeadDrop\Loading;

use DeadDrop\DeadDrop\Artifacts\TableManifest;
use Illuminate\Database\Connection;

/**
 * Inserts the rows of a gzipped NDJSON table file through the query builder.
 *
 * The reader hands rows over one at a time and only a single chunk is held,
 * so a table larger than memory still loads.
 */
final class NdjsonLoader implements Loader
{
    private const int CHUNK = 500;

    public function format(): string
    {
        return 'ndjson';
    }

    /**
     * @param  iterable<int, array<string, mixed>>  $rows
     */
    public function load(TableManifest $table, iterable $rows, Connection $target): int
    {
        $written = 0;
        $chunk = [];

        foreach ($rows as $row) {
            $chunk[] = $row;

            if (count($chunk) === self::CHUNK) {
                $written += $this->insert($table, $chunk, $target);
                $chunk = [];
            }
        }

        if ($chunk !== []) {
            $written += $this->insert($table, $chunk, $target);
        }

        return $written;
    }

    /**
     * @param  non-empty-list<array<string, mixed>>  $chunk
     */
    private function insert(TableManifest $table, array $chunk, Connection $target): int
    {
        $target->table($table->table)->insert($chunk);

        return count($chunk);
    }
}

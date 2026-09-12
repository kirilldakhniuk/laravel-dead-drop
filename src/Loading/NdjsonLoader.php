<?php

declare(strict_types=1);

namespace DeadDrop\DeadDrop\Loading;

use DeadDrop\DeadDrop\Artifacts\ArtifactReader;
use DeadDrop\DeadDrop\Artifacts\TableManifest;
use Illuminate\Database\Connection;

/**
 * Reads a table's gzipped NDJSON file through the artifact reader — which
 * decodes each line back into a row with its scalar types restored — and
 * inserts those rows through the query builder a chunk at a time.
 *
 * The reader yields one row at a time and only a single chunk is ever held,
 * so a table larger than memory still loads.
 */
final class NdjsonLoader implements Loader
{
    /**
     * The most rows one insert ever carries; wide tables use fewer, because
     * every column of every row is a bound parameter and SQLite stops at
     * 32,766 of them.
     */
    private const int CHUNK = 500;

    private const int MAX_BINDINGS = 30000;

    public function format(): string
    {
        return 'ndjson';
    }

    public function load(TableManifest $table, ArtifactReader $reader, string $artifactId, Connection $target): int
    {
        $written = 0;
        $chunk = [];
        $size = $this->chunkSize($table);

        foreach ($reader->rows($artifactId, $table) as $row) {
            $chunk[] = $row;

            if (count($chunk) === $size) {
                $written += $this->insert($table, $chunk, $target);
                $chunk = [];
            }
        }

        if ($chunk !== []) {
            $written += $this->insert($table, $chunk, $target);
        }

        return $written;
    }

    private function chunkSize(TableManifest $table): int
    {
        return min(self::CHUNK, max(1, intdiv(self::MAX_BINDINGS, max(1, count($table->columns)))));
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

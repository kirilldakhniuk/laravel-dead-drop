<?php

declare(strict_types=1);

namespace DeadDrop\DeadDrop\Loading;

use DeadDrop\DeadDrop\Artifacts\ArtifactReader;
use DeadDrop\DeadDrop\Artifacts\TableManifest;
use DeadDrop\DeadDrop\Schema\Table;
use Illuminate\Database\Connection;

final class NdjsonLoader implements Loader
{
    private const int CHUNK = 500;

    private const int MAX_BINDINGS = 30000;

    public function format(): string
    {
        return 'ndjson';
    }

    public function load(TableManifest $table, ArtifactReader $reader, string $artifactId, Connection $target, Table $schema): int
    {
        $written = 0;
        $chunk = [];
        $size = $this->chunkSize($table);
        $generated = $this->generatedColumns($schema);

        foreach ($reader->rows($artifactId, $table) as $row) {
            // Generated values are exported, but the target must compute them on insert.
            foreach ($generated as $column) {
                unset($row[$column]);
            }

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

    /**
     * @return list<string>
     */
    private function generatedColumns(Table $schema): array
    {
        $generated = [];

        foreach ($schema->columns as $column) {
            if ($column->generated) {
                $generated[] = $column->name;
            }
        }

        return $generated;
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

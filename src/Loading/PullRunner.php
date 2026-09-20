<?php

declare(strict_types=1);

namespace DeadDrop\DeadDrop\Loading;

use Closure;
use DeadDrop\DeadDrop\Artifacts\ArtifactReader;
use DeadDrop\DeadDrop\Artifacts\Manifest;
use DeadDrop\DeadDrop\Artifacts\TableManifest;
use DeadDrop\DeadDrop\Drivers\DriverFactory;
use DeadDrop\DeadDrop\Schema\DatabaseSchema;
use DeadDrop\DeadDrop\Schema\Introspector;
use DeadDrop\DeadDrop\Schema\Table;
use Illuminate\Database\Connection;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;

final class PullRunner
{
    public function __construct(
        private readonly Introspector $introspector,
        private readonly DriverFactory $drivers,
        private readonly LoaderRegistry $loaders,
    ) {}

    /**
     * @param  Closure(string, int): void|null  $progress  called with the table key and rows written, after each table
     *
     * @throws RuntimeException when the target cannot hold the slice, or a table loads a different number of rows than the manifest promises
     * @throws InvalidArgumentException when the manifest names a format this installation has no loader for
     */
    public function run(Manifest $manifest, ArtifactReader $reader, string $targetConnection, ?Closure $progress = null): PullReport
    {
        $schema = $this->introspector->inspect($targetConnection);
        $db = DB::connection($targetConnection);
        $driver = $this->drivers->for($db);

        // Validate every table before replacing any data.
        $this->assertTargetFits($manifest, $schema);

        /** @var array<string, int> $loaded */
        $loaded = [];
        /** @var list<string> $skipped */
        $skipped = [];

        $driver->beginLoading($db);

        try {
            foreach ($manifest->tables as $table) {
                $target = $schema->table($table->table);

                if ($target === null) {
                    $skipped[] = "{$table->key()}: not present on the target";

                    continue;
                }

                $written = $this->replaceTable($table, $manifest, $reader, $db, $target);

                $loaded[$table->key()] = $written;

                $progress?->__invoke($table->key(), $written);
            }
        } finally {
            $driver->endLoading($db);
        }

        return new PullReport($loaded, $skipped);
    }

    private function replaceTable(TableManifest $table, Manifest $manifest, ArtifactReader $reader, Connection $db, Table $target): int
    {
        $loader = $this->loaders->for($table->format);

        try {
            return $db->transaction(function () use ($table, $manifest, $reader, $db, $loader, $target): int {
                $db->table($table->table)->delete();

                $written = $loader->load($table, $reader, $manifest->id, $db, $target);

                // A row count mismatch must roll back this table.
                if ($written !== $table->rows) {
                    throw new RuntimeException("Row count mismatch for [{$table->key()}]: manifest says {$table->rows}, loaded {$written}");
                }

                return $written;
            });
        } catch (QueryException $e) {
            // Omit SQL bindings: they can contain row data.
            $message = explode(' (Connection:', $e->getMessage(), 2)[0];

            throw new RuntimeException("Loading [{$table->key()}] failed: {$message}", previous: $e);
        }
    }

    private function assertTargetFits(Manifest $manifest, DatabaseSchema $schema): void
    {
        /** @var array<string, string> $seen */
        $seen = [];

        foreach ($manifest->tables as $table) {
            if (($seen[$table->table] ?? $table->connection) !== $table->connection) {
                throw new RuntimeException("Artifact holds table [{$table->table}] from more than one connection; a single target cannot hold both.");
            }

            $seen[$table->table] = $table->connection;

            $this->loaders->for($table->format);
        }

        foreach ($manifest->tables as $table) {
            $target = $schema->table($table->table);

            if ($target === null) {
                continue; // absent tables are skipped, not refused
            }

            $carried = array_column($table->columns, 'name');

            $missing = array_values(array_diff($carried, $target->columnNames()));

            sort($missing);

            if ($missing !== []) {
                throw new RuntimeException("Target table [{$table->table}] is missing columns: ".implode(', ', $missing));
            }

            $required = [];

            foreach ($target->columns as $column) {
                if (! $column->nullable && $column->default === null && ! $column->autoIncrement && ! $column->generated && ! in_array($column->name, $carried, true)) {
                    $required[] = $column->name;
                }
            }

            sort($required);

            if ($required !== []) {
                throw new RuntimeException("Target table [{$table->table}] requires columns the artifact does not carry: ".implode(', ', $required));
            }
        }
    }
}

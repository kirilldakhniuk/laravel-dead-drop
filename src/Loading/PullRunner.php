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

/**
 * Replaces the artifact's tables in a target connection.
 *
 * Replace is the only mode: each table the target has is emptied and refilled
 * from the artifact inside one transaction, so a table either ends up as the
 * dump saw it or exactly as it was. Referential integrity is off for the whole
 * run because a slice arrives in plan order, not in an order any one database
 * would accept row by row, and it is restored in a `finally` whatever happens.
 * Tables the artifact does not name are never touched.
 */
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

        // Shape is checked against the whole manifest before a single row
        // moves, so a target that cannot hold the slice is refused intact
        // rather than left half replaced.
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

                $written = $this->replace($table, $manifest, $reader, $db, $target);

                $loaded[$table->key()] = $written;

                $progress?->__invoke($table->key(), $written);
            }
        } finally {
            $driver->endLoading($db);
        }

        return new PullReport($loaded, $skipped);
    }

    private function replace(TableManifest $table, Manifest $manifest, ArtifactReader $reader, Connection $db, Table $target): int
    {
        $loader = $this->loaders->for($table->format);
        $written = 0;

        try {
            $db->transaction(function () use ($table, $manifest, $reader, $db, $loader, $target, &$written): void {
                $db->table($table->table)->delete();

                $written = $loader->load($table, $reader, $manifest->id, $db, $target);

                // A short table means the artifact file and its manifest entry
                // disagree, and a slice that is quietly incomplete is worse than
                // one that is not there, so the table rolls back.
                if ($written !== $table->rows) {
                    throw new RuntimeException("Row count mismatch for [{$table->key()}]: manifest says {$table->rows}, loaded {$written}");
                }
            });
        } catch (QueryException $e) {
            // A pull touches many tables; the engine's message names a column
            // and a constraint but never which of them was being written. Only
            // the engine's own text is repeated: Laravel appends the SQL and
            // its bindings, which for an insert is the row itself — the one
            // thing a redacted dump must not print.
            $message = explode(' (Connection:', $e->getMessage(), 2)[0];

            throw new RuntimeException("Loading [{$table->key()}] failed: {$message}", previous: $e);
        }

        return $written;
    }

    private function assertTargetFits(Manifest $manifest, DatabaseSchema $schema): void
    {
        /** @var array<string, string> $seen */
        $seen = [];

        foreach ($manifest->tables as $table) {
            // A target is one database, so two connections that both carry a
            // `users` table would load one over the other; the operator has to
            // split the pull rather than silently lose a slice.
            if (($seen[$table->table] ?? $table->connection) !== $table->connection) {
                throw new RuntimeException("Artifact holds table [{$table->table}] from more than one connection; a single target cannot hold both.");
            }

            $seen[$table->table] = $table->connection;

            // An artifact written by a newer version can name a format this
            // installation cannot read; finding that out half way through
            // would leave the tables before it already replaced.
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

            // A target column the artifact has no value for only works when
            // the database can supply one; otherwise every insert fails, and
            // it should fail before the table is emptied.
            foreach ($target->columns as $column) {
                // A generated column never takes a value on insert, so the
                // artifact not carrying one is exactly right.
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

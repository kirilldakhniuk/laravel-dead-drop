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
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
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
     * @throws RuntimeException when the target is missing a column the artifact carries, or a table loads a different number of rows than the manifest promises
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

        $driver->disableForeignKeyChecks($db);

        try {
            foreach ($manifest->tables as $table) {
                if ($schema->table($table->table) === null) {
                    $skipped[] = "{$table->key()}: not present on the target";

                    continue;
                }

                $written = $this->replace($table, $manifest, $reader, $db);

                $loaded[$table->key()] = $written;

                $progress?->__invoke($table->key(), $written);
            }
        } finally {
            $driver->enableForeignKeyChecks($db);
        }

        return new PullReport($loaded, $skipped);
    }

    private function replace(TableManifest $table, Manifest $manifest, ArtifactReader $reader, Connection $db): int
    {
        $loader = $this->loaders->for($table->format);
        $written = 0;

        $db->transaction(function () use ($table, $manifest, $reader, $db, $loader, &$written): void {
            $db->table($table->table)->delete();

            $written = $loader->load($table, $reader->rows($manifest->id, $table), $db);

            // A short table means the artifact file and its manifest entry
            // disagree, and a slice that is quietly incomplete is worse than
            // one that is not there, so the table rolls back.
            if ($written !== $table->rows) {
                throw new RuntimeException("Row count mismatch for [{$table->key()}]: manifest says {$table->rows}, loaded {$written}");
            }
        });

        return $written;
    }

    private function assertTargetFits(Manifest $manifest, DatabaseSchema $schema): void
    {
        foreach ($manifest->tables as $table) {
            $target = $schema->table($table->table);

            if ($target === null) {
                continue; // absent tables are skipped, not refused
            }

            $missing = array_values(array_diff(
                array_column($table->columns, 'name'),
                $target->columnNames(),
            ));

            sort($missing);

            if ($missing !== []) {
                throw new RuntimeException("Target table [{$table->table}] is missing columns: ".implode(', ', $missing));
            }
        }
    }
}

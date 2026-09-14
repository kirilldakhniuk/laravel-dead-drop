<?php

declare(strict_types=1);

namespace DeadDrop\DeadDrop\Extraction;

use Closure;
use Composer\InstalledVersions;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use DeadDrop\DeadDrop\Artifacts\ArtifactWriter;
use DeadDrop\DeadDrop\Artifacts\Manifest;
use DeadDrop\DeadDrop\Artifacts\TableManifest;
use DeadDrop\DeadDrop\Config\ConfigSet;
use DeadDrop\DeadDrop\Planning\ExtractionPlan;
use DeadDrop\DeadDrop\Planning\KeySetRepository;
use DeadDrop\DeadDrop\Planning\Root;
use DeadDrop\DeadDrop\Planning\UnresolvedReference;
use DeadDrop\DeadDrop\Redaction\RedactionContext;
use DeadDrop\DeadDrop\Redaction\Redactor;
use DeadDrop\DeadDrop\Schema\Column;
use DeadDrop\DeadDrop\Schema\SchemaSet;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Turns a plan into an artifact on a disk: it resolves the executor, writes
 * the manifest as `writing`, hands every step to the executor in plan order,
 * and flips the manifest to `complete` once the last table is on the disk.
 *
 * It decides nothing about which rows are taken or what a column becomes —
 * the plan and the reviewed config already answered both — so the command
 * above it stays composition and the executor below it stays row movement.
 */
final class ArtifactBuilder
{
    public function __construct(
        private readonly ExecutorManager $executors,
        private readonly KeySetRepository $keys,
    ) {}

    /**
     * @param  Closure(TableArtifact): void|null  $progress  called after each table is written
     *
     * @throws RuntimeException when the executor cannot read a connection in the plan, or the plan names a table the config or schema does not
     */
    public function build(
        ExtractionPlan $plan,
        Root $root,
        ?DateTimeInterface $since,
        ConfigSet $config,
        SchemaSet $schemas,
        RedactionContext $context,
        Filesystem $disk,
        string $basePath,
        ?Closure $progress = null,
    ): Manifest {
        $executor = $this->executors->driver();
        $connections = $this->connections($plan, $executor);
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));

        $manifest = new Manifest(
            id: Manifest::newId($now),
            status: Manifest::STATUS_WRITING,
            createdAt: $now->format(DateTimeInterface::ATOM),
            packageVersion: $this->packageVersion(),
            root: "{$root->connection}.{$root->table}:".implode(',', $root->ids),
            since: $since?->format(DateTimeInterface::ATOM),
            executor: $executor->name(),
            connections: $connections,
            tables: [],
            unresolved: array_map(
                fn (UnresolvedReference $reference): array => [
                    'connection' => $reference->connection,
                    'table' => $reference->table,
                    'column' => $reference->column,
                    'reason' => $reference->reason,
                ],
                $plan->unresolved,
            ),
        );

        $writer = new ArtifactWriter($disk, $basePath, $manifest->id);

        // The manifest lands before the first row does, so a dump that dies
        // half way still leaves something `pull` refuses and `dumps` flags
        // rather than an unexplained directory of files.
        $writer->writeManifest($manifest);

        foreach ($plan->steps as $step) {
            $table = $schemas->for($step->connection)->table($step->table);

            if ($table === null) {
                throw new RuntimeException("No schema for table [{$step->connection}.{$step->table}].");
            }

            $tableConfig = $config->for($step->connection)->table($step->table);

            if ($tableConfig === null) {
                throw new RuntimeException("No config for table [{$step->connection}.{$step->table}].");
            }

            $primaryKey = $table->primaryKey();

            if ($primaryKey === null) {
                throw new RuntimeException("Table [{$step->connection}.{$step->table}] has no single-column primary key to export by.");
            }

            $keys = $step->keyTable === null ? null : $this->keys->get($step->connection, $step->table);

            // A null key set means "the whole table" to an executor, so a step
            // that planned one and lost it would silently widen the dump past
            // what the traversal collected.
            if ($step->keyTable !== null && $keys === null) {
                throw new RuntimeException("No key set for table [{$step->connection}.{$step->table}].");
            }
            $redactor = Redactor::forTable($tableConfig, $table, $context);
            $artifact = $executor->export($step, $table, $tableConfig, $keys, $redactor, $writer);

            $manifest = $manifest->withTable(new TableManifest(
                connection: $artifact->connection,
                table: $artifact->table,
                file: $artifact->file,
                format: $artifact->format,
                rows: $artifact->rows,
                bytes: $artifact->bytes,
                primaryKey: $primaryKey,
                columns: array_map(
                    fn (Column $column): array => ['name' => $column->name, 'type' => $column->type->value],
                    array_values($table->columns),
                ),
                redacted: $redactor->columns(),
            ));

            if ($progress !== null) {
                $progress($artifact);
            }
        }

        $manifest = $manifest->withStatus(Manifest::STATUS_COMPLETE);

        $writer->writeManifest($manifest);

        return $manifest;
    }

    /**
     * The drivers behind the plan's connections, refusing up front any the
     * executor cannot read — a native executor that only speaks MySQL should
     * say so before a single file is written.
     *
     * @return array<string, array{driver: string}>
     */
    private function connections(ExtractionPlan $plan, Executor $executor): array
    {
        $connections = [];

        foreach ($plan->steps as $step) {
            if (isset($connections[$step->connection])) {
                continue;
            }

            $driver = DB::connection($step->connection)->getDriverName();

            if (! $executor->supports($driver)) {
                throw new RuntimeException("Executor [{$executor->name()}] does not support the {$driver} driver used by connection [{$step->connection}].");
            }

            $connections[$step->connection] = ['driver' => $driver];
        }

        return $connections;
    }

    private function packageVersion(): string
    {
        if (! InstalledVersions::isInstalled('kirilldakhniuk/laravel-dead-drop')) {
            return 'dev';
        }

        return InstalledVersions::getPrettyVersion('kirilldakhniuk/laravel-dead-drop') ?? 'dev';
    }
}

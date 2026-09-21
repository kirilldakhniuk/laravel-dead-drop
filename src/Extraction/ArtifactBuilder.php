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
use Throwable;

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
        ?Manifest $pending = null,
    ): Manifest {
        $executor = $this->executors->driver($pending?->executor);
        $connections = $this->connections($plan, $executor);
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));

        $manifest = new Manifest(
            id: $pending->id ?? Manifest::newId($now),
            status: Manifest::STATUS_WRITING,
            createdAt: $pending->createdAt ?? $now->format(DateTimeInterface::ATOM),
            packageVersion: $this->packageVersion(),
            root: $root->spec(),
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

        // Persist the writing status so interrupted dumps cannot be pulled.
        $writer->writeManifest($manifest);

        try {
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

                // A missing key set must not turn a scoped step into a full-table export.
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

                $writer->writeManifest($manifest);

                if ($progress !== null) {
                    $progress($artifact);
                }
            }
        } catch (Throwable $e) {
            $writer->writeManifest($manifest->withStatus(Manifest::STATUS_FAILED));

            throw $e;
        }

        $manifest = $manifest->withStatus(Manifest::STATUS_COMPLETE);

        $writer->writeManifest($manifest);

        return $manifest;
    }

    /**
     * @return array<string, array{driver: string, database: string|null}>
     */
    private function connections(ExtractionPlan $plan, Executor $executor): array
    {
        $connections = [];

        foreach ($plan->steps as $step) {
            if (isset($connections[$step->connection])) {
                continue;
            }

            $connection = DB::connection($step->connection);
            $driver = $connection->getDriverName();

            if (! $executor->supports($driver)) {
                throw new RuntimeException("Executor [{$executor->name()}] does not support the {$driver} driver used by connection [{$step->connection}].");
            }

            $connections[$step->connection] = [
                'driver' => $driver,
                'database' => $this->databaseName($driver, $connection->getDatabaseName()),
            ];
        }

        return $connections;
    }

    private function databaseName(string $driver, string $database): ?string
    {
        if ($database === '') {
            return null;
        }

        return $driver === 'sqlite' ? basename($database) : $database;
    }

    public function reserve(Root $root, Filesystem $disk, string $basePath): Manifest
    {
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $manifest = new Manifest(
            id: Manifest::newId($now),
            status: Manifest::STATUS_QUEUED,
            createdAt: $now->format(DateTimeInterface::ATOM),
            packageVersion: $this->packageVersion(),
            root: $root->spec(),
            since: null,
            executor: $this->executors->driver()->name(),
            connections: [],
            tables: [],
            unresolved: [],
        );
        (new ArtifactWriter($disk, $basePath, $manifest->id))->writeManifest($manifest);

        return $manifest;
    }

    private function packageVersion(): string
    {
        if (! InstalledVersions::isInstalled('kirilldakhnyuk/laravel-dead-drop')) {
            return 'dev';
        }

        return InstalledVersions::getPrettyVersion('kirilldakhnyuk/laravel-dead-drop') ?? 'dev';
    }
}

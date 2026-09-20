<?php

declare(strict_types=1);

namespace DeadDrop\DeadDrop\Extraction;

use DeadDrop\DeadDrop\Artifacts\ArtifactWriter;
use DeadDrop\DeadDrop\Config\TableConfig;
use DeadDrop\DeadDrop\Planning\KeySet;
use DeadDrop\DeadDrop\Planning\PlanStep;
use DeadDrop\DeadDrop\Redaction\Redactor;
use DeadDrop\DeadDrop\Schema\Table;
use Illuminate\Support\Collection;
use InvalidArgumentException;
use Throwable;

final class PhpExecutor implements Executor
{
    private const int CHUNK = 1000;

    private const array SUPPORTED = ['mysql', 'mariadb', 'pgsql', 'sqlite'];

    public function __construct(
        private readonly SourceConnections $connections = new SourceConnections,
    ) {}

    public function name(): string
    {
        return 'php';
    }

    public function supports(string $connectionDriver): bool
    {
        return in_array($connectionDriver, self::SUPPORTED, true);
    }

    public function export(PlanStep $step, Table $table, TableConfig $config, ?KeySet $keys, Redactor $redactor, ArtifactWriter $writer): TableArtifact
    {
        $primaryKey = $table->primaryKey();

        if ($primaryKey === null) {
            throw new InvalidArgumentException("Table [{$step->connection}.{$step->table}] has no single-column primary key to export by.");
        }

        $db = $this->connections->get($step->connection);

        $types = [];

        foreach ($table->columns as $column) {
            $types[$column->name] = $column->type;
        }

        $fileName = "{$step->connection}.{$step->table}.ndjson.gz";
        $file = $writer->table($fileName, $types);

        $query = $db->table($table->name)->useWritePdo()->select("{$table->name}.*");

        if ($keys !== null) {
            $query->join($keys->tableName, "{$table->name}.{$primaryKey}", '=', "{$keys->tableName}.k");
        }

        try {
            $query->chunkById(self::CHUNK, function (Collection $rows) use ($file, $redactor): void {
                foreach ($rows as $row) {
                    /** @var array<string, mixed> $values */
                    $values = (array) $row;

                    $file->append($redactor->apply($values));
                }
            }, "{$table->name}.{$primaryKey}", $primaryKey);

            $counts = $file->finish();
        } catch (Throwable $e) {
            $file->abort();

            throw $e;
        }

        return new TableArtifact($step->connection, $step->table, $fileName, 'ndjson', $counts['rows'], $counts['bytes']);
    }
}

<?php

declare(strict_types=1);

namespace DeadDrop\DeadDrop\Config;

use DeadDrop\DeadDrop\Inference\SensitiveColumnDetector;
use DeadDrop\DeadDrop\Schema\ColumnType;
use DeadDrop\DeadDrop\Schema\DatabaseSchema;
use DeadDrop\DeadDrop\Schema\Table;

/**
 * Compares a freshly introspected schema against a reviewed config and
 * reports where they disagree. Pure comparison: no database access, no
 * filesystem access, nothing but the two objects it is handed.
 */
final class DriftDetector
{
    public function __construct(
        private readonly SensitiveColumnDetector $sensitive,
    ) {}

    public function detect(DatabaseSchema $schema, ConnectionConfig $config): DriftReport
    {
        return new DriftReport(
            newTables: $this->newTables($schema, $config),
            removedTables: $this->removedTables($schema, $config),
            newColumns: $this->newColumns($schema, $config),
            removedColumns: $this->removedColumns($schema, $config),
            undecidedColumns: $this->undecidedColumns($schema, $config),
        );
    }

    /**
     * @return list<string>
     */
    private function newTables(DatabaseSchema $schema, ConnectionConfig $config): array
    {
        $tables = [];

        foreach ($schema->tables as $table) {
            if ($config->table($table->name) === null) {
                $tables[] = $table->name;
            }
        }

        sort($tables);

        return $tables;
    }

    /**
     * @return list<string>
     */
    private function removedTables(DatabaseSchema $schema, ConnectionConfig $config): array
    {
        $tables = [];

        foreach ($config->tables as $table) {
            if (! $table->removed && $schema->table($table->name) === null) {
                $tables[] = $table->name;
            }
        }

        sort($tables);

        return $tables;
    }

    /**
     * @return list<string>
     */
    private function newColumns(DatabaseSchema $schema, ConnectionConfig $config): array
    {
        $columns = [];

        foreach ($this->comparableTables($schema, $config) as $tableName => [$tableConfig, $table]) {
            foreach ($table->columnNames() as $column) {
                if (! in_array($column, $tableConfig->columns, true)) {
                    $columns[] = "{$tableName}.{$column}";
                }
            }
        }

        sort($columns);

        return $columns;
    }

    /**
     * @return list<string>
     */
    private function removedColumns(DatabaseSchema $schema, ConnectionConfig $config): array
    {
        $columns = [];

        foreach ($this->comparableTables($schema, $config) as $tableName => [$tableConfig, $table]) {
            $current = $table->columnNames();

            foreach ($tableConfig->columns as $column) {
                if (! in_array($column, $current, true)) {
                    $columns[] = "{$tableName}.{$column}";
                }
            }
        }

        sort($columns);

        return $columns;
    }

    /**
     * @return list<string>
     */
    private function undecidedColumns(DatabaseSchema $schema, ConnectionConfig $config): array
    {
        $columns = [];

        foreach ($this->comparableTables($schema, $config) as $tableName => [$tableConfig, $table]) {
            foreach ($tableConfig->redact as $column => $transformer) {
                if ($transformer === 'review') {
                    $columns[] = "{$tableName}.{$column}";
                }
            }

            foreach ($this->sensitive->detect($table) as $column => $suggestion) {
                if (! array_key_exists($column, $tableConfig->redact)) {
                    $columns[] = "{$tableName}.{$column}";
                }
            }

            // A JSON blob of unknown shape is a decision `init` writes as
            // `review`; deleting that line is not the same as making it, so
            // the column is undecided again rather than silently allowed.
            foreach ($table->columns as $column) {
                if ($column->type === ColumnType::Json && ! array_key_exists($column->name, $tableConfig->redact)) {
                    $columns[] = "{$tableName}.{$column->name}";
                }
            }
        }

        $columns = array_values(array_unique($columns));

        sort($columns);

        return $columns;
    }

    /**
     * The config tables that are present in the schema and still under
     * review: not `skip`, not `removed`.
     *
     * @return array<string, array{TableConfig, Table}>
     */
    private function comparableTables(DatabaseSchema $schema, ConnectionConfig $config): array
    {
        $tables = [];

        foreach ($config->tables as $tableConfig) {
            if ($tableConfig->removed || $tableConfig->class === TableClass::Skip) {
                continue;
            }

            $table = $schema->table($tableConfig->name);

            if ($table !== null) {
                $tables[$tableConfig->name] = [$tableConfig, $table];
            }
        }

        return $tables;
    }
}

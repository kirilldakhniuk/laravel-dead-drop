<?php

declare(strict_types=1);

namespace DeadDrop\DeadDrop\Config;

use DeadDrop\DeadDrop\Inference\SensitiveColumnDetector;
use DeadDrop\DeadDrop\Schema\ColumnType;
use DeadDrop\DeadDrop\Schema\DatabaseSchema;
use DeadDrop\DeadDrop\Schema\Table;

final class DriftDetector
{
    public function __construct(
        private readonly SensitiveColumnDetector $sensitive,
    ) {}

    public function detect(DatabaseSchema $schema, ConnectionConfig $config): DriftReport
    {
        $tables = $this->comparableTables($schema, $config);

        return new DriftReport(
            newTables: $this->newTables($schema, $config),
            removedTables: $this->removedTables($schema, $config),
            newColumns: $this->newColumns($tables),
            removedColumns: $this->removedColumns($tables),
            undecidedColumns: $this->undecidedColumns($tables),
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
     * @param  array<string, array{TableConfig, Table}>  $tables
     * @return list<string>
     */
    private function newColumns(array $tables): array
    {
        $columns = [];

        foreach ($tables as $tableName => [$tableConfig, $table]) {
            foreach (array_diff($table->columnNames(), $tableConfig->columns) as $column) {
                $columns[] = "{$tableName}.{$column}";
            }
        }

        sort($columns);

        return $columns;
    }

    /**
     * @param  array<string, array{TableConfig, Table}>  $tables
     * @return list<string>
     */
    private function removedColumns(array $tables): array
    {
        $columns = [];

        foreach ($tables as $tableName => [$tableConfig, $table]) {
            foreach (array_diff($tableConfig->columns, $table->columnNames()) as $column) {
                $columns[] = "{$tableName}.{$column}";
            }
        }

        sort($columns);

        return $columns;
    }

    /**
     * @param  array<string, array{TableConfig, Table}>  $tables
     * @return list<string>
     */
    private function undecidedColumns(array $tables): array
    {
        $columns = [];

        foreach ($tables as $tableName => [$tableConfig, $table]) {
            foreach ($tableConfig->redact as $column => $transformer) {
                if ($transformer === 'review') {
                    $columns[] = "{$tableName}.{$column}";
                }
            }

            // Keys cannot be redacted, so they need no redaction decision.
            $keys = $this->keyColumns($tableConfig, $table);

            foreach ($this->sensitive->detect($table) as $column => $suggestion) {
                if (! array_key_exists($column, $tableConfig->redact) && ! isset($keys[$column])) {
                    $columns[] = "{$tableName}.{$column}";
                }
            }

            // JSON columns require an explicit decision even if their review entry was deleted.
            foreach ($table->columns as $column) {
                if ($column->type === ColumnType::Json && ! array_key_exists($column->name, $tableConfig->redact) && ! isset($keys[$column->name])) {
                    $columns[] = "{$tableName}.{$column->name}";
                }
            }
        }

        $columns = array_values(array_unique($columns));

        sort($columns);

        return $columns;
    }

    /**
     * @return array<string, true>
     */
    private function keyColumns(TableConfig $config, Table $table): array
    {
        $keys = [];
        $primaryKey = $table->primaryKey();

        if ($primaryKey !== null) {
            $keys[$primaryKey] = true;
        }

        foreach (array_keys($config->references) as $column) {
            $keys[$column] = true;
        }

        return $keys;
    }

    /**
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

<?php

declare(strict_types=1);

namespace DeadDrop\DeadDrop\Schema;

use DeadDrop\DeadDrop\Drivers\DriverFactory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class Introspector
{
    public function __construct(
        private readonly DriverFactory $drivers,
    ) {}

    public function inspect(string $connection): DatabaseSchema
    {
        $db = DB::connection($connection);
        $driver = $this->drivers->for($db);
        $builder = Schema::connection($connection);
        $estimates = $driver->estimatedRowCounts($db);
        $schema = $driver->currentSchema($db);

        $tables = [];

        foreach ($builder->getTables() as $meta) {
            $tableSchema = $meta['schema'] ?? null;

            if ($tableSchema === 'temp') {
                continue; // SQLite lists temporary tables alongside real ones
            }

            if ($tableSchema !== null && $schema !== null && $tableSchema !== $schema) {
                continue; // another schema, another database, or an attached one
            }

            $name = $meta['name'];

            $columns = [];

            foreach ($builder->getColumns($name) as $col) {
                $columns[$col['name']] = new Column(
                    name: $col['name'],
                    // The full native type, not `type_name`: `tinyint(1)` is a
                    // boolean and `tinyint(4)` is not, and only `type` says which.
                    type: $driver->normaliseType($col['type']),
                    nativeType: $col['type'],
                    nullable: $col['nullable'],
                    autoIncrement: $col['auto_increment'],
                    default: $col['default'] === null ? null : (string) $col['default'],
                );
            }

            $indexes = array_map(
                fn (array $i): Index => new Index($i['name'], $i['columns'], $i['unique'], $i['primary']),
                $builder->getIndexes($name),
            );

            $foreignKeys = array_map(
                fn (array $f): ForeignKey => new ForeignKey($f['columns'], $f['foreign_table'], $f['foreign_columns']),
                $builder->getForeignKeys($name),
            );

            $tables[$name] = new Table(
                name: $name,
                columns: $columns,
                indexes: $indexes,
                foreignKeys: $foreignKeys,
                estimatedRows: $estimates[$name] ?? 0,
                estimatedBytes: (int) ($meta['size'] ?? 0),
            );
        }

        return new DatabaseSchema($connection, $db->getDriverName(), $tables);
    }
}

<?php

declare(strict_types=1);

namespace DeadDrop\DeadDrop\Redaction;

use DeadDrop\DeadDrop\Config\TableClass;
use DeadDrop\DeadDrop\Config\TableConfig;
use DeadDrop\DeadDrop\Schema\ColumnType;
use DeadDrop\DeadDrop\Schema\Table;
use InvalidArgumentException;

/**
 * The extraction gate for a table's `redact` map: everything here must pass
 * before a single row is allowed to move. Each column is checked in order
 * and stops at its first violation, so a column never reports more than one
 * problem.
 */
final class RedactionRules
{
    public function __construct(
        private readonly TransformerFactory $factory = new TransformerFactory,
    ) {}

    /**
     * @return list<string>
     */
    public function violations(TableConfig $config, Table $table): array
    {
        if ($config->class === TableClass::Skip) {
            return [];
        }

        $violations = [];
        $primaryKey = $table->primaryKey();

        foreach ($config->redact as $columnName => $spec) {
            $violation = $this->violationFor($config->name, $columnName, $spec, $table, $config, $primaryKey);

            if ($violation !== null) {
                $violations[] = $violation;
            }
        }

        sort($violations);

        return $violations;
    }

    private function violationFor(string $table, string $columnName, string $spec, Table $schema, TableConfig $config, ?string $primaryKey): ?string
    {
        $prefix = "{$table}.{$columnName}";
        $column = $schema->column($columnName);

        if ($column === null) {
            return "{$prefix}: column does not exist";
        }

        if ($spec === 'review') {
            return "{$prefix}: 'review' must be replaced with a decision";
        }

        if ($columnName === $primaryKey) {
            return "{$prefix}: primary key columns cannot be redacted";
        }

        if (array_key_exists($columnName, $config->references)) {
            return "{$prefix}: reference columns cannot be redacted";
        }

        [$name] = array_pad(explode(':', $spec, 2), 2, null);

        if ($spec === 'null' && ! $column->nullable) {
            return "{$prefix}: 'null' is not allowed on a NOT NULL column";
        }

        if ($name === 'scramble' && $column->type !== ColumnType::DateTime) {
            return "{$prefix}: 'scramble' requires a date or datetime column";
        }

        if (($name === 'hash' || $name === 'mask') && $column->type !== ColumnType::String) {
            return "{$prefix}: '{$spec}' requires a string column";
        }

        try {
            $this->factory->make($spec, $column, $primaryKey ?? 'id', new RedactionContext(str_repeat('x', 16), 'example.test'));
        } catch (InvalidArgumentException) {
            return "{$prefix}: unknown transformer [{$spec}]";
        }

        return null;
    }
}

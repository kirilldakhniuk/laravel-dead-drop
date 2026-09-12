<?php

declare(strict_types=1);

namespace DeadDrop\DeadDrop\Redaction;

use DeadDrop\DeadDrop\Config\TableClass;
use DeadDrop\DeadDrop\Config\TableConfig;
use DeadDrop\DeadDrop\Schema\Column;
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
    /**
     * How much of a hash has to survive truncation to stay collision-safe on
     * a unique index: 128 bits of hex, or the full email form (the hex
     * `HashTransformer` puts in front of `@{domain}`, and the shortest
     * prefix it will fall back to).
     */
    private const int UNIQUE_HEX = 32;

    private const int EMAIL_HEX = 16;

    private const int EMAIL_MIN_HEX = 8;

    public function __construct(
        private readonly TransformerFactory $factory = new TransformerFactory,
        private readonly RedactionContext $context = new RedactionContext('xxxxxxxxxxxxxxxx', 'example.test'),
    ) {}

    /**
     * @return list<string>
     */
    public function violations(TableConfig $config, Table $table): array
    {
        $violations = array_values($this->violationsByColumn($config, $table));

        sort($violations);

        return $violations;
    }

    /**
     * The same violations keyed by the column that caused them, for a caller
     * that has to act on the entry rather than print it — `dead-drop:init`
     * replaces a suggestion this gate would reject with `review`.
     *
     * @return array<string, string>
     */
    public function violationsByColumn(TableConfig $config, Table $table): array
    {
        if ($config->class === TableClass::Skip) {
            return [];
        }

        $violations = [];
        $primaryKey = $table->primaryKey();

        foreach ($config->redact as $columnName => $spec) {
            $violation = $this->violationFor($config->name, $columnName, $spec, $table, $config, $primaryKey);

            if ($violation !== null) {
                $violations[$columnName] = $violation;
            }
        }

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

        if ($name === 'null' && ! $column->nullable) {
            return "{$prefix}: 'null' is not allowed on a NOT NULL column";
        }

        if ($name === 'scramble' && ! $this->factory->isDateColumn($column)) {
            return "{$prefix}: 'scramble' requires a date or datetime column";
        }

        if (($name === 'hash' || $name === 'mask') && $column->type !== ColumnType::String) {
            return "{$prefix}: '{$spec}' requires a string column";
        }

        if ($name === 'hash') {
            $violation = $this->hashLength($prefix, $column, $schema->isUnique($columnName));

            if ($violation !== null) {
                return $violation;
            }
        }

        try {
            $this->factory->make($spec, $column, $primaryKey ?? 'id', $this->context);
        } catch (InvalidArgumentException) {
            return "{$prefix}: unknown transformer [{$spec}]";
        }

        return null;
    }

    /**
     * A hash is truncated to the column's declared length, and a truncated
     * hash collides: on a unique-indexed column that is a failed insert on
     * the target, so the column has to be wide enough to keep the hash
     * distinct (an email address keeps its domain, so it needs less hex).
     * An email address also has to stay one — a column too narrow for even
     * the shortest form would be cut off inside the domain.
     */
    private function hashLength(string $prefix, Column $column, bool $unique): ?string
    {
        $declared = $this->factory->declaredLength($column);

        if ($declared === null) {
            return null; // the database states no length to truncate to
        }

        if ($this->factory->isEmailColumn($column)) {
            $shortest = self::EMAIL_MIN_HEX + 1 + strlen($this->context->emailDomain);

            if ($declared < $shortest) {
                return "{$prefix}: 'hash' needs a declared length of at least {$shortest} for an email address";
            }

            $full = self::EMAIL_HEX + 1 + strlen($this->context->emailDomain);

            return $unique && $declared < $full
                ? "{$prefix}: 'hash' on a unique column needs a declared length of at least {$full}; [{$column->nativeType}] is too short"
                : null;
        }

        return $unique && $declared < self::UNIQUE_HEX
            ? "{$prefix}: 'hash' on a unique column needs a declared length of at least ".self::UNIQUE_HEX."; [{$column->nativeType}] is too short"
            : null;
    }
}

<?php

declare(strict_types=1);

namespace DeadDrop\DeadDrop\Redaction;

use DeadDrop\DeadDrop\Config\TableConfig;
use DeadDrop\DeadDrop\Schema\Table;
use InvalidArgumentException;

/**
 * Applies a table's `redact` map to a row at extraction time. Built once per
 * table from a reviewed `TableConfig`, and only from one the gate
 * (`RedactionRules`) accepts: the gate runs here too — over the same
 * transformer factory that will build the row transformers — so a caller
 * that skipped it still cannot extract rows through an unreviewed map. Past
 * that point a column whose transformer cannot be built is skipped and left
 * untouched rather than thrown over.
 */
final class Redactor
{
    /**
     * @param  array<string, Transformer>  $transformers  keyed by column
     */
    private function __construct(
        private readonly array $transformers,
    ) {}

    public static function forTable(TableConfig $config, Table $table, RedactionContext $context, ?TransformerFactory $factory = null): self
    {
        $factory ??= new TransformerFactory;
        $violations = (new RedactionRules($factory))->violations($config, $table);

        if ($violations !== []) {
            throw new InvalidArgumentException("Invalid redaction config for [{$table->name}]: ".implode('; ', $violations));
        }

        $primaryKey = $table->primaryKey() ?? 'id';
        $transformers = [];

        foreach ($config->redact as $columnName => $spec) {
            $column = $table->column($columnName);

            if ($column === null) {
                continue;
            }

            try {
                $transformers[$columnName] = $factory->make($spec, $column, $primaryKey, $context);
            } catch (InvalidArgumentException) {
                continue;
            }
        }

        return new self($transformers);
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    public function apply(array $row): array
    {
        foreach ($this->transformers as $column => $transformer) {
            if (array_key_exists($column, $row)) {
                $row[$column] = $transformer->apply($row[$column], $row);
            }
        }

        return $row;
    }

    /**
     * @return list<string>
     */
    public function columns(): array
    {
        $columns = array_keys($this->transformers);
        sort($columns);

        return $columns;
    }
}

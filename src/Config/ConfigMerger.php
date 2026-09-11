<?php

declare(strict_types=1);

namespace DeadDrop\DeadDrop\Config;

/**
 * Folds a freshly discovered config into the reviewed one, so re-running
 * `init` after a schema change never throws away a human decision.
 *
 * The human owns everything judgement-like — the table's class, redactions,
 * reference targets, `descend` flags and scoping — and the schema owns the
 * facts: which columns exist, which tables exist, and how confidently an
 * edge was found. A table that vanished is kept and marked `removed` rather
 * than deleted, so dropping it stays a deliberate act.
 */
final class ConfigMerger
{
    public function merge(ConnectionConfig $existing, ConnectionConfig $discovered): ConnectionConfig
    {
        $tables = [];

        foreach ($discovered->tables as $name => $table) {
            $reviewed = $existing->table($name);

            $tables[$name] = $reviewed === null ? $table : $this->table($reviewed, $table);
        }

        foreach ($existing->tables as $name => $table) {
            if (! isset($tables[$name])) {
                $tables[$name] = $this->removed($table);
            }
        }

        return new ConnectionConfig($discovered->connection, $tables);
    }

    private function table(TableConfig $existing, TableConfig $discovered): TableConfig
    {
        return new TableConfig(
            name: $existing->name,
            class: $existing->class,
            columns: $discovered->columns,
            references: $this->references($existing->references, $discovered->references),
            redact: $existing->redact + $discovered->redact,
            window: $existing->window ?? $discovered->window,
            exclude: $existing->exclude ?? $discovered->exclude,
            morph: $existing->morph ?? $discovered->morph,
            removed: false,
        );
    }

    /**
     * @param  array<string, Reference>  $existing
     * @param  array<string, Reference>  $discovered
     * @return array<string, Reference>
     */
    private function references(array $existing, array $discovered): array
    {
        $references = $existing;

        foreach ($discovered as $column => $reference) {
            $reviewed = $existing[$column] ?? null;

            $references[$column] = $reviewed === null ? $reference : $this->reference($reviewed, $reference);
        }

        return $references;
    }

    private function reference(Reference $existing, Reference $discovered): Reference
    {
        return new Reference(
            connection: $existing->connection,
            table: $existing->table,
            column: $existing->column,
            descend: $existing->descend,
            source: $discovered->source->rank() > $existing->source->rank() ? $discovered->source : $existing->source,
        );
    }

    private function removed(TableConfig $table): TableConfig
    {
        return new TableConfig(
            name: $table->name,
            class: $table->class,
            columns: $table->columns,
            references: $table->references,
            redact: $table->redact,
            window: $table->window,
            exclude: $table->exclude,
            morph: $table->morph,
            removed: true,
        );
    }
}

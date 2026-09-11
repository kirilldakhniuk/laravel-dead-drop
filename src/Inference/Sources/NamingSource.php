<?php

declare(strict_types=1);

namespace DeadDrop\DeadDrop\Inference\Sources;

use DeadDrop\DeadDrop\Inference\EdgeSource;
use DeadDrop\DeadDrop\Inference\InferredEdge;
use DeadDrop\DeadDrop\Schema\DatabaseSchema;
use Illuminate\Support\Str;

/**
 * Guesses edges from column naming conventions when neither a declared
 * foreign key nor an Eloquent relation claims the column.
 *
 * Columns matching the audit-trail blocklist (`created_by`, `updated_by`,
 * `deleted_by`, `owner_id`, `parent_id`, and any `*_by` column) are resolved
 * but never marked to descend, since descending them turns a scoped dump
 * into a full one.
 */
final class NamingSource
{
    /**
     * Columns that look like references but resolve to self-referencing or
     * hierarchical relationships rather than something worth descending
     * into. `*_by` columns (created_by, updated_by, deleted_by, ...) are
     * matched by suffix below rather than listed here.
     *
     * @var list<string>
     */
    private const array NON_DESCENDING_COLUMNS = [
        'owner_id',
        'parent_id',
    ];

    /**
     * @return list<InferredEdge>
     */
    public function infer(DatabaseSchema $schema): array
    {
        $edges = [];

        foreach ($schema->tables as $table) {
            foreach ($table->columnNames() as $column) {
                if ($column === $table->primaryKey()) {
                    continue;
                }

                $edge = $this->guess($schema, $table->name, $column);

                if ($edge !== null) {
                    $edges[] = $edge;
                }
            }
        }

        return $edges;
    }

    private function guess(DatabaseSchema $schema, string $table, string $column): ?InferredEdge
    {
        if (str_ends_with($column, '_by')) {
            return $this->guessAuditColumn($schema, $table, $column);
        }

        if (in_array($column, self::NON_DESCENDING_COLUMNS, true)) {
            return $this->guessOwnerOrParent($schema, $table, $column);
        }

        if (str_ends_with($column, '_id')) {
            return $this->guessReference($schema, $table, $column, descend: true);
        }

        return null;
    }

    private function guessAuditColumn(DatabaseSchema $schema, string $table, string $column): ?InferredEdge
    {
        $users = $schema->table('users');

        if ($users === null || $users->primaryKey() === null) {
            return null;
        }

        return new InferredEdge(
            table: $table,
            column: $column,
            targetConnection: null,
            targetTable: $users->name,
            targetColumn: $users->primaryKey(),
            source: EdgeSource::Guessed,
            descend: false,
        );
    }

    private function guessOwnerOrParent(DatabaseSchema $schema, string $table, string $column): ?InferredEdge
    {
        $edge = $this->guessReference($schema, $table, $column, descend: false);

        if ($edge !== null) {
            return $edge;
        }

        $self = $schema->table($table);

        if ($self === null || $self->primaryKey() === null) {
            return null;
        }

        return new InferredEdge(
            table: $table,
            column: $column,
            targetConnection: null,
            targetTable: $self->name,
            targetColumn: $self->primaryKey(),
            source: EdgeSource::Guessed,
            descend: false,
        );
    }

    private function guessReference(DatabaseSchema $schema, string $table, string $column, bool $descend): ?InferredEdge
    {
        $base = substr($column, 0, -3);

        foreach ([Str::plural($base), $base] as $candidateTable) {
            $target = $schema->table($candidateTable);

            if ($target !== null && $target->primaryKey() !== null) {
                return new InferredEdge(
                    table: $table,
                    column: $column,
                    targetConnection: null,
                    targetTable: $target->name,
                    targetColumn: $target->primaryKey(),
                    source: EdgeSource::Guessed,
                    descend: $descend,
                );
            }
        }

        return null;
    }
}

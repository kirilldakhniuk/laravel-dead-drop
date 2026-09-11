<?php

declare(strict_types=1);

namespace DeadDrop\DeadDrop\Inference;

use DeadDrop\DeadDrop\Schema\Table;

/**
 * Finds Eloquent-style polymorphic column pairs: a `{prefix}_type` column
 * naming a model and a `{prefix}_id` column naming its key, such as
 * `commentable_type` / `commentable_id`.
 */
final class MorphPairDetector
{
    /**
     * @return array{type: string, id: string}|null
     */
    public function detect(Table $table): ?array
    {
        foreach ($table->columnNames() as $column) {
            if (! str_ends_with($column, '_type')) {
                continue;
            }

            $prefix = substr($column, 0, -5);
            $idColumn = "{$prefix}_id";

            if ($table->column($idColumn) !== null) {
                return ['type' => $column, 'id' => $idColumn];
            }
        }

        return null;
    }
}

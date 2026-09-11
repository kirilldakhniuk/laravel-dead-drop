<?php

declare(strict_types=1);

namespace DeadDrop\DeadDrop\Inference\Sources;

use DeadDrop\DeadDrop\Inference\EdgeSource;
use DeadDrop\DeadDrop\Inference\InferredEdge;
use DeadDrop\DeadDrop\Schema\DatabaseSchema;

final class ForeignKeySource
{
    /**
     * @return list<InferredEdge>
     */
    public function infer(DatabaseSchema $schema): array
    {
        $edges = [];

        foreach ($schema->tables as $table) {
            foreach ($table->foreignKeys as $fk) {
                if (count($fk->columns) !== 1) {
                    continue;   // composite keys are refused in v1
                }

                $edges[] = new InferredEdge(
                    table: $table->name,
                    column: $fk->columns[0],
                    targetConnection: null,
                    targetTable: $fk->foreignTable,
                    targetColumn: $fk->foreignColumns[0],
                    source: EdgeSource::ForeignKey,
                );
            }
        }

        return $edges;
    }
}

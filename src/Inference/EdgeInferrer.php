<?php

declare(strict_types=1);

namespace DeadDrop\DeadDrop\Inference;

use DeadDrop\DeadDrop\Inference\Sources\EloquentSource;
use DeadDrop\DeadDrop\Inference\Sources\ForeignKeySource;
use DeadDrop\DeadDrop\Inference\Sources\NamingSource;
use DeadDrop\DeadDrop\Schema\DatabaseSchema;

/**
 * Composes the three edge sources with fixed precedence: a declared foreign
 * key beats an Eloquent relation beats a naming guess. The first source to
 * claim a `table.column` wins, and the winning edge carries its source so
 * the rendered config can record it.
 */
final class EdgeInferrer
{
    public function __construct(
        private readonly ForeignKeySource $fk,
        private readonly EloquentSource $eloquent,
        private readonly NamingSource $naming,
    ) {}

    /**
     * @return array<string, InferredEdge>
     */
    public function infer(DatabaseSchema $schema): array
    {
        $edges = [];

        foreach ([$this->fk, $this->eloquent, $this->naming] as $source) {
            foreach ($source->infer($schema) as $edge) {
                $key = "{$edge->table}.{$edge->column}";

                $edges[$key] ??= $edge;
            }
        }

        ksort($edges);

        return $edges;
    }
}

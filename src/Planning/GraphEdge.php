<?php

declare(strict_types=1);

namespace DeadDrop\DeadDrop\Planning;

/**
 * One connection-resolved reference: a column on the source table pointing at
 * a column on the target table.
 */
final readonly class GraphEdge
{
    public function __construct(
        public string $connection,
        public string $table,
        public string $column,
        public string $targetConnection,
        public string $targetTable,
        public string $targetColumn,
        public bool $descend,
    ) {}
}

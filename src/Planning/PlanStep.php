<?php

declare(strict_types=1);

namespace DeadDrop\DeadDrop\Planning;

/**
 * One table's share of a dump: the rows a traversal collected for it, and the
 * key table holding them. A null key table means the whole table is taken,
 * which is how lookup tables are dumped.
 */
final readonly class PlanStep
{
    public function __construct(
        public string $connection,
        public string $table,
        public ?string $keyTable,
        public int $rows,
        public int $estimatedBytes,
    ) {}
}

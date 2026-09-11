<?php

declare(strict_types=1);

namespace DeadDrop\DeadDrop\Planning;

/**
 * A reference the traversal held rows for but could not follow, so the dump
 * would not be referentially complete along that edge.
 */
final readonly class UnresolvedReference
{
    public function __construct(
        public string $connection,
        public string $table,
        public string $column,
        public string $reason,
    ) {}
}

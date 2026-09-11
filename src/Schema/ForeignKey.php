<?php

declare(strict_types=1);

namespace DeadDrop\DeadDrop\Schema;

final readonly class ForeignKey
{
    /**
     * @param  array<int, string>  $columns
     * @param  array<int, string>  $foreignColumns
     */
    public function __construct(
        public array $columns,
        public string $foreignTable,
        public array $foreignColumns,
    ) {}
}

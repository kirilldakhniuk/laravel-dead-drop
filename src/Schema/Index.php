<?php

declare(strict_types=1);

namespace DeadDrop\DeadDrop\Schema;

final readonly class Index
{
    /**
     * @param  array<int, string>  $columns
     */
    public function __construct(
        public string $name,
        public array $columns,
        public bool $unique,
        public bool $primary,
    ) {}
}

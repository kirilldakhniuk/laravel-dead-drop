<?php

declare(strict_types=1);

namespace DeadDrop\DeadDrop\Schema;

final readonly class Column
{
    public function __construct(
        public string $name,
        public ColumnType $type,
        public string $nativeType,
        public bool $nullable,
        public bool $autoIncrement,
        public ?string $default,
    ) {}
}

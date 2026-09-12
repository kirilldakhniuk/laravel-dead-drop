<?php

declare(strict_types=1);

namespace DeadDrop\DeadDrop\Schema;

final readonly class Column
{
    /**
     * @param  bool  $generated  the database computes this column (virtual or stored), so it accepts no value on insert
     */
    public function __construct(
        public string $name,
        public ColumnType $type,
        public string $nativeType,
        public bool $nullable,
        public bool $autoIncrement,
        public ?string $default,
        public bool $generated = false,
    ) {}
}

<?php

declare(strict_types=1);

namespace DeadDrop\DeadDrop\Schema;

final readonly class Table
{
    /**
     * @param  array<string, Column>  $columns
     * @param  array<int, Index>  $indexes
     * @param  array<int, ForeignKey>  $foreignKeys
     */
    public function __construct(
        public string $name,
        public array $columns,
        public array $indexes,
        public array $foreignKeys,
        public int $estimatedRows,
        public int $estimatedBytes,
    ) {}

    public function column(string $name): ?Column
    {
        return $this->columns[$name] ?? null;
    }

    /** @return array<int, string> */
    public function columnNames(): array
    {
        return array_keys($this->columns);
    }

    private function primaryIndex(): ?Index
    {
        foreach ($this->indexes as $index) {
            if ($index->primary) {
                return $index;
            }
        }

        return null;
    }

    public function primaryKey(): ?string
    {
        $index = $this->primaryIndex();

        return $index !== null && count($index->columns) === 1 ? $index->columns[0] : null;
    }

    public function hasCompositePrimaryKey(): bool
    {
        $index = $this->primaryIndex();

        return $index !== null && count($index->columns) > 1;
    }

    public function isUnique(string $column): bool
    {
        foreach ($this->indexes as $index) {
            if ($index->unique && $index->columns === [$column]) {
                return true;
            }
        }

        return false;
    }
}

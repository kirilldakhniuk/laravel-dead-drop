<?php

declare(strict_types=1);

namespace DeadDrop\DeadDrop\Inference;

final readonly class InferredEdge
{
    public function __construct(
        public string $table,
        public string $column,
        public ?string $targetConnection,
        public string $targetTable,
        public string $targetColumn,
        public EdgeSource $source,
        public bool $descend = true,
    ) {}
}

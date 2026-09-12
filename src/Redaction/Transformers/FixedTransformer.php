<?php

declare(strict_types=1);

namespace DeadDrop\DeadDrop\Redaction\Transformers;

use DeadDrop\DeadDrop\Redaction\Transformer;

final readonly class FixedTransformer implements Transformer
{
    public function __construct(
        private string $literal,
    ) {}

    public function apply(mixed $value, array $row): mixed
    {
        if ($value === null) {
            return null;
        }

        return $this->literal;
    }
}

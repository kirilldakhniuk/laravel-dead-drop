<?php

declare(strict_types=1);

namespace DeadDrop\DeadDrop\Redaction\Transformers;

use DeadDrop\DeadDrop\Redaction\Transformer;

final readonly class KeepTransformer implements Transformer
{
    public function apply(mixed $value, array $row): mixed
    {
        return $value;
    }
}

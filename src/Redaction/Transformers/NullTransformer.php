<?php

declare(strict_types=1);

namespace DeadDrop\DeadDrop\Redaction\Transformers;

use DeadDrop\DeadDrop\Redaction\Transformer;

final readonly class NullTransformer implements Transformer
{
    public function apply(mixed $value, array $row): mixed
    {
        return null;
    }
}

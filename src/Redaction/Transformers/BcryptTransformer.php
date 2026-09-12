<?php

declare(strict_types=1);

namespace DeadDrop\DeadDrop\Redaction\Transformers;

use DeadDrop\DeadDrop\Redaction\RedactionContext;
use DeadDrop\DeadDrop\Redaction\Transformer;

final readonly class BcryptTransformer implements Transformer
{
    public function __construct(
        private RedactionContext $context,
        private string $plain,
    ) {}

    public function apply(mixed $value, array $row): mixed
    {
        if ($value === null) {
            return null;
        }

        return $this->context->bcrypt($this->plain);
    }
}

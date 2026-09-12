<?php

declare(strict_types=1);

namespace DeadDrop\DeadDrop\Redaction\Transformers;

use DeadDrop\DeadDrop\Redaction\Transformer;

final readonly class HashTransformer implements Transformer
{
    public function __construct(
        private string $salt,
        private ?int $maxLength,
        private ?string $emailDomain,
    ) {}

    public function apply(mixed $value, array $row): mixed
    {
        if ($value === null) {
            return null;
        }

        $hex = hash('sha256', $this->salt.(string) $value);

        if ($this->emailDomain !== null) {
            return substr($hex, 0, 16).'@'.$this->emailDomain;
        }

        return $this->maxLength !== null ? substr($hex, 0, $this->maxLength) : $hex;
    }
}

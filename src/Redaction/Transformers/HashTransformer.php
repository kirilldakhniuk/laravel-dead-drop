<?php

declare(strict_types=1);

namespace DeadDrop\DeadDrop\Redaction\Transformers;

use DeadDrop\DeadDrop\Redaction\Transformer;

final readonly class HashTransformer implements Transformer
{
    /**
     * The hex prefix an email address gets, and the shortest one it may be
     * squeezed to when the column is too narrow to hold the full form.
     */
    private const int EMAIL_HEX = 16;

    private const int EMAIL_HEX_MIN = 8;

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
            return substr($hex, 0, $this->emailHexLength()).'@'.$this->emailDomain;
        }

        return $this->maxLength !== null ? substr($hex, 0, $this->maxLength) : $hex;
    }

    /**
     * How much hex an email address can carry: the full prefix, or as much of
     * it as fits beside `@{domain}` in a column that declares a length.
     * `RedactionRules` refuses a column too narrow for the shortest form, so
     * the address stays an address rather than being cut off mid-domain.
     */
    private function emailHexLength(): int
    {
        if ($this->maxLength === null || $this->emailDomain === null) {
            return self::EMAIL_HEX;
        }

        $available = $this->maxLength - 1 - strlen($this->emailDomain);

        return max(self::EMAIL_HEX_MIN, min(self::EMAIL_HEX, $available));
    }
}

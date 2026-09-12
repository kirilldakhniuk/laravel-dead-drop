<?php

declare(strict_types=1);

namespace DeadDrop\DeadDrop\Redaction;

use Illuminate\Support\Facades\Hash;

final class RedactionContext
{
    /**
     * @var array<string, string>
     */
    private array $bcrypt = [];

    public function __construct(
        public readonly string $salt,
        public readonly string $emailDomain,
    ) {}

    public function bcrypt(string $value): string
    {
        return $this->bcrypt[$value] ??= Hash::make($value);
    }
}

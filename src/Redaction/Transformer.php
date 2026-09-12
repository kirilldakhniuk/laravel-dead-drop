<?php

declare(strict_types=1);

namespace DeadDrop\DeadDrop\Redaction;

interface Transformer
{
    /**
     * @param  array<string, mixed>  $row
     */
    public function apply(mixed $value, array $row): mixed;
}

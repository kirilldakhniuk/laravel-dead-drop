<?php

declare(strict_types=1);

namespace DeadDrop\DeadDrop\Inference;

enum EdgeSource: string
{
    case ForeignKey = 'fk';
    case Eloquent = 'eloquent';
    case Guessed = 'guessed';
    case Manual = 'manual';

    public function rank(): int
    {
        return match ($this) {
            self::ForeignKey => 3,
            self::Eloquent => 2,
            self::Guessed => 1,
            self::Manual => 0,
        };
    }
}

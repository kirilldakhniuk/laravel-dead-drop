<?php

declare(strict_types=1);

namespace DeadDrop\DeadDrop\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @see \DeadDrop\DeadDrop\DeadDrop
 */
class DeadDrop extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return \DeadDrop\DeadDrop\DeadDrop::class;
    }
}

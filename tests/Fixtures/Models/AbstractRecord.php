<?php

declare(strict_types=1);

namespace DeadDrop\DeadDrop\Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A base model no row is ever stored as. A morph type naming it resolves to a
 * real class that cannot be instantiated, which is the case `MorphResolver`
 * has to survive.
 */
abstract class AbstractRecord extends Model
{
    protected $connection = 'dd_test';
}

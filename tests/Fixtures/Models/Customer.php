<?php

declare(strict_types=1);

namespace DeadDrop\DeadDrop\Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\Model;

final class Customer extends Model
{
    protected $connection = 'dd_test';
}

<?php

declare(strict_types=1);

namespace DeadDrop\DeadDrop\Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\Model;

final class Broken extends Model
{
    protected $connection = 'dd_test';

    protected $table = 'customers';

    public function __construct(string $required)
    {
        parent::__construct();
    }
}

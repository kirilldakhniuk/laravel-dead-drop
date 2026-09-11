<?php

declare(strict_types=1);

namespace DeadDrop\DeadDrop\Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use RuntimeException;

final class Company extends Model
{
    protected $connection = 'dd_test';

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    public function explode()
    {
        throw new RuntimeException('called');
    }
}

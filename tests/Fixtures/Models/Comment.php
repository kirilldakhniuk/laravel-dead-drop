<?php

declare(strict_types=1);

namespace DeadDrop\DeadDrop\Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

final class Comment extends Model
{
    protected $connection = 'dd_test';

    public function commentable(): MorphTo
    {
        return $this->morphTo();
    }
}

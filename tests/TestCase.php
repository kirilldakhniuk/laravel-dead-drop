<?php

declare(strict_types=1);

namespace DeadDrop\DeadDrop\Tests;

use DeadDrop\DeadDrop\DeadDropServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [
            DeadDropServiceProvider::class,
        ];
    }
}

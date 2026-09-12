<?php

declare(strict_types=1);

namespace DeadDrop\DeadDrop\Tests;

use DeadDrop\DeadDrop\DeadDropServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [DeadDropServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'dd_test');
        $app['config']->set('database.connections.dd_test', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => true,
            'use_native_json' => true,
        ]);
        $app['config']->set('database.connections.dd_analytics', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => true,
            'use_native_json' => true,
        ]);
        $app['config']->set('database.connections.dd_target', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => true,
            'use_native_json' => true,
        ]);
    }
}

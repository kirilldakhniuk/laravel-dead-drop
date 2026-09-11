<?php

declare(strict_types=1);

use DeadDrop\DeadDrop\Tests\TestCase;
use Illuminate\Support\Str;

uses(TestCase::class)->in(__DIR__);

function tempDirectory(): string
{
    $directory = sys_get_temp_dir().'/dead-drop-'.Str::random(8);

    mkdir($directory, recursive: true);

    return $directory;
}

function initFixtureConfig(): string
{
    $path = tempDirectory();

    test()->artisan('dead-drop:init', ['--connection' => ['dd_test'], '--path' => $path, '--no-interaction' => true])
        ->assertSuccessful();

    return $path;
}

<?php

declare(strict_types=1);

use DeadDrop\DeadDrop\Extraction\Executor;
use DeadDrop\DeadDrop\Extraction\ExecutorManager;
use DeadDrop\DeadDrop\Extraction\PhpExecutor;

it('resolves the php executor by default', function () {
    expect(app(ExecutorManager::class)->driver())->toBeInstanceOf(PhpExecutor::class)
        ->and(app(ExecutorManager::class))->toBe(app(ExecutorManager::class));
});

it('registers a custom executor through extend', function () {
    $custom = Mockery::mock(Executor::class);
    app(ExecutorManager::class)->extend('custom', fn () => $custom);

    expect(app(ExecutorManager::class)->driver('custom'))->toBe($custom);
});

it('rejects an unknown executor', function () {
    app(ExecutorManager::class)->driver('nope');
})->throws(InvalidArgumentException::class, 'nope');

<?php

declare(strict_types=1);

use DeadDrop\DeadDrop\DeadDrop;

it('resolves the singleton', function () {
    expect(app(DeadDrop::class))->toBeInstanceOf(DeadDrop::class);
});

it('returns the same instance from the container', function () {
    expect(app(DeadDrop::class))->toBe(app(DeadDrop::class));
});

it('merges the package config', function () {
    expect(config('dead-drop.placeholder'))->toBe('default');
});

it('loads the package translations', function () {
    expect(trans('dead-drop::messages.placeholder'))->toBe('DeadDrop placeholder translation.');
});

it('loads the package views', function () {
    expect(view()->exists('dead-drop::placeholder'))->toBeTrue();
});

it('registers the artisan command', function () {
    $this->artisan('dead-drop:placeholder')
        ->expectsOutputToContain('DeadDrop placeholder command executed.')
        ->assertSuccessful();
});

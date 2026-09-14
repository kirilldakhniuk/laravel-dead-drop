<?php

declare(strict_types=1);

use DeadDrop\DeadDrop\Redaction\RedactionContext;
use DeadDrop\DeadDrop\Redaction\SaltResolver;

it('merges a config with the expected top-level keys', function () {
    $config = config('dead-drop');

    expect($config)->toBeArray()
        ->toHaveKeys(['disk', 'path', 'config_path', 'model_paths', 'redaction', 'binaries', 'pull']);
});

it('defaults the disk to local', function () {
    expect(config('dead-drop.disk'))->toBe('local');
});

it('binds a RedactionContext whose salt is derived from the app key when none is configured', function () {
    app()->forgetInstance(RedactionContext::class);
    config()->set('dead-drop.redaction.salt', null);

    $expected = SaltResolver::resolve(null, config('app.key'));

    expect(app(RedactionContext::class)->salt)->toBe((string) $expected);
});

it('binds a RedactionContext whose salt is the configured salt when one is set', function () {
    app()->forgetInstance(RedactionContext::class);
    config()->set('dead-drop.redaction.salt', str_repeat('s', 32));

    expect(app(RedactionContext::class)->salt)->toBe(str_repeat('s', 32));
});

it('defaults to a reserved email domain', function () {
    expect(config('dead-drop.redaction.email_domain'))->toBe('example.test');
});

it('defines the in-memory test connection as the default', function () {
    expect(config('database.default'))->toBe('dd_test');
});

it('defaults the executor to php', function () {
    expect(config('dead-drop.executor'))->toBe('php');
});

it('defines the in-memory target connection', function () {
    expect(config('database.connections.dd_target.driver'))->toBe('sqlite');
});

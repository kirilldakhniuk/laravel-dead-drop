<?php

declare(strict_types=1);

it('merges a config with the expected top-level keys', function () {
    $config = config('dead-drop');

    expect($config)->toBeArray()
        ->toHaveKeys(['disk', 'path', 'config_path', 'model_paths', 'redaction', 'binaries', 'pull']);
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

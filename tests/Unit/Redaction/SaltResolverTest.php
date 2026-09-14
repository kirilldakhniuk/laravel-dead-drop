<?php

declare(strict_types=1);

use DeadDrop\DeadDrop\Redaction\SaltResolver;

it('returns the configured salt unchanged when one is set', function () {
    expect(SaltResolver::resolve('a-configured-salt', 'base64:some-app-key'))->toBe('a-configured-salt');
});

it('derives a 64-character lowercase hex salt from the app key when none is configured', function () {
    $salt = SaltResolver::resolve(null, 'base64:some-app-key');

    expect($salt)->toMatch('/^[0-9a-f]{64}$/');
});

it('derives the same salt from the same app key', function () {
    expect(SaltResolver::resolve(null, 'base64:some-app-key'))
        ->toBe(SaltResolver::resolve(null, 'base64:some-app-key'));
});

it('derives different salts from different app keys', function () {
    expect(SaltResolver::resolve(null, 'base64:some-app-key'))
        ->not->toBe(SaltResolver::resolve(null, 'base64:another-app-key'));
});

it('returns null when neither a configured salt nor an app key is set', function () {
    expect(SaltResolver::resolve(null, null))->toBeNull();
});

it('treats an empty configured salt as unset and falls back to the app key', function () {
    expect(SaltResolver::resolve('', 'base64:some-app-key'))
        ->toBe(SaltResolver::resolve(null, 'base64:some-app-key'));
});

it('returns null when the configured salt is empty and there is no app key', function () {
    expect(SaltResolver::resolve('', null))->toBeNull();
});

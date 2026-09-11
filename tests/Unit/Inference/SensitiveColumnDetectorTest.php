<?php

declare(strict_types=1);

use DeadDrop\DeadDrop\Inference\MorphPairDetector;
use DeadDrop\DeadDrop\Inference\SensitiveColumnDetector;
use DeadDrop\DeadDrop\Schema\Introspector;
use DeadDrop\DeadDrop\Schema\Table;
use DeadDrop\DeadDrop\Tests\Fixtures\SchemaBuilder;

beforeEach(fn () => SchemaBuilder::migrate('dd_test'));

function fixtureTable(string $name): Table
{
    return app(Introspector::class)->inspect('dd_test')->table($name);
}

it('suggests hash for an email column', function () {
    expect((new SensitiveColumnDetector)->detect(fixtureTable('users'))['email'])->toBe('hash');
});

it('suggests a deterministic bcrypt placeholder for a password column', function () {
    expect((new SensitiveColumnDetector)->detect(fixtureTable('users'))['password'])->toBe('bcrypt:secret');
});

it('suggests a fixed value for a stripe identifier', function () {
    expect((new SensitiveColumnDetector)->detect(fixtureTable('companies'))['stripe_id'])->toBe('fixed:redacted');
});

it('does not suggest a transformer for an ordinary column', function () {
    expect((new SensitiveColumnDetector)->detect(fixtureTable('orders')))->not->toHaveKey('total');
});

it('flags every json column for human review', function () {
    expect((new SensitiveColumnDetector)->needsReview(fixtureTable('failed_jobs')))->toContain('payload');
});

it('flags a polymorphic column pair for human review', function () {
    expect((new SensitiveColumnDetector)->needsReview(fixtureTable('comments')))->toContain('commentable_type');
});

it('detects a morph pair', function () {
    expect((new MorphPairDetector)->detect(fixtureTable('comments')))->toBe(['type' => 'commentable_type', 'id' => 'commentable_id'])
        ->and((new MorphPairDetector)->detect(fixtureTable('orders')))->toBeNull();
});

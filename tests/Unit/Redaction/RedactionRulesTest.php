<?php

declare(strict_types=1);

use DeadDrop\DeadDrop\Config\Reference;
use DeadDrop\DeadDrop\Config\TableClass;
use DeadDrop\DeadDrop\Config\TableConfig;
use DeadDrop\DeadDrop\Inference\EdgeSource;
use DeadDrop\DeadDrop\Redaction\RedactionRules;
use DeadDrop\DeadDrop\Schema\Introspector;
use DeadDrop\DeadDrop\Tests\Fixtures\SchemaBuilder;

beforeEach(fn () => SchemaBuilder::migrate('dd_test'));

function violationsFor(string $table, array $redact, array $references = [], TableClass $class = TableClass::Data): array
{
    $schema = app(Introspector::class)->inspect('dd_test')->table($table);
    $config = new TableConfig($table, $class, $schema->columnNames(), $references, $redact, null, null, null);

    return (new RedactionRules)->violations($config, $schema);
}

it('accepts a valid redaction map', function () {
    expect(violationsFor('users', ['email' => 'hash', 'password' => 'bcrypt:secret']))->toBe([]);
});

it('rejects review placeholders', function () {
    expect(violationsFor('users', ['email' => 'review']))->toBe(["users.email: 'review' must be replaced with a decision"]);
});

it('rejects redacting the primary key', function () {
    expect(violationsFor('users', ['id' => 'hash']))->toBe(['users.id: primary key columns cannot be redacted']);
});

it('rejects redacting a reference column', function () {
    $references = ['company_id' => new Reference(null, 'companies', 'id', true, EdgeSource::Guessed)];

    expect(violationsFor('users', ['company_id' => 'null'], $references))->toBe(['users.company_id: reference columns cannot be redacted']);
});

it('rejects null on a not null column', function () {
    expect(violationsFor('users', ['email' => 'null']))->toBe(["users.email: 'null' is not allowed on a NOT NULL column"]);
});

it('rejects scramble on a non date column', function () {
    expect(violationsFor('users', ['email' => 'scramble']))->toBe(["users.email: 'scramble' requires a date or datetime column"]);
});

it('rejects hash and mask on non string columns', function () {
    expect(violationsFor('orders', ['total' => 'hash']))->toBe(["orders.total: 'hash' requires a string column"])
        ->and(violationsFor('orders', ['created_at' => 'mask']))->toBe(["orders.created_at: 'mask' requires a string column"]);
});

it('rejects an unknown transformer and a missing column', function () {
    expect(violationsFor('users', ['email' => 'rot13', 'ghost' => 'hash']))->toBe([
        'users.email: unknown transformer [rot13]',
        'users.ghost: column does not exist',
    ]);
});

it('ignores skip tables', function () {
    expect(violationsFor('users', ['email' => 'review'], [], TableClass::Skip))->toBe([]);
});

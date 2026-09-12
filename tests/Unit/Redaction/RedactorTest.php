<?php

declare(strict_types=1);

use DeadDrop\DeadDrop\Config\TableClass;
use DeadDrop\DeadDrop\Config\TableConfig;
use DeadDrop\DeadDrop\Redaction\RedactionContext;
use DeadDrop\DeadDrop\Redaction\Redactor;
use DeadDrop\DeadDrop\Schema\Introspector;
use DeadDrop\DeadDrop\Tests\Fixtures\SchemaBuilder;

beforeEach(fn () => SchemaBuilder::migrate('dd_test'));

function usersRedactor(array $redact): Redactor
{
    $table = app(Introspector::class)->inspect('dd_test')->table('users');
    $config = new TableConfig('users', TableClass::Data, $table->columnNames(), [], $redact, null, null, null);

    return Redactor::forTable($config, $table, new RedactionContext(str_repeat('s', 32), 'example.test'));
}

it('redacts only the configured columns', function () {
    $row = ['id' => 10, 'company_id' => 1, 'email' => 'a@acme.test', 'password' => 'secret', 'created_by' => null, 'failed_job_id' => null];

    $out = usersRedactor(['email' => 'hash', 'password' => 'fixed:x'])->apply($row);

    expect($out['email'])->toMatch('/^[0-9a-f]{16}@example\.test$/')
        ->and($out['password'])->toBe('x')
        ->and($out['id'])->toBe(10)
        ->and($out['company_id'])->toBe(1)
        ->and(array_keys($out))->toBe(array_keys($row));
});

it('lists its redacted columns sorted', function () {
    expect(usersRedactor(['password' => 'fixed:x', 'email' => 'hash'])->columns())->toBe(['email', 'password']);
});

it('ignores a configured column missing from the row', function () {
    expect(usersRedactor(['email' => 'hash'])->apply(['id' => 1]))->toBe(['id' => 1]);
});

it('refuses to build from an invalid redaction map', function () {
    usersRedactor(['id' => 'hash']);
})->throws(InvalidArgumentException::class, 'primary key columns cannot be redacted');

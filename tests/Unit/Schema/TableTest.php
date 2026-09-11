<?php

declare(strict_types=1);

use DeadDrop\DeadDrop\Schema\Column;
use DeadDrop\DeadDrop\Schema\ColumnType;
use DeadDrop\DeadDrop\Schema\Index;
use DeadDrop\DeadDrop\Schema\Table;

function usersTable(array $indexes): Table
{
    return new Table(
        name: 'users',
        columns: [
            'id' => new Column('id', ColumnType::Integer, 'bigint', false, true, null),
            'email' => new Column('email', ColumnType::String, 'varchar', false, false, null),
        ],
        indexes: $indexes,
        foreignKeys: [],
        estimatedRows: 100,
        estimatedBytes: 4096,
    );
}

it('reports a single column primary key', function () {
    $table = usersTable([new Index('PRIMARY', ['id'], true, true)]);

    expect($table->primaryKey())->toBe('id')
        ->and($table->hasCompositePrimaryKey())->toBeFalse();
});

it('detects a composite primary key', function () {
    $table = usersTable([new Index('PRIMARY', ['id', 'email'], true, true)]);

    expect($table->hasCompositePrimaryKey())->toBeTrue()
        ->and($table->primaryKey())->toBeNull();
});

it('knows a column is unique', function () {
    $table = usersTable([
        new Index('PRIMARY', ['id'], true, true),
        new Index('users_email_unique', ['email'], true, false),
    ]);

    expect($table->isUnique('email'))->toBeTrue();
});

it('does not treat a column in a composite unique index as unique on its own', function () {
    $table = usersTable([
        new Index('PRIMARY', ['id'], true, true),
        new Index('users_email_team_unique', ['email', 'team_id'], true, false),
    ]);

    expect($table->isUnique('email'))->toBeFalse();
});

it('lists column names and looks a column up by name', function () {
    $table = usersTable([]);

    expect($table->columnNames())->toBe(['id', 'email'])
        ->and($table->column('email')?->type)->toBe(ColumnType::String)
        ->and($table->column('missing'))->toBeNull();
});

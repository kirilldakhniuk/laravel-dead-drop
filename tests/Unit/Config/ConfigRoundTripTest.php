<?php

declare(strict_types=1);

use DeadDrop\DeadDrop\Config\ConfigLoader;
use DeadDrop\DeadDrop\Config\ConfigRenderer;
use DeadDrop\DeadDrop\Config\ConnectionConfig;
use DeadDrop\DeadDrop\Config\Reference;
use DeadDrop\DeadDrop\Config\TableClass;
use DeadDrop\DeadDrop\Config\TableConfig;
use DeadDrop\DeadDrop\Inference\EdgeSource;

it('parses a rendered config back into an equal object', function () {
    $original = new ConnectionConfig('mysql', [
        'orders' => new TableConfig(
            name: 'orders',
            class: TableClass::Data,
            columns: ['id', 'company_id', 'created_by', 'created_at'],
            references: [
                'company_id' => new Reference(null, 'companies', 'id', true, EdgeSource::ForeignKey),
                'created_by' => new Reference('dd_test', 'users', 'id', false, EdgeSource::Guessed),
            ],
            redact: ['note' => 'null'],
            window: 'created_at',
            exclude: "status = 'draft'",
            morph: null,
        ),
        'comments' => new TableConfig('comments', TableClass::Data, ['id'], [], [], null, null, ['type' => 'commentable_type', 'id' => 'commentable_id']),
        'legacy' => new TableConfig('legacy', TableClass::Skip, ['id'], [], [], null, null, null, removed: true),
    ]);

    $directory = tempDirectory();
    file_put_contents($directory.'/mysql.php', (new ConfigRenderer)->render($original));

    $parsed = (new ConfigLoader)->load('mysql', $directory);

    expect($parsed)->toEqual($original);
});

it('renders tables alphabetically with keys in a fixed order', function () {
    $config = new ConnectionConfig('mysql', [
        'users' => new TableConfig('users', TableClass::Data, ['id', 'email'], [], ['email' => 'hash'], null, null, null),
        'companies' => new TableConfig('companies', TableClass::Lookup, ['id'], [], [], null, null, null),
    ]);

    $source = (new ConfigRenderer)->render($config);

    expect($source)->toStartWith("<?php\n\ndeclare(strict_types=1);\n\nreturn [\n    'companies' => [\n        'class' => 'lookup',\n        'columns' => ['id'],\n    ],\n    'users' => [\n        'class' => 'data',\n        'columns' => ['id', 'email'],\n        'redact' => [\n            'email' => 'hash',\n        ],\n    ],\n];\n");
});

it('accepts hand written shorthand references as manual', function () {
    $reference = Reference::fromArray('companies.id');

    expect($reference->table)->toBe('companies')
        ->and($reference->column)->toBe('id')
        ->and($reference->connection)->toBeNull()
        ->and($reference->descend)->toBeTrue()
        ->and($reference->source)->toBe(EdgeSource::Manual);
});

it('loads every connection file in a directory', function () {
    $directory = tempDirectory();
    file_put_contents($directory.'/a.php', "<?php\n\nreturn ['t' => ['class' => 'data', 'columns' => ['id']]];\n");
    file_put_contents($directory.'/b.php', "<?php\n\nreturn [];\n");

    $set = (new ConfigLoader)->loadAll($directory);

    expect($set->connections())->toBe(['a', 'b'])
        ->and($set->for('a')->table('t')->class)->toBe(TableClass::Data)
        ->and((new ConfigLoader)->load('missing', $directory))->toBeNull();
});

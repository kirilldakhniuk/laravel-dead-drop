<?php

declare(strict_types=1);

use DeadDrop\DeadDrop\Config\ConfigMerger;
use DeadDrop\DeadDrop\Config\ConnectionConfig;
use DeadDrop\DeadDrop\Config\TableClass;
use DeadDrop\DeadDrop\Inference\EdgeSource;

function connectionConfig(array $tables): ConnectionConfig
{
    foreach ($tables as $name => &$table) {
        $table['columns'] ??= ['id'];
    }

    return ConnectionConfig::fromArray('mysql', $tables);
}

it('preserves a human edited class', function () {
    $merged = (new ConfigMerger)->merge(
        connectionConfig(['audit_log' => ['class' => 'skip']]),
        connectionConfig(['audit_log' => ['class' => 'data']]),
    );

    expect($merged->table('audit_log')->class)->toBe(TableClass::Skip);
});

it('preserves a human edited redaction and adds new suggestions', function () {
    $merged = (new ConfigMerger)->merge(
        connectionConfig(['users' => ['class' => 'data', 'redact' => ['email' => 'keep']]]),
        connectionConfig(['users' => ['class' => 'data', 'redact' => ['email' => 'hash', 'phone' => 'mask']]]),
    );

    expect($merged->table('users')->redact)->toBe(['email' => 'keep', 'phone' => 'mask']);
});

it('preserves a descend false flag while upgrading the source', function () {
    $merged = (new ConfigMerger)->merge(
        connectionConfig(['users' => ['class' => 'data', 'references' => [
            'created_by' => ['users.id', 'descend' => false, 'source' => 'guessed'],
        ]]]),
        connectionConfig(['users' => ['class' => 'data', 'references' => [
            'created_by' => ['users.id', 'descend' => true, 'source' => 'fk'],
        ]]]),
    );

    $reference = $merged->table('users')->references['created_by'];

    expect($reference->descend)->toBeFalse()
        ->and($reference->source)->toBe(EdgeSource::ForeignKey);
});

it('keeps a human edited reference target and a manual reference nothing rediscovered', function () {
    $merged = (new ConfigMerger)->merge(
        connectionConfig(['orders' => ['class' => 'data', 'references' => [
            'buyer_id' => 'customers.id',
            'legacy_ref' => 'legacy.id',
        ]]]),
        connectionConfig(['orders' => ['class' => 'data', 'references' => [
            'buyer_id' => ['users.id', 'source' => 'guessed'],
        ]]]),
    );

    expect($merged->table('orders')->references['buyer_id']->table)->toBe('customers')
        ->and($merged->table('orders')->references['buyer_id']->source)->toBe(EdgeSource::Guessed)
        ->and($merged->table('orders')->references)->toHaveKey('legacy_ref');
});

it('takes columns from the discovered schema', function () {
    $merged = (new ConfigMerger)->merge(
        connectionConfig(['users' => ['class' => 'data', 'columns' => ['id', 'old']]]),
        connectionConfig(['users' => ['class' => 'data', 'columns' => ['id', 'new']]]),
    );

    expect($merged->table('users')->columns)->toBe(['id', 'new']);
});

it('fills a null window and morph from discovery but never overrides a human value', function () {
    $merged = (new ConfigMerger)->merge(
        connectionConfig(['orders' => ['class' => 'data', 'window' => 'shipped_at']]),
        connectionConfig(['orders' => ['class' => 'data', 'window' => 'created_at', 'morph' => ['type' => 'a_type', 'id' => 'a_id']]]),
    );

    expect($merged->table('orders')->window)->toBe('shipped_at')
        ->and($merged->table('orders')->morph)->toBe(['type' => 'a_type', 'id' => 'a_id']);
});

it('adds a newly discovered table', function () {
    $merged = (new ConfigMerger)->merge(
        connectionConfig(['users' => ['class' => 'data']]),
        connectionConfig(['users' => ['class' => 'data'], 'invoices' => ['class' => 'data']]),
    );

    expect($merged->table('invoices'))->not->toBeNull();
});

it('retains a vanished table and marks it removed', function () {
    $merged = (new ConfigMerger)->merge(
        connectionConfig(['users' => ['class' => 'data'], 'legacy' => ['class' => 'data']]),
        connectionConfig(['users' => ['class' => 'data']]),
    );

    expect($merged->table('legacy')->removed)->toBeTrue()
        ->and($merged->table('users')->removed)->toBeFalse();
});

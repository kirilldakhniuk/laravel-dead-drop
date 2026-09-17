<?php

declare(strict_types=1);

use DeadDrop\DeadDrop\Artifacts\Manifest;
use DeadDrop\DeadDrop\Artifacts\TableManifest;

function sampleManifest(): Manifest
{
    return new Manifest(
        id: '20260912-141500-a8k2zq',
        status: Manifest::STATUS_WRITING,
        createdAt: '2026-09-12T14:15:00+00:00',
        packageVersion: 'dev',
        root: 'dd_test.companies:1',
        since: null,
        executor: 'php',
        connections: ['dd_test' => ['driver' => 'sqlite', 'database' => 'app']],
        tables: [new TableManifest('dd_test', 'companies', 'dd_test.companies.ndjson.gz', 'ndjson', 3, 120, 'id', [['name' => 'id', 'type' => 'int'], ['name' => 'name', 'type' => 'string']], [])],
        unresolved: [['connection' => 'dd_test', 'table' => 'users', 'column' => 'failed_job_id', 'reason' => 'target table failed_jobs is skipped']],
    );
}

it('round trips through an array', function () {
    $manifest = sampleManifest();

    expect(Manifest::fromArray($manifest->toArray()))->toEqual($manifest)
        ->and($manifest->toArray()['version'])->toBe(1);
});

it('flips status and appends tables immutably', function () {
    $manifest = sampleManifest();
    $complete = $manifest->withStatus(Manifest::STATUS_COMPLETE)->withTable(new TableManifest('dd_test', 'users', 'dd_test.users.ndjson.gz', 'ndjson', 2, 80, 'id', [], ['email']));

    expect($manifest->isComplete())->toBeFalse()
        ->and($complete->isComplete())->toBeTrue()
        ->and(count($complete->tables))->toBe(2)
        ->and(count($manifest->tables))->toBe(1)
        ->and($complete->totalRows())->toBe(5)
        ->and($complete->totalBytes())->toBe(200);
});

it('reads a connection written before the source database name was recorded', function () {
    $raw = sampleManifest()->toArray();
    $raw['connections'] = ['dd_test' => ['driver' => 'sqlite']];

    expect(Manifest::fromArray($raw)->connections)->toBe(['dd_test' => ['driver' => 'sqlite', 'database' => null]]);
});

it('rejects a manifest of another version', function () {
    Manifest::fromArray(['version' => 2] + sampleManifest()->toArray());
})->throws(InvalidArgumentException::class);

it('generates sortable ids', function () {
    $id = Manifest::newId(new DateTimeImmutable('2026-09-12 14:15:00', new DateTimeZone('UTC')));

    expect($id)->toMatch('/^20260912-141500-[a-z0-9]{6}$/');
});

<?php

declare(strict_types=1);

use DeadDrop\DeadDrop\Artifacts\ArtifactReader;
use DeadDrop\DeadDrop\Artifacts\ArtifactWriter;
use DeadDrop\DeadDrop\Artifacts\TableManifest;
use DeadDrop\DeadDrop\Config\ConfigLoader;
use DeadDrop\DeadDrop\Extraction\PhpExecutor;
use DeadDrop\DeadDrop\Planning\KeySetRepository;
use DeadDrop\DeadDrop\Planning\PlanStep;
use DeadDrop\DeadDrop\Redaction\RedactionContext;
use DeadDrop\DeadDrop\Redaction\Redactor;
use DeadDrop\DeadDrop\Schema\Index;
use DeadDrop\DeadDrop\Schema\Introspector;
use DeadDrop\DeadDrop\Schema\Table;
use DeadDrop\DeadDrop\Tests\Fixtures\SchemaBuilder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

beforeEach(function () {
    SchemaBuilder::migrate('dd_test');
    SchemaBuilder::seedTwoCompanies('dd_test');
    fakeArtifactDisk();
});

it('exports the rows of a plan step joined to its key set with redaction applied', function () {
    $path = initFixtureConfig();
    $result = traverseFixture('dd_test.companies:1', $path);
    $keys = app(KeySetRepository::class)->get('dd_test', 'users');
    $table = app(Introspector::class)->inspect('dd_test')->table('users');
    $config = (new ConfigLoader)->loadAll($path)->for('dd_test')->table('users');
    $writer = new ArtifactWriter(Storage::disk('local'), 'dead-drops', 'test-id');
    $redactor = Redactor::forTable($config, $table, new RedactionContext(str_repeat('s', 32), 'example.test'));

    $artifact = (new PhpExecutor)->export(new PlanStep('dd_test', 'users', $keys->tableName, $keys->count(), 0), $table, $config, $keys, $redactor, $writer);

    $manifest = new TableManifest('dd_test', 'users', $artifact->file, $artifact->format, $artifact->rows, $artifact->bytes, 'id', array_map(fn ($c) => ['name' => $c->name, 'type' => $c->type->value], array_values($table->columns)), $redactor->columns());
    $rows = iterator_to_array((new ArtifactReader(Storage::disk('local'), 'dead-drops'))->rows('test-id', $manifest), false);

    expect($artifact->format)->toBe('ndjson')
        ->and($artifact->rows)->toBe(2)
        ->and(array_column($rows, 'id'))->toBe([10, 50])
        ->and($rows[0]['email'])->toMatch('/^[0-9a-f]{16}@example\.test$/')
        ->and($rows[0]['company_id'])->toBe(1);
});

it('exports a whole lookup table when no key set is given', function () {
    $path = initFixtureConfig();
    $table = app(Introspector::class)->inspect('dd_test')->table('countries');
    $config = (new ConfigLoader)->loadAll($path)->for('dd_test')->table('countries');
    $writer = new ArtifactWriter(Storage::disk('local'), 'dead-drops', 'test-id');
    $redactor = Redactor::forTable($config, $table, new RedactionContext(str_repeat('s', 32), 'example.test'));

    $artifact = (new PhpExecutor)->export(new PlanStep('dd_test', 'countries', null, 2, 0), $table, $config, null, $redactor, $writer);

    expect($artifact->rows)->toBe(2);
});

it('supports every bundled database driver', function () {
    foreach (['mysql', 'mariadb', 'pgsql', 'sqlite'] as $driver) {
        expect((new PhpExecutor)->supports($driver))->toBeTrue();
    }
    expect((new PhpExecutor)->supports('sqlsrv'))->toBeFalse();
});

it('aborts the table file when reading fails', function () {
    $path = initFixtureConfig();
    traverseFixture('dd_test.companies:1', $path);
    $keys = app(KeySetRepository::class)->get('dd_test', 'users');
    $real = app(Introspector::class)->inspect('dd_test')->table('users');
    $table = new Table('users', $real->columns, [new Index('bogus', ['no_such_column'], true, true)], [], 0, 0);
    $config = (new ConfigLoader)->loadAll($path)->for('dd_test')->table('users');
    $writer = new ArtifactWriter(Storage::disk('local'), 'dead-drops', 'test-id');
    $redactor = Redactor::forTable($config, $table, new RedactionContext(str_repeat('s', 32), 'example.test'));
    $step = new PlanStep('dd_test', 'users', $keys->tableName, $keys->count(), 0);

    // Pinning the staging file's name names the one file this assertion is
    // about: globbing the shared temp dir also sees the files the other
    // parallel workers are writing at that moment.
    Str::createRandomStringsUsing(fn (): string => 'executor-abort-staging');
    $staging = sys_get_temp_dir().'/dead-drop-executor-abort-staging';
    $error = null;

    // The exception is held rather than asserted on straight away: releasing
    // it drops the last reference to the writer, whose destructor would clean
    // the staging file up and make this pass without an explicit abort.
    try {
        (new PhpExecutor)->export($step, $table, $config, $keys, $redactor, $writer);
    } catch (Throwable $error) {
    } finally {
        Str::createRandomStringsNormally();
    }

    expect(is_file($staging))->toBeFalse()
        ->and($error)->toBeInstanceOf(QueryException::class)
        ->and(Storage::disk('local')->exists('dead-drops/test-id/dd_test.users.ndjson.gz'))->toBeFalse();
});

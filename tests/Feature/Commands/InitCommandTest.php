<?php

declare(strict_types=1);

use DeadDrop\DeadDrop\Tests\Fixtures\SchemaBuilder;
use Illuminate\Support\Facades\Schema;

beforeEach(fn () => SchemaBuilder::migrate('dd_test'));

it('writes a config file per connection without interaction', function () {
    $path = tempDirectory();

    $this->artisan('dead-drop:init', ['--connection' => ['dd_test'], '--path' => $path, '--no-interaction' => true])
        ->assertSuccessful();

    expect($path.'/dd_test.php')->toBeFile();
});

it('classifies known tables in the written config', function () {
    SchemaBuilder::seedTwoCompanies('dd_test');

    $config = require initFixtureConfig().'/dd_test.php';

    expect($config['failed_jobs']['class'])->toBe('skip')
        ->and($config['countries']['class'])->toBe('data')
        ->and($config['orders']['class'])->toBe('data')
        ->and($config['companies']['class'])->toBe('data');
});

it('records edge sources, redactions, windows and morphs in the written config', function () {
    $config = require initFixtureConfig().'/dd_test.php';

    expect($config['orders']['references']['company_id'])->toBe(['companies.id', 'source' => 'fk'])
        ->and($config['order_items']['references']['order_id'])->toBe(['orders.id', 'source' => 'guessed'])
        ->and($config['users']['references']['created_by'])->toBe(['users.id', 'descend' => false, 'source' => 'guessed'])
        ->and($config['users']['redact']['email'])->toBe('hash')
        ->and($config['orders']['window'])->toBe('created_at')
        ->and($config['comments']['morph'])->toBe(['type' => 'commentable_type', 'id' => 'commentable_id'])
        ->and($config['failed_jobs'])->toBe(['class' => 'skip']);
});

it('hands back a suggestion its own gate would reject as review', function () {
    // `*_key` suggests `null`, which the gate refuses on a NOT NULL column —
    // writing it would mean init produced a config check and dump both fail.
    Schema::connection('dd_test')->table('companies', fn ($t) => $t->string('api_key')->default(''));

    $config = require initFixtureConfig().'/dd_test.php';

    expect($config['companies']['redact']['api_key'])->toBe('review');
});

it('writes no redact entry for a key column with a sensitive name', function () {
    // `api_key` suggests `null`, but a primary key can carry no entry at all —
    // writing one would leave the column with no state check accepts.
    Schema::connection('dd_test')->create('licences', function ($t) {
        $t->string('api_key')->primary();
        $t->string('name');
    });

    $path = initFixtureConfig();

    expect((require $path.'/dd_test.php')['licences'])->not->toHaveKey('redact');

    $this->artisan('dead-drop:check', ['--connection' => ['dd_test'], '--path' => $path])->assertExitCode(0);
});

it('forces tables named in --skip to skip', function () {
    $path = tempDirectory();

    $this->artisan('dead-drop:init', ['--connection' => ['dd_test'], '--skip' => ['countries'], '--path' => $path, '--no-interaction' => true]);

    expect((require $path.'/dd_test.php')['countries']['class'])->toBe('skip');
});

it('preserves a human edit when re-run', function () {
    $path = initFixtureConfig();
    $file = $path.'/dd_test.php';

    file_put_contents($file, str_replace("'orders' => [\n        'class' => 'data',", "'orders' => [\n        'class' => 'skip',", file_get_contents($file)));

    $this->artisan('dead-drop:init', ['--connection' => ['dd_test'], '--path' => $path, '--no-interaction' => true]);

    expect((require $file)['orders']['class'])->toBe('skip');
});

it('fails without a connection when non-interactive', function () {
    $this->artisan('dead-drop:init', ['--no-interaction' => true])->assertFailed();
});

it('fails with a clear message for an unknown connection', function () {
    $path = tempDirectory();

    $this->artisan('dead-drop:init', ['--connection' => ['nope'], '--path' => $path, '--no-interaction' => true])
        ->expectsOutputToContain('Unknown database connection [nope]')
        ->assertFailed();

    expect($path.'/nope.php')->not->toBeFile();
});

it('fails when the config directory cannot be created', function () {
    $blocker = tempDirectory().'/blocker';
    file_put_contents($blocker, '');

    $this->artisan('dead-drop:init', ['--connection' => ['dd_test'], '--path' => $blocker.'/nested', '--no-interaction' => true])
        ->expectsOutputToContain('Could not create directory')
        ->assertFailed();
});

it('names an unreadable connection instead of crashing on the interactive path', function () {
    // A stock app's connection list is not a list of connections DeadDrop can
    // read: `sqlsrv` is not supported at all, and `pgsql` is usually not up.
    config(['database.connections' => [
        'dd_test' => config('database.connections.dd_test'),
        'broken' => ['driver' => 'sqlsrv', 'host' => 'nowhere', 'database' => 'x'],
    ]]);

    $path = tempDirectory();

    $this->artisan('dead-drop:init', ['--path' => $path])
        ->expectsOutputToContain('broken (unavailable: Unsupported database driver [sqlsrv].)')
        ->expectsChoice('Which connections should DeadDrop enroll?', ['dd_test'], ['dd_test' => 'dd_test (8 tables)'])
        ->expectsQuestion('Any large tables to skip?', [])
        ->assertSuccessful();

    expect($path.'/dd_test.php')->toBeFile()
        ->and($path.'/broken.php')->not->toBeFile();
});

it('forces a table chosen at the skip prompt to skip', function () {
    config(['database.connections' => ['dd_test' => config('database.connections.dd_test')]]);

    $path = tempDirectory();

    $this->artisan('dead-drop:init', ['--path' => $path])
        ->expectsQuestion('Which connections should DeadDrop enroll?', ['dd_test'])
        ->expectsQuestion('Any large tables to skip?', ['dd_test.countries'])
        ->assertSuccessful();

    expect((require $path.'/dd_test.php')['countries']['class'])->toBe('skip');
});

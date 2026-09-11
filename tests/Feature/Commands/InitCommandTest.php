<?php

declare(strict_types=1);

use DeadDrop\DeadDrop\Tests\Fixtures\SchemaBuilder;

beforeEach(fn () => SchemaBuilder::migrate('dd_test'));

it('writes a config file per connection without interaction', function () {
    $path = tempDirectory();

    $this->artisan('dead-drop:init', ['--connection' => ['dd_test'], '--path' => $path, '--no-interaction' => true])
        ->assertSuccessful();

    expect($path.'/dd_test.php')->toBeFile();
});

it('classifies known tables in the written config', function () {
    $config = require initFixtureConfig().'/dd_test.php';

    expect($config['failed_jobs']['class'])->toBe('skip')
        ->and($config['countries']['class'])->toBe('lookup')
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
        ->and($config['failed_jobs'])->toBe(['class' => 'skip', 'columns' => ['id', 'payload']]);
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

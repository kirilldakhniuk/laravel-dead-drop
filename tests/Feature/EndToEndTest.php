<?php

declare(strict_types=1);

use DeadDrop\DeadDrop\Tests\Fixtures\SchemaBuilder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

beforeEach(function () {
    SchemaBuilder::migrate('dd_test');
    SchemaBuilder::seedTwoCompanies('dd_test');
    SchemaBuilder::migrate('dd_target');
    fakeArtifactDisk();
    config()->set('dead-drop.pull.allow_environments', ['testing']);
});

it('goes from init through check, dump and pull to a redacted, referentially complete target', function () {
    $path = tempDirectory();

    $this->artisan('dead-drop:init', ['--connection' => ['dd_test'], '--path' => $path, '--no-interaction' => true])->assertSuccessful();
    $this->artisan('dead-drop:check', ['--connection' => ['dd_test'], '--path' => $path])->assertExitCode(0);
    $this->artisan('dead-drop:dump', ['--connection' => 'dd_test', '--path' => $path, '--disk' => 'local', '--no-interaction' => true])->assertSuccessful();
    $this->artisan('dead-drop:pull', ['--connection' => 'dd_target', '--disk' => 'local', '--force' => true])->assertSuccessful();

    $source = DB::connection('dd_test');
    $target = DB::connection('dd_target');

    // Every dumpable table arrives whole, and the tables nobody dumps are
    // left alone.
    foreach (['companies', 'users', 'customers', 'orders', 'order_items', 'countries'] as $table) {
        expect($target->table($table)->count())->toBe($source->table($table)->count());
    }

    expect($target->table('failed_jobs')->count())->toBe(0);

    // Every table is taken whole, so nothing can be left dangling.
    foreach ($target->table('order_items')->get() as $item) {
        expect($target->table('orders')->where('id', $item->order_id)->exists())->toBeTrue();
    }

    // redaction
    $user = $target->table('users')->where('id', 10)->first();
    expect($user->email)->toMatch('/^[0-9a-f]{16}@example\.test$/')
        ->and(Hash::check('secret', $user->password))->toBeTrue()
        ->and($target->table('companies')->where('id', 1)->value('stripe_id'))->toBe('redacted')
        ->and($target->table('customers')->where('id', 7)->value('email'))->not->toBe('seven@example.test');

    // source untouched
    expect($source->table('users')->where('id', 10)->value('email'))->toBe('a@acme.test');
});

it('is idempotent across two pulls', function () {
    $path = initFixtureConfig();
    dumpFixture($path);

    $this->artisan('dead-drop:pull', ['--connection' => 'dd_target', '--disk' => 'local', '--force' => true])->assertSuccessful();
    $this->artisan('dead-drop:pull', ['--connection' => 'dd_target', '--disk' => 'local', '--force' => true])->assertSuccessful();

    expect(DB::connection('dd_target')->table('orders')->count())->toBe(3)
        ->and(DB::connection('dd_target')->table('users')->count())->toBe(3);
});

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
    $this->artisan('dead-drop:dump', ['table' => 'companies', 'ids' => ['1'], '--connection' => 'dd_test', '--path' => $path, '--disk' => 'local'])->assertSuccessful();
    $this->artisan('dead-drop:pull', ['--connection' => 'dd_target', '--disk' => 'local', '--force' => true])->assertSuccessful();

    $target = DB::connection('dd_target');

    // collected set (company 2 arrives by ascent: user 50 belongs to it and placed order 1)
    expect($target->table('companies')->orderBy('id')->pluck('id')->all())->toBe([1, 2])
        ->and($target->table('orders')->orderBy('id')->pluck('id')->all())->toBe([1, 2])
        ->and($target->table('order_items')->orderBy('id')->pluck('id')->all())->toBe([1, 2])
        ->and($target->table('users')->orderBy('id')->pluck('id')->all())->toBe([10, 50])
        ->and($target->table('customers')->pluck('id')->all())->toBe([7])
        ->and($target->table('countries')->count())->toBe(2)
        ->and($target->table('failed_jobs')->count())->toBe(0);

    // referential completeness on the target
    foreach ($target->table('orders')->get() as $order) {
        expect($target->table('companies')->where('id', $order->company_id)->exists())->toBeTrue()
            ->and($target->table('users')->where('id', $order->user_id)->exists())->toBeTrue();
    }

    // redaction
    $user = $target->table('users')->where('id', 10)->first();
    expect($user->email)->toMatch('/^[0-9a-f]{16}@example\.test$/')
        ->and(Hash::check('secret', $user->password))->toBeTrue()
        ->and($target->table('companies')->where('id', 1)->value('stripe_id'))->toBe('redacted')
        ->and($target->table('customers')->where('id', 7)->value('email'))->not->toBe('seven@example.test');

    // source untouched
    expect(DB::connection('dd_test')->table('users')->where('id', 10)->value('email'))->toBe('a@acme.test');
});

it('is idempotent across two pulls', function () {
    $path = initFixtureConfig();
    dumpFixture('dd_test.companies:1', $path);

    $this->artisan('dead-drop:pull', ['--connection' => 'dd_target', '--disk' => 'local', '--force' => true])->assertSuccessful();
    $this->artisan('dead-drop:pull', ['--connection' => 'dd_target', '--disk' => 'local', '--force' => true])->assertSuccessful();

    expect(DB::connection('dd_target')->table('orders')->count())->toBe(2)
        ->and(DB::connection('dd_target')->table('users')->count())->toBe(2);
});

it('round trips a whole database dump into the target', function () {
    $path = tempDirectory();

    $this->artisan('dead-drop:init', ['--connection' => ['dd_test'], '--path' => $path, '--no-interaction' => true])->assertSuccessful();
    $this->artisan('dead-drop:check', ['--connection' => ['dd_test'], '--path' => $path])->assertExitCode(0);
    $this->artisan('dead-drop:dump', ['--all' => true, '--connection' => 'dd_test', '--path' => $path, '--disk' => 'local'])->assertSuccessful();
    $this->artisan('dead-drop:pull', ['--connection' => 'dd_target', '--disk' => 'local', '--force' => true])->assertSuccessful();

    $source = DB::connection('dd_test');
    $target = DB::connection('dd_target');

    foreach (['companies', 'users', 'customers', 'orders', 'order_items', 'countries'] as $table) {
        expect($target->table($table)->count())->toBe($source->table($table)->count());
    }

    // Every table is taken whole, so nothing can be left dangling.
    foreach ($target->table('order_items')->get() as $item) {
        expect($target->table('orders')->where('id', $item->order_id)->exists())->toBeTrue();
    }

    // A whole-database dump is still a redacted one, and still leaves the
    // tables nobody dumps alone.
    expect($target->table('users')->where('id', 10)->value('email'))->toMatch('/^[0-9a-f]{16}@example\.test$/')
        ->and($target->table('failed_jobs')->count())->toBe(0);
});

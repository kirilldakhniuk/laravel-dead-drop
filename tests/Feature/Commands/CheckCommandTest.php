<?php

declare(strict_types=1);

use DeadDrop\DeadDrop\Tests\Fixtures\SchemaBuilder;
use Illuminate\Support\Facades\Schema;

beforeEach(fn () => SchemaBuilder::migrate('dd_test'));

it('passes when the config matches the schema', function () {
    $path = initFixtureConfig();

    $this->artisan('dead-drop:check', ['--connection' => ['dd_test'], '--path' => $path])->assertExitCode(0);
});

it('fails and names a table added after init', function () {
    $path = initFixtureConfig();

    Schema::connection('dd_test')->create('invoices', function ($t) {
        $t->id();
        $t->unsignedBigInteger('company_id');
    });

    $this->artisan('dead-drop:check', ['--connection' => ['dd_test'], '--path' => $path])
        ->expectsOutputToContain('invoices')
        ->assertExitCode(1);
});

it('fails and names a column added after init', function () {
    $path = initFixtureConfig();

    Schema::connection('dd_test')->table('orders', fn ($t) => $t->string('reference')->nullable());

    $this->artisan('dead-drop:check', ['--connection' => ['dd_test'], '--path' => $path])
        ->expectsOutputToContain('orders.reference')
        ->assertExitCode(1);
});

it('fails when a sensitive column has no redaction decision', function () {
    $path = initFixtureConfig();

    Schema::connection('dd_test')->table('orders', fn ($t) => $t->string('billing_email')->nullable());

    $this->artisan('dead-drop:check', ['--connection' => ['dd_test'], '--path' => $path])
        ->expectsOutputToContain('billing_email')
        ->assertExitCode(1);
});

it('fails when a review placeholder was left in place', function () {
    $path = initFixtureConfig();
    $file = $path.'/dd_test.php';

    file_put_contents($file, str_replace("'email' => 'hash',", "'email' => 'review',", file_get_contents($file)));

    $this->artisan('dead-drop:check', ['--connection' => ['dd_test'], '--path' => $path])
        ->expectsOutputToContain('users.email')
        ->assertExitCode(1);
});

it('fails when a connection has no config file', function () {
    $this->artisan('dead-drop:check', ['--connection' => ['dd_test'], '--path' => tempDirectory()])
        ->expectsOutputToContain('dead-drop:init')
        ->assertExitCode(1);
});

it('fails when the directory holds no config at all', function () {
    $path = tempDirectory();

    $this->artisan('dead-drop:check', ['--path' => $path])
        ->expectsOutputToContain("No DeadDrop config found in [{$path}] — run dead-drop:init")
        ->assertExitCode(1);
});

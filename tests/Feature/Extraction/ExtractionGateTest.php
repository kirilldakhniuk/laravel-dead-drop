<?php

declare(strict_types=1);

use DeadDrop\DeadDrop\Config\ConfigLoader;
use DeadDrop\DeadDrop\Extraction\ExtractionGate;
use DeadDrop\DeadDrop\Planning\Root;
use DeadDrop\DeadDrop\Redaction\SaltResolver;
use DeadDrop\DeadDrop\Schema\Introspector;
use DeadDrop\DeadDrop\Schema\SchemaSet;
use DeadDrop\DeadDrop\Tests\Fixtures\SchemaBuilder;
use Illuminate\Support\Facades\Schema;

beforeEach(function () {
    SchemaBuilder::migrate('dd_test');
    SchemaBuilder::seedTwoCompanies('dd_test');
});

function gateCheck(string $configDirectory, string $root = 'dd_test.companies:1', ?string $salt = null, ?string $configuredSalt = null): array
{
    $config = (new ConfigLoader)->loadAll($configDirectory);
    $schemas = new SchemaSet(['dd_test' => app(Introspector::class)->inspect('dd_test')]);

    return app(ExtractionGate::class)->check(Root::parse($root), $config, $schemas, $salt ?? str_repeat('s', 32), $configuredSalt)->lines();
}

it('passes a clean config with a valid root', function () {
    expect(gateCheck(initFixtureConfig()))->toBe([]);
});

it('fails on drift', function () {
    $path = initFixtureConfig();
    Schema::connection('dd_test')->table('orders', fn ($t) => $t->string('reference')->nullable());

    expect(gateCheck($path))->toContain('dd_test: New columns (not in config):');
});

it('fails on a review placeholder', function () {
    $path = initFixtureConfig();
    file_put_contents($path.'/dd_test.php', str_replace("'email' => 'hash',", "'email' => 'review',", file_get_contents($path.'/dd_test.php')));

    $lines = gateCheck($path);

    expect(implode("\n", $lines))->toContain('users.email');
});

it('fails when the configured salt is set but too short', function () {
    expect(gateCheck(initFixtureConfig(), salt: 'short', configuredSalt: 'short'))->toContain('redaction.salt must be at least 16 characters (DEAD_DROP_REDACTION_SALT)');
});

it('passes when the salt is null but the app key is set', function () {
    config()->set('app.key', 'base64:some-app-key');

    expect(gateCheck(initFixtureConfig(), salt: SaltResolver::resolve(null, config('app.key'))))->toBe([]);
});

it('fails with the zero-configuration message when both the salt and the app key are empty', function () {
    config()->set('app.key', '');
    config()->set('dead-drop.redaction.salt', null);

    $salt = SaltResolver::resolve(config('dead-drop.redaction.salt'), config('app.key'));

    expect(gateCheck(initFixtureConfig(), salt: (string) $salt))->toContain('redaction.salt is not set and APP_KEY is empty; set DEAD_DROP_REDACTION_SALT (generate one with: openssl rand -hex 16)');
});

it('fails on an invalid redaction placement', function () {
    $path = initFixtureConfig();
    file_put_contents($path.'/dd_test.php', str_replace("'email' => 'hash',\n            'password' => 'bcrypt:secret',", "'email' => 'null',\n            'password' => 'bcrypt:secret',", file_get_contents($path.'/dd_test.php')));

    expect(gateCheck($path))->toContain("users.email: 'null' is not allowed on a NOT NULL column");
});

it('fails on a root id that does not exist', function () {
    expect(gateCheck(initFixtureConfig(), 'dd_test.companies:1,999'))->toBe(['Root id 999 does not exist in dd_test.companies']);
});

it('checks a whole database dump without looking for a root row', function () {
    // There is no root row to verify, and `*` is not a table: a gate that
    // still ran the root-id check would report it as having no primary key.
    expect(gateCheck(initFixtureConfig(), 'dd_test:*'))->toBe([]);
});

it('still fails a whole database dump on drift', function () {
    $path = initFixtureConfig();
    Schema::connection('dd_test')->table('orders', fn ($t) => $t->string('reference')->nullable());

    expect(gateCheck($path, 'dd_test:*'))->toContain('dd_test: New columns (not in config):');
});

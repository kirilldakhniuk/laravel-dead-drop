<?php

declare(strict_types=1);

use DeadDrop\DeadDrop\Config\Reference;
use DeadDrop\DeadDrop\Config\TableClass;
use DeadDrop\DeadDrop\Config\TableConfig;
use DeadDrop\DeadDrop\Inference\EdgeSource;
use DeadDrop\DeadDrop\Redaction\RedactionRules;
use DeadDrop\DeadDrop\Schema\Introspector;
use DeadDrop\DeadDrop\Tests\Fixtures\SchemaBuilder;
use Illuminate\Support\Facades\DB;

beforeEach(fn () => SchemaBuilder::migrate('dd_test'));

function violationsFor(string $table, array $redact, array $references = [], TableClass $class = TableClass::Data): array
{
    $schema = app(Introspector::class)->inspect('dd_test')->table($table);
    $config = new TableConfig($table, $class, $schema->columnNames(), $references, $redact, null, null, null);

    return (new RedactionRules)->violations($config, $schema);
}

/**
 * SQLite's own grammar never writes a length (`string('code', 10)` is just
 * `varchar`), so the columns whose declared width the rules judge are
 * declared by hand.
 */
function badgesTable(): void
{
    $db = DB::connection('dd_test');

    $db->statement('CREATE TABLE badges (id integer primary key autoincrement, code varchar(10) not null, label varchar(10) not null, email varchar(255) not null, tight_email varchar(25) not null, short_email varchar(15) not null, seen_at time not null)');
    $db->statement('CREATE UNIQUE INDEX badges_code_unique ON badges (code)');
    $db->statement('CREATE UNIQUE INDEX badges_email_unique ON badges (email)');
    $db->statement('CREATE UNIQUE INDEX badges_tight_email_unique ON badges (tight_email)');
}

it('accepts a valid redaction map', function () {
    expect(violationsFor('users', ['email' => 'hash', 'password' => 'bcrypt:secret']))->toBe([]);
});

it('rejects review placeholders', function () {
    expect(violationsFor('users', ['email' => 'review']))->toBe(["users.email: 'review' must be replaced with a decision"]);
});

it('rejects redacting the primary key', function () {
    // Including a `review` placeholder: there is no decision to replace it
    // with, so the entry itself has to go.
    expect(violationsFor('users', ['id' => 'hash']))->toBe(['users.id: primary key columns cannot be redacted'])
        ->and(violationsFor('users', ['id' => 'review']))->toBe(['users.id: primary key columns cannot be redacted'])
        ->and(violationsFor('users', ['id' => 'keep']))->toBe(['users.id: primary key columns cannot be redacted']);
});

it('rejects redacting a reference column', function () {
    $references = ['company_id' => new Reference(null, 'companies', 'id', true, EdgeSource::Guessed)];

    expect(violationsFor('users', ['company_id' => 'null'], $references))->toBe(['users.company_id: reference columns cannot be redacted'])
        ->and(violationsFor('users', ['company_id' => 'review'], $references))->toBe(['users.company_id: reference columns cannot be redacted']);
});

it('rejects null on a not null column', function () {
    expect(violationsFor('users', ['email' => 'null']))->toBe(["users.email: 'null' is not allowed on a NOT NULL column"]);
});

it('rejects scramble on a non date column', function () {
    expect(violationsFor('users', ['email' => 'scramble']))->toBe(["users.email: 'scramble' requires a date or datetime column"]);
});

it('rejects hash and mask on non string columns', function () {
    expect(violationsFor('orders', ['total' => 'hash']))->toBe(["orders.total: 'hash' requires a string column"])
        ->and(violationsFor('orders', ['created_at' => 'mask']))->toBe(["orders.created_at: 'mask' requires a string column"]);
});

it('rejects an unknown transformer and a missing column', function () {
    expect(violationsFor('users', ['email' => 'rot13', 'ghost' => 'hash']))->toBe([
        'users.email: unknown transformer [rot13]',
        'users.ghost: column does not exist',
    ]);
});

it('rejects a null spec with an argument on a not null column', function () {
    expect(violationsFor('users', ['email' => 'null:x']))->toBe(["users.email: 'null' is not allowed on a NOT NULL column"]);
});

it('rejects scramble on a time column', function () {
    // `time` and `year` normalise to the same ColumnType as `date`, but
    // neither carries a date an offset could move.
    badgesTable();

    expect(violationsFor('badges', ['seen_at' => 'scramble']))->toBe(["badges.seen_at: 'scramble' requires a date or datetime column"]);
});

it('rejects a truncated hash on a unique column', function () {
    badgesTable();

    expect(violationsFor('badges', ['code' => 'hash']))
        ->toBe(["badges.code: 'hash' on a unique column needs a declared length of at least 32; [varchar(10)] is too short"])
        ->and(violationsFor('badges', ['tight_email' => 'hash']))
        ->toBe(["badges.tight_email: 'hash' on a unique column needs a declared length of at least 29; [varchar(25)] is too short"])
        ->and(violationsFor('badges', ['email' => 'hash']))->toBe([])
        ->and(violationsFor('badges', ['label' => 'hash']))->toBe([]);
});

it('rejects a hash on an email column too narrow to hold an address', function () {
    badgesTable();

    expect(violationsFor('badges', ['short_email' => 'hash']))
        ->toBe(["badges.short_email: 'hash' needs a declared length of at least 21 for an email address"]);
});

it('ignores skip tables', function () {
    expect(violationsFor('users', ['email' => 'review'], [], TableClass::Skip))->toBe([]);
});

<?php

declare(strict_types=1);

use DeadDrop\DeadDrop\Redaction\RedactionContext;
use DeadDrop\DeadDrop\Redaction\TransformerFactory;
use DeadDrop\DeadDrop\Redaction\Transformers\ScrambleTransformer;
use DeadDrop\DeadDrop\Schema\Column;
use DeadDrop\DeadDrop\Schema\ColumnType;
use Illuminate\Support\Facades\Hash;

function redactionContext(): RedactionContext
{
    return new RedactionContext(str_repeat('s', 32), 'example.test');
}

function stringColumn(string $name, string $native = 'varchar(255)'): Column
{
    return new Column($name, ColumnType::String, $native, true, false, null);
}

function transformerFor(string $spec, Column $column, string $pk = 'id')
{
    return (new TransformerFactory)->make($spec, $column, $pk, redactionContext());
}

it('hashes a value with the salt as lowercase hex sha256', function () {
    $out = transformerFor('hash', stringColumn('ssn'))->apply('123-45-6789', ['id' => 1]);

    expect($out)->toBe(hash('sha256', str_repeat('s', 32).'123-45-6789'))
        ->and($out)->toMatch('/^[0-9a-f]{64}$/');
});

it('truncates a hash to the declared column length', function () {
    expect(transformerFor('hash', stringColumn('code', 'varchar(20)'))->apply('abc', ['id' => 1]))->toHaveLength(20);
});

it('hashes an email column into a reserved domain address', function () {
    $out = transformerFor('hash', stringColumn('email'))->apply('a@acme.test', ['id' => 1]);

    expect($out)->toMatch('/^[0-9a-f]{16}@example\.test$/')
        ->and(transformerFor('hash', stringColumn('billing_email'))->apply('a@acme.test', ['id' => 1]))->toBe($out);
});

it('shortens the hex of an email hash to fit a narrow column', function () {
    // 21 characters is 8 hex + '@' + 'example.test' — the shortest address
    // the rules allow — and the result still has to be one address, not a
    // domain cut in half.
    expect(transformerFor('hash', stringColumn('email', 'varchar(21)'))->apply('a@acme.test', ['id' => 1]))
        ->toMatch('/^[0-9a-f]{8}@example\.test$/')
        ->and(transformerFor('hash', stringColumn('email', 'varchar(25)'))->apply('a@acme.test', ['id' => 1]))
        ->toMatch('/^[0-9a-f]{12}@example\.test$/')
        ->and(transformerFor('hash', stringColumn('email', 'varchar(40)'))->apply('a@acme.test', ['id' => 1]))
        ->toMatch('/^[0-9a-f]{16}@example\.test$/');
});

it('masks all but the last four characters', function () {
    expect(transformerFor('mask', stringColumn('phone'))->apply('+15551234567', ['id' => 1]))->toBe('********4567')
        ->and(transformerFor('mask', stringColumn('phone'))->apply('1234', ['id' => 1]))->toBe('****')
        ->and(transformerFor('mask', stringColumn('phone'))->apply('12', ['id' => 1]))->toBe('****');
});

it('nulls a value', function () {
    expect(transformerFor('null', stringColumn('iban'))->apply('DE00', ['id' => 1]))->toBeNull();
});

it('scrambles a date deterministically within 180 days', function () {
    $column = new Column('date_of_birth', ColumnType::DateTime, 'date', true, false, null);
    $first = transformerFor('scramble', $column)->apply('1990-06-15', ['id' => 7]);
    $again = transformerFor('scramble', $column)->apply('1990-06-15', ['id' => 7]);

    $diff = (new DateTimeImmutable('1990-06-15'))->diff(new DateTimeImmutable($first))->days;

    expect($first)->toBe($again)
        ->and($first)->toMatch('/^\d{4}-\d{2}-\d{2}$/')
        ->and($diff)->toBeLessThanOrEqual(180);
});

it('derives the scramble offset from the row primary key', function () {
    $column = new Column('date_of_birth', ColumnType::DateTime, 'date', true, false, null);
    $offsets = [];
    foreach (range(1, 50) as $id) {
        $offsets[] = (new DateTimeImmutable('1990-06-15'))->diff(new DateTimeImmutable(transformerFor('scramble', $column)->apply('1990-06-15', ['id' => $id])))->days;
    }

    // fifty rows cannot all share one offset unless the key is being ignored
    expect(count(array_unique($offsets)))->toBeGreaterThan(1);
});

it('keeps the time part when scrambling a datetime', function () {
    $column = new Column('created_at', ColumnType::DateTime, 'datetime', true, false, null);

    expect(transformerFor('scramble', $column)->apply('2026-01-15 10:20:30', ['id' => 1]))->toMatch('/^\d{4}-\d{2}-\d{2} 10:20:30$/');
});

it('refuses scramble on a non date column', function () {
    transformerFor('scramble', stringColumn('name'));
})->throws(InvalidArgumentException::class, 'scramble');

it('refuses scramble on a time or year column', function () {
    $time = new Column('clock', ColumnType::DateTime, 'time', true, false, null);
    $year = new Column('vintage', ColumnType::DateTime, 'year', true, false, null);

    expect(fn () => transformerFor('scramble', $time))->toThrow(InvalidArgumentException::class, '[clock] is time')
        ->and(fn () => transformerFor('scramble', $year))->toThrow(InvalidArgumentException::class, '[vintage] is year')
        ->and(transformerFor('scramble', new Column('at', ColumnType::DateTime, 'timestamp', true, false, null)))->toBeInstanceOf(ScrambleTransformer::class);
});

it('computes one bcrypt hash per value and reuses it', function () {
    $context = redactionContext();
    $column = stringColumn('password');
    $a = (new TransformerFactory)->make('bcrypt:secret', $column, 'id', $context)->apply('x', ['id' => 1]);
    $b = (new TransformerFactory)->make('bcrypt:secret', $column, 'id', $context)->apply('y', ['id' => 2]);

    expect($a)->toBe($b)
        ->and(Hash::check('secret', $a))->toBeTrue();
});

it('returns a fixed literal, including the empty string', function () {
    expect(transformerFor('fixed:redacted', stringColumn('stripe_id'))->apply('cus_1', ['id' => 1]))->toBe('redacted')
        ->and(transformerFor('fixed:', stringColumn('note'))->apply('x', ['id' => 1]))->toBe('');
});

it('keeps a value untouched', function () {
    expect(transformerFor('keep', stringColumn('nickname'))->apply('bob', ['id' => 1]))->toBe('bob');
});

it('passes null through every transformer', function () {
    foreach (['hash', 'mask', 'null', 'bcrypt:secret', 'fixed:x', 'keep'] as $spec) {
        expect(transformerFor($spec, stringColumn('email'))->apply(null, ['id' => 1]))->toBeNull();
    }

    $date = new Column('dob', ColumnType::DateTime, 'date', true, false, null);
    expect(transformerFor('scramble', $date)->apply(null, ['id' => 1]))->toBeNull();
});

it('rejects an unknown transformer and the review placeholder', function () {
    expect(fn () => transformerFor('review', stringColumn('payload')))->toThrow(InvalidArgumentException::class, 'review')
        ->and(fn () => transformerFor('rot13', stringColumn('payload')))->toThrow(InvalidArgumentException::class, 'rot13');
});

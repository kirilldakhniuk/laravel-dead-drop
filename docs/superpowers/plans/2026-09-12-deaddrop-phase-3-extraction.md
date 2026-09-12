# DeadDrop — Phase 3: Extraction, Redaction and Loading — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** `dead-drop:dump` writes a redacted, referentially-complete NDJSON artifact through a pluggable executor, and a new `dead-drop:pull` loads it into a local or staging database, end to end.

**Architecture:** Four new layers on top of the phase-1/2 planner — `Redaction/` (per-column transformers and a per-table `Redactor`), `Artifacts/` (manifest plus gzipped NDJSON per table, read and written only through `Storage`), `Extraction/` (an `Executor` driver interface, a Laravel-style `ExecutorManager` with `extend()`, the `PhpExecutor`, and the fail-closed `ExtractionGate`), and `Loading/` (format-keyed `Loader`s and a `PullRunner` that replaces tables under disabled foreign-key checks). Commands compose these; nothing else moves.

**Tech Stack:** PHP 8.3+, Laravel 12 and 13 (`illuminate/*` `^12.0||^13.0`), Orchestra Testbench 10/11, Pest 4/5, Laravel Prompts, Pint, PHPStan (Larastan) level 7, `ext-zlib` (gzip streams, bundled with PHP).

**Spec:** `docs/superpowers/specs/2026-09-12-deaddrop-extraction-design.md` — the binding text; section numbers below refer to it.

## Global Constraints

- This repository IS the package (`kirilldakhniuk/dead-drop`). Root namespace `DeadDrop\DeadDrop\` from `src/`; tests `DeadDrop\DeadDrop\Tests\` from `tests/`. Branch `deaddrop-extraction`, cut from `main` at `b0b47c1`.
- Every PHP file starts with `<?php` then `declare(strict_types=1);` (arch test, tests and fixtures included).
- PHP `^8.3`. Laravel/Illuminate `^12.0||^13.0`. Works on Windows CI (no shell-outs, no hard-coded `/`; use `DIRECTORY_SEPARATOR`-agnostic PHP functions; `Storage` paths always use `/`).
- Tests are Pest (`it()` closures). Shared helpers live in `tests/Pest.php` (already there: `tempDirectory()`, `initFixtureConfig()`, `traverseFixture()`, `collectedKeys()`); helper function names must be unique across the suite. Fixtures under `tests/Fixtures/`. `SchemaBuilder::migrate($connection)` / `seedTwoCompanies($connection)` create the canonical schema/rows on any connection name.
- Quality gates, all must pass before every commit: `composer lint` (Pint auto-fix), `composer analyse` (PHPStan level 7 over `src`, `config`), `composer test:types` (100% type coverage over `src`; every property, parameter and return typed; arrays docblocked), `composer test:unit` (Pest). `composer test` runs all four. Existing tests (125) keep passing.
- Arch presets ban anywhere in src and tests: `md5`, `sha1`, `uniqid`, `rand`, `mt_rand`, `tempnam`, `str_shuffle`, `shuffle`, `array_rand`, `eval`, `exec`, `shell_exec`, `system`, `passthru`, `unserialize`, `extract`, `assert`, `var_export`, `var_dump`, `print_r`, `dump`, `dd`, `ddd`, `echo`, `print`, `die`, `exit`, `env()` outside `config/`. Consequences: hashing uses `hash('sha256', …)`; random ids use `Illuminate\Support\Str::random()` / `Str::lower(Str::random(6))`; temp files are `sys_get_temp_dir().'/dead-drop-'.Str::random(12)`; never `tempnam`.
- **Local toolchain:** the Homebrew `php` on PATH is broken. Prefix every command with `export PATH="$HOME/Library/Application Support/Herd/bin:$PATH"` (gives `php`, `php84`, `composer`).
- Driver-specific SQL lives ONLY in `src/Drivers/`; `grep -rn "information_schema\|pg_class\|TEMPORARY\|FOREIGN_KEY_CHECKS\|session_replication_role\|PRAGMA" src --exclude-dir=Drivers` must return nothing.
- Collected keys stay in temporary key tables; extraction reads rows by joining them; at most one chunk (1,000 rows) is in memory. Loading inserts in chunks of 500.
- Nothing ever writes to the source connection except temporary tables. All artifact I/O goes through `Illuminate\Support\Facades\Storage::disk(...)`.
- Every command remains fully drivable with `--no-interaction` plus flags.
- Commit after each task with the message given in the task, ending with the trailer `Co-Authored-By: Claude Fable 5.1 <noreply@anthropic.com>`. Stage with explicit paths (`git add src tests config composer.json README.md CHANGELOG.md resources/boost` as applicable); never stage `docs/` or `.superpowers/`.
- Add only the files and dependencies a task names. No new composer dependencies.

---

## File Structure

```
config/dead-drop.php                       # + 'executor'
composer.json                              # analyse: --memory-limit=1G
src/
  Drivers/DatabaseDriver.php               # + disableForeignKeyChecks / enableForeignKeyChecks
  Drivers/{Sqlite,MySql,Postgres}Driver.php
  Inference/TableClassifier.php            # + spatial_ref_sys in skip list
  Redaction/
    Transformer.php                        # interface
    RedactionContext.php
    Transformers/{Hash,Mask,Null,Scramble,Bcrypt,Fixed,Keep}Transformer.php
    TransformerFactory.php
    Redactor.php
    RedactionRules.php
  Artifacts/
    Manifest.php  TableManifest.php
    ArtifactWriter.php  TableFileWriter.php  ArtifactReader.php
    RowCodec.php                           # value encoding/decoding shared by writer and loader
  Extraction/
    Executor.php                           # interface
    TableArtifact.php
    ExecutorManager.php
    PhpExecutor.php
    ExtractionGate.php  GateReport.php
    ArtifactBuilder.php                    # runs the executor over a plan, writes the manifest
  Loading/
    Loader.php                             # interface
    NdjsonLoader.php
    LoaderRegistry.php
    PullRunner.php  PullReport.php
  Console/Commands/
    DumpCommand.php                        # real mode
    DumpsCommand.php
    PullCommand.php
  DeadDropServiceProvider.php              # singletons + commands
tests/
  TestCase.php                             # + dd_target connection
  Pest.php                                 # + fakeArtifactDisk(), dumpFixture()
  Fixtures/RecordingAfterHook.php
  Unit/Redaction/…  Unit/Artifacts/…  Feature/Extraction/…  Feature/Loading/…  Feature/Commands/…
```

**Responsibility boundaries**

- `Redaction/` is pure: transformers see a value and its row, never a database.
- `Artifacts/` knows the on-disk layout and nothing about databases. `RowCodec` is the single place that encodes and decodes values.
- `Extraction/` is the only layer that reads source rows. `ArtifactBuilder` orchestrates one dump; `ExtractionGate` decides whether a dump may start.
- `Loading/` is the only layer that writes target rows.
- Commands compose; they contain no rules.

---

## Task 1: Foundations — driver FK toggles, config, skip list, target connection

**Files:**
- Modify: `src/Drivers/DatabaseDriver.php`, `src/Drivers/SqliteDriver.php`, `src/Drivers/MySqlDriver.php`, `src/Drivers/PostgresDriver.php`
- Modify: `config/dead-drop.php` (add `executor`)
- Modify: `composer.json` (`analyse` script gets `--memory-limit=1G`)
- Modify: `src/Inference/TableClassifier.php` (add `spatial_ref_sys` to the exact skip names)
- Modify: `tests/TestCase.php` (add `dd_target` connection)
- Test: `tests/Feature/Drivers/SqliteDriverTest.php`, `tests/Unit/Inference/TableClassifierTest.php`, `tests/Feature/ServiceProviderTest.php`

**Interfaces:**
- Consumes: existing `DatabaseDriver` (`name()`, `normaliseType()`, `estimatedRowCounts()`, `currentSchema()`, `createKeyTable()`, `insertKeys(): int`, `countKeys()`, `dropKeyTable()`, `quote()`).
- Produces: `DatabaseDriver::disableForeignKeyChecks(Connection $connection): void` and `enableForeignKeyChecks(Connection $connection): void`; config key `dead-drop.executor` (default `'php'`); connection `dd_target` (sqlite `:memory:`, `foreign_key_constraints => true`, `use_native_json => true`), identical to `dd_test` and `dd_analytics`.

- [ ] **Step 1: Write the failing tests**

Append to `tests/Feature/Drivers/SqliteDriverTest.php`:

```php
it('toggles foreign key checks', function () {
    SchemaBuilder::migrate('dd_test');
    $driver = new SqliteDriver;
    $connection = DB::connection('dd_test');

    $driver->disableForeignKeyChecks($connection);
    $connection->table('orders')->insert(['id' => 500, 'company_id' => 999, 'user_id' => 1, 'customer_id' => null, 'total' => 1, 'created_at' => null]);
    expect($connection->table('orders')->where('id', 500)->exists())->toBeTrue();

    $driver->enableForeignKeyChecks($connection);
    expect(fn () => $connection->table('orders')->insert(['id' => 501, 'company_id' => 999, 'user_id' => 1, 'customer_id' => null, 'total' => 1, 'created_at' => null]))
        ->toThrow(QueryException::class);
});
```

(Import `DeadDrop\DeadDrop\Tests\Fixtures\SchemaBuilder` and `Illuminate\Database\QueryException`.)

Append to `tests/Unit/Inference/TableClassifierTest.php`:

```php
it('skips the PostGIS spatial_ref_sys table', function () {
    Schema::connection('dd_test')->create('spatial_ref_sys', function ($t) {
        $t->integer('srid')->primary();
        $t->string('auth_name')->nullable();
    });

    expect(classifyFixtureTable('spatial_ref_sys'))->toBe(TableClass::Skip);
});
```

Append to `tests/Feature/ServiceProviderTest.php`:

```php
it('defaults the executor to php', function () {
    expect(config('dead-drop.executor'))->toBe('php');
});

it('defines the in-memory target connection', function () {
    expect(config('database.connections.dd_target.driver'))->toBe('sqlite');
});
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `composer test:unit -- --filter='toggles foreign key checks|spatial_ref_sys|defaults the executor|target connection'`
Expected: FAIL — `disableForeignKeyChecks` undefined; `spatial_ref_sys` classified `Lookup`/`Data`; config keys missing.

- [ ] **Step 3: Implement**

`DatabaseDriver` gains:

```php
/** Turn off referential-integrity enforcement for this session (loading inserts parents and children in plan order but under one transaction per table). */
public function disableForeignKeyChecks(Connection $connection): void;

public function enableForeignKeyChecks(Connection $connection): void;
```

Implementations: SQLite `PRAGMA foreign_keys = OFF` / `PRAGMA foreign_keys = ON`; MySQL `SET FOREIGN_KEY_CHECKS = 0` / `= 1`; Postgres `SET session_replication_role = replica` / `SET session_replication_role = DEFAULT`. All via `$connection->statement(...)`.

`config/dead-drop.php` — after `model_paths`:

```php
    // Which executor moves rows during dead-drop:dump ('php' ships with the package; register others with ExecutorManager::extend()).
    'executor' => env('DEAD_DROP_EXECUTOR', 'php'),
```

`composer.json` — `"analyse": ["vendor/bin/phpstan analyse --memory-limit=1G"]`.

`TableClassifier` — add `'spatial_ref_sys'` to the exact-name skip constant.

`tests/TestCase.php` — add `dd_target` next to `dd_analytics` with the same array.

- [ ] **Step 4: Run tests to verify they pass**

Run: `composer test:unit -- --filter='toggles foreign key checks|spatial_ref_sys|defaults the executor|target connection'`
Expected: PASS, 4 tests.

- [ ] **Step 5: Run the full gate and commit**

Run: `composer test`; run the driver-boundary grep from Global Constraints (empty).

```bash
git add src config composer.json tests
git commit -m "feat: add foreign key toggles, executor config and target test connection"
```

---

## Task 2: Redaction transformers

**Files:**
- Create: `src/Redaction/Transformer.php`, `src/Redaction/RedactionContext.php`, `src/Redaction/TransformerFactory.php`, `src/Redaction/Transformers/HashTransformer.php`, `MaskTransformer.php`, `NullTransformer.php`, `ScrambleTransformer.php`, `BcryptTransformer.php`, `FixedTransformer.php`, `KeepTransformer.php`
- Test: `tests/Unit/Redaction/TransformerTest.php`

**Interfaces:**
- Consumes: `DeadDrop\DeadDrop\Schema\Column` (`->name`, `->type: ColumnType`, `->nativeType`, `->nullable`), `ColumnType`.
- Produces:
  - `interface Transformer { public function apply(mixed $value, array $row): mixed; }` — `$row` is the full source row keyed by column name (transformers that need the primary key read it from the row).
  - `final class RedactionContext(string $salt, string $emailDomain)` with `bcrypt(string $value): string` (computes `Hash::make($value)` once per distinct value and caches it for the context's lifetime).
  - `final class TransformerFactory { public function make(string $spec, Column $column, string $primaryKey, RedactionContext $context): Transformer; }` — `$spec` is the config `redact` value. Recognised: `hash`, `mask`, `null`, `scramble`, `keep`, `bcrypt:<value>`, `fixed:<value>`. Anything else (including `review`) throws `InvalidArgumentException("Unknown redaction transformer [{$spec}] for column [{$column->name}].")`. `scramble` on a column whose type is not `ColumnType::DateTime` throws `InvalidArgumentException("'scramble' requires a date or datetime column; [{$column->name}] is {$column->type->value}.")`.
  - Transformer semantics (spec §6). `null` input always returns `null` unchanged, for every transformer.
    - `HashTransformer(string $salt, ?int $maxLength, ?string $emailDomain)`: `hash('sha256', $salt.(string) $value)`; when `$emailDomain !== null` → `substr($hex, 0, 16).'@'.$emailDomain`; else truncated to `$maxLength` when set. The factory passes `$emailDomain` only when the lower-cased column name is `email`, `email_address`, or ends with `_email`, and `$maxLength` from the digits inside the first parentheses of `nativeType` (`varchar(191)` → 191; none → null).
    - `MaskTransformer`: `$s = (string) $value; strlen($s) <= 4 ? '****' : str_repeat('*', strlen($s) - 4).substr($s, -4)` (use `mb_strlen`/`mb_substr`).
    - `NullTransformer`: `null`.
    - `ScrambleTransformer(string $salt, string $primaryKey)`: `$days = (hexdec(substr(hash('sha256', $salt.(string) $row[$primaryKey]), 0, 8)) % 361) - 180`; parse the value with `DateTimeImmutable` (`new DateTimeImmutable((string) $value)`), add `$days` days, and format back as `Y-m-d` when the original string has no time part (length 10), else `Y-m-d H:i:s`. Unparseable input → `InvalidArgumentException` naming the column and value.
    - `BcryptTransformer(RedactionContext $context, string $plain)`: `$context->bcrypt($plain)`.
    - `FixedTransformer(string $literal)`: the literal (empty string allowed: `fixed:` → `''`).
    - `KeepTransformer`: `$value`.

- [ ] **Step 1: Write the failing test**

`tests/Unit/Redaction/TransformerTest.php`:

```php
<?php

declare(strict_types=1);

use DeadDrop\DeadDrop\Redaction\RedactionContext;
use DeadDrop\DeadDrop\Redaction\TransformerFactory;
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
```

- [ ] **Step 2: Run test to verify it fails**

Run: `composer test:unit -- --filter=TransformerTest`
Expected: FAIL — `Class "DeadDrop\DeadDrop\Redaction\RedactionContext" not found`.

- [ ] **Step 3: Implement**

Each transformer is a small `final readonly class` implementing `Transformer`; `RedactionContext` holds `private array $bcrypt = []` (so it is `final class`, not readonly). `TransformerFactory::make()`:

```php
public function make(string $spec, Column $column, string $primaryKey, RedactionContext $context): Transformer
{
    [$name, $argument] = array_pad(explode(':', $spec, 2), 2, null);

    return match ($name) {
        'hash' => new HashTransformer($context->salt, $this->declaredLength($column), $this->isEmailColumn($column) ? $context->emailDomain : null),
        'mask' => new MaskTransformer,
        'null' => new NullTransformer,
        'scramble' => $column->type === ColumnType::DateTime
            ? new ScrambleTransformer($context->salt, $primaryKey)
            : throw new InvalidArgumentException("'scramble' requires a date or datetime column; [{$column->name}] is {$column->type->value}."),
        'bcrypt' => new BcryptTransformer($context, (string) $argument),
        'fixed' => new FixedTransformer((string) $argument),
        'keep' => new KeepTransformer,
        default => throw new InvalidArgumentException("Unknown redaction transformer [{$spec}] for column [{$column->name}]."),
    };
}
```

`declaredLength()`: `preg_match('/\((\d+)/', $column->nativeType, $m) ? (int) $m[1] : null`. `isEmailColumn()`: lower-cased name equals `email`/`email_address` or ends with `_email` (same patterns `SensitiveColumnDetector` uses — read that class and reuse the constant if it is accessible, otherwise duplicate the three patterns with a comment pointing there).

- [ ] **Step 4: Run test to verify it passes**

Run: `composer test:unit -- --filter=TransformerTest`
Expected: PASS, 13 tests.

- [ ] **Step 5: Run the full gate and commit**

Run: `composer test`

```bash
git add src/Redaction tests/Unit/Redaction
git commit -m "feat: add redaction transformers"
```

---

## Task 3: Redactor and redaction rules

**Files:**
- Create: `src/Redaction/Redactor.php`, `src/Redaction/RedactionRules.php`
- Test: `tests/Unit/Redaction/RedactorTest.php`, `tests/Unit/Redaction/RedactionRulesTest.php`

**Interfaces:**
- Consumes: `TransformerFactory`, `RedactionContext`, `DeadDrop\DeadDrop\Config\TableConfig` (`->name`, `->redact: array<string,string>`, `->references: array<string, Reference>` keyed by column), `DeadDrop\DeadDrop\Schema\Table` (`->columns`, `->column()`, `->primaryKey()`).
- Produces:
  - `final class Redactor` with `static forTable(TableConfig $config, Table $table, RedactionContext $context, ?TransformerFactory $factory = null): self`, `apply(array $row): array` (returns the row with each redacted column replaced; untouched columns pass through; columns named in `redact` but absent from the row are ignored), `columns(): list<string>` (the redacted column names, sorted).
  - `final class RedactionRules` with `violations(TableConfig $config, Table $table): list<string>` — one message per problem, sorted, exact texts:
    - `"{table}.{col}: column does not exist"`
    - `"{table}.{col}: 'review' must be replaced with a decision"`
    - `"{table}.{col}: primary key columns cannot be redacted"`
    - `"{table}.{col}: reference columns cannot be redacted"`
    - `"{table}.{col}: 'null' is not allowed on a NOT NULL column"`
    - `"{table}.{col}: 'scramble' requires a date or datetime column"`
    - `"{table}.{col}: unknown transformer [{spec}]"` (anything `TransformerFactory` would reject other than `review`/`scramble` cases above; detect by trying `make()` with a throw-away context and catching `InvalidArgumentException`).
    Skip-class tables always return `[]`.

- [ ] **Step 1: Write the failing tests**

`tests/Unit/Redaction/RedactorTest.php`:

```php
<?php

declare(strict_types=1);

use DeadDrop\DeadDrop\Config\TableClass;
use DeadDrop\DeadDrop\Config\TableConfig;
use DeadDrop\DeadDrop\Redaction\RedactionContext;
use DeadDrop\DeadDrop\Redaction\Redactor;
use DeadDrop\DeadDrop\Schema\Introspector;
use DeadDrop\DeadDrop\Tests\Fixtures\SchemaBuilder;

beforeEach(fn () => SchemaBuilder::migrate('dd_test'));

function usersRedactor(array $redact): Redactor
{
    $table = app(Introspector::class)->inspect('dd_test')->table('users');
    $config = new TableConfig('users', TableClass::Data, $table->columnNames(), [], $redact, null, null, null);

    return Redactor::forTable($config, $table, new RedactionContext(str_repeat('s', 32), 'example.test'));
}

it('redacts only the configured columns', function () {
    $row = ['id' => 10, 'company_id' => 1, 'email' => 'a@acme.test', 'password' => 'secret', 'created_by' => null, 'failed_job_id' => null];

    $out = usersRedactor(['email' => 'hash', 'password' => 'fixed:x'])->apply($row);

    expect($out['email'])->toMatch('/^[0-9a-f]{16}@example\.test$/')
        ->and($out['password'])->toBe('x')
        ->and($out['id'])->toBe(10)
        ->and($out['company_id'])->toBe(1)
        ->and(array_keys($out))->toBe(array_keys($row));
});

it('lists its redacted columns sorted', function () {
    expect(usersRedactor(['password' => 'null', 'email' => 'hash'])->columns())->toBe(['email', 'password']);
});

it('ignores a configured column missing from the row', function () {
    expect(usersRedactor(['email' => 'hash'])->apply(['id' => 1]))->toBe(['id' => 1]);
});
```

`tests/Unit/Redaction/RedactionRulesTest.php`:

```php
<?php

declare(strict_types=1);

use DeadDrop\DeadDrop\Config\Reference;
use DeadDrop\DeadDrop\Config\TableClass;
use DeadDrop\DeadDrop\Config\TableConfig;
use DeadDrop\DeadDrop\Inference\EdgeSource;
use DeadDrop\DeadDrop\Redaction\RedactionRules;
use DeadDrop\DeadDrop\Schema\Introspector;
use DeadDrop\DeadDrop\Tests\Fixtures\SchemaBuilder;

beforeEach(fn () => SchemaBuilder::migrate('dd_test'));

function violationsFor(string $table, array $redact, array $references = [], TableClass $class = TableClass::Data): array
{
    $schema = app(Introspector::class)->inspect('dd_test')->table($table);
    $config = new TableConfig($table, $class, $schema->columnNames(), $references, $redact, null, null, null);

    return (new RedactionRules)->violations($config, $schema);
}

it('accepts a valid redaction map', function () {
    expect(violationsFor('users', ['email' => 'hash', 'password' => 'bcrypt:secret']))->toBe([]);
});

it('rejects review placeholders', function () {
    expect(violationsFor('users', ['email' => 'review']))->toBe(["users.email: 'review' must be replaced with a decision"]);
});

it('rejects redacting the primary key', function () {
    expect(violationsFor('users', ['id' => 'hash']))->toBe(['users.id: primary key columns cannot be redacted']);
});

it('rejects redacting a reference column', function () {
    $references = ['company_id' => new Reference(null, 'companies', 'id', true, EdgeSource::Guessed)];

    expect(violationsFor('users', ['company_id' => 'null'], $references))->toBe(['users.company_id: reference columns cannot be redacted']);
});

it('rejects null on a not null column', function () {
    expect(violationsFor('users', ['email' => 'null']))->toBe(["users.email: 'null' is not allowed on a NOT NULL column"]);
});

it('rejects scramble on a non date column', function () {
    expect(violationsFor('users', ['email' => 'scramble']))->toBe(["users.email: 'scramble' requires a date or datetime column"]);
});

it('rejects an unknown transformer and a missing column', function () {
    expect(violationsFor('users', ['email' => 'rot13', 'ghost' => 'hash']))->toBe([
        'users.email: unknown transformer [rot13]',
        'users.ghost: column does not exist',
    ]);
});

it('ignores skip tables', function () {
    expect(violationsFor('users', ['email' => 'review'], [], TableClass::Skip))->toBe([]);
});
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `composer test:unit -- --filter='RedactorTest|RedactionRulesTest'`
Expected: FAIL — classes not found.

- [ ] **Step 3: Implement**

`Redactor::forTable()` builds `array<string, Transformer>` keyed by column via the factory (skipping columns absent from `$table->columns`; rule violations are the gate's job, so the redactor itself only builds what it can). `apply()` iterates its transformers and replaces `$row[$column]` when the key exists. `RedactionRules::violations()` checks, per `redact` entry, in this order and stops at the first violation for that column: exists → not `review` → not primary key → not a reference source column → `null`/NOT NULL → `scramble`/type → factory accepts.

- [ ] **Step 4: Run tests to verify they pass**

Run: `composer test:unit -- --filter='RedactorTest|RedactionRulesTest'`
Expected: PASS, 11 tests.

- [ ] **Step 5: Run the full gate and commit**

Run: `composer test`

```bash
git add src/Redaction tests/Unit/Redaction
git commit -m "feat: add per-table redactor and redaction rules"
```

---

## Task 4: Artifact layout — manifest, writer, reader, row codec

**Files:**
- Create: `src/Artifacts/Manifest.php`, `src/Artifacts/TableManifest.php`, `src/Artifacts/RowCodec.php`, `src/Artifacts/TableFileWriter.php`, `src/Artifacts/ArtifactWriter.php`, `src/Artifacts/ArtifactReader.php`
- Modify: `tests/Pest.php` (add `fakeArtifactDisk(): void`)
- Test: `tests/Unit/Artifacts/ManifestTest.php`, `tests/Feature/Artifacts/ArtifactRoundTripTest.php`

**Interfaces:**
- Consumes: `Illuminate\Contracts\Filesystem\Filesystem` (from `Storage::disk()`), `ColumnType`.
- Produces (spec §5):
  - `final readonly class TableManifest(string $connection, string $table, string $file, string $format, int $rows, int $bytes, string $primaryKey, array $columns, array $redacted)` — `$columns` is `list<array{name: string, type: string}>` (`type` = `ColumnType` backing value), `$redacted` is `list<string>`; `toArray()` / `static fromArray(array)`; `key(): string` = `"{connection}.{table}"`.
  - `final readonly class Manifest(string $id, string $status, string $createdAt, string $packageVersion, string $root, ?string $since, string $executor, array $connections, array $tables, array $unresolved)` — `$connections` is `array<string, array{driver: string}>`, `$tables` is `list<TableManifest>`, `$unresolved` is `list<array{connection: string, table: string, column: string, reason: string}>`; constants `STATUS_WRITING = 'writing'`, `STATUS_COMPLETE = 'complete'`, `VERSION = 1`; `toArray()` (includes `'version' => 1`) / `static fromArray(array)` (throws `InvalidArgumentException` on a different version or missing keys); `withStatus(string): self`, `withTable(TableManifest): self` (appends), `totalRows(): int`, `totalBytes(): int`, `isComplete(): bool`. `static newId(DateTimeImmutable $now): string` = `$now->format('Ymd-His').'-'.Str::lower(Str::random(6))`.
  - `final class RowCodec` with `encode(array $row, array $types): string` (one JSON line without trailing newline; `$types` is `array<string, ColumnType>`; `Binary` values become `['__base64' => base64_encode($value)]`; everything else is JSON-native — ints/floats/bools/strings/null as given, `Json`-typed values that are already strings stay strings; flags `JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE`) and `decode(string $line, array $types): array` (reverses: `Binary` wrapper → raw bytes; `Integer` → `(int)`; `Boolean` → `(bool)`; `Decimal` → string; everything else as decoded).
  - `final class TableFileWriter` (created by `ArtifactWriter::table()`): `append(array $row): void`, `finish(): array{rows: int, bytes: int}` — writes gzip lines to a local temp file (`gzopen($tmp, 'wb6')`), on `finish()` closes it, `$disk->put($path, fopen($tmp, 'rb'))`, deletes the temp file, returns counts (`bytes` = compressed size via `filesize` before deletion).
  - `final class ArtifactWriter` with `__construct(Filesystem $disk, string $basePath, string $id)`, `path(string $file = ''): string` (`"{basePath}/{id}"` or `"{basePath}/{id}/{file}"`, using `/`), `writeManifest(Manifest $manifest): void` (`put` of pretty-printed JSON), `table(string $file, array $types): TableFileWriter`.
  - `final class ArtifactReader` with `__construct(Filesystem $disk, string $basePath)`, `ids(): list<string>` (directories under `basePath` that contain `manifest.json`, newest id first — ids sort lexically by timestamp, so `rsort`), `manifest(string $id): Manifest` (throws `InvalidArgumentException("No artifact [{$id}] on this disk.")` when missing), `latestComplete(): ?Manifest`, `rows(string $id, TableManifest $table): Generator` (yields decoded `array` rows; reads via `$disk->readStream()` copied to a local temp file, then `gzopen`/`gzgets`, deleting the temp file in a `finally`).
  - `tests/Pest.php`: `function fakeArtifactDisk(): void { Storage::fake('local'); config()->set('dead-drop.disk', 'local'); config()->set('dead-drop.path', 'dead-drops'); config()->set('dead-drop.redaction.salt', str_repeat('s', 32)); }`.

- [ ] **Step 1: Write the failing tests**

`tests/Unit/Artifacts/ManifestTest.php`:

```php
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
        connections: ['dd_test' => ['driver' => 'sqlite']],
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

it('rejects a manifest of another version', function () {
    Manifest::fromArray(['version' => 2] + sampleManifest()->toArray());
})->throws(InvalidArgumentException::class);

it('generates sortable ids', function () {
    $id = Manifest::newId(new DateTimeImmutable('2026-09-12 14:15:00', new DateTimeZone('UTC')));

    expect($id)->toMatch('/^20260912-141500-[a-z0-9]{6}$/');
});
```

`tests/Feature/Artifacts/ArtifactRoundTripTest.php`:

```php
<?php

declare(strict_types=1);

use DeadDrop\DeadDrop\Artifacts\ArtifactReader;
use DeadDrop\DeadDrop\Artifacts\ArtifactWriter;
use DeadDrop\DeadDrop\Artifacts\Manifest;
use DeadDrop\DeadDrop\Artifacts\TableManifest;
use DeadDrop\DeadDrop\Schema\ColumnType;
use Illuminate\Support\Facades\Storage;

beforeEach(fn () => fakeArtifactDisk());

function writeSampleArtifact(string $id, string $status = Manifest::STATUS_COMPLETE): Manifest
{
    $disk = Storage::disk('local');
    $writer = new ArtifactWriter($disk, 'dead-drops', $id);
    $types = ['id' => ColumnType::Integer, 'name' => ColumnType::String, 'flags' => ColumnType::Json, 'blob' => ColumnType::Binary, 'ok' => ColumnType::Boolean];

    $file = $writer->table('dd_test.things.ndjson.gz', $types);
    $file->append(['id' => 1, 'name' => 'héllo/wörld', 'flags' => '{"a":1}', 'blob' => "\x00\xff\x10", 'ok' => 1]);
    $file->append(['id' => 2, 'name' => null, 'flags' => null, 'blob' => null, 'ok' => 0]);
    $counts = $file->finish();

    $manifest = new Manifest($id, $status, '2026-09-12T14:15:00+00:00', 'dev', 'dd_test.things:1', null, 'php', ['dd_test' => ['driver' => 'sqlite']], [
        new TableManifest('dd_test', 'things', 'dd_test.things.ndjson.gz', 'ndjson', $counts['rows'], $counts['bytes'], 'id', [
            ['name' => 'id', 'type' => 'int'], ['name' => 'name', 'type' => 'string'], ['name' => 'flags', 'type' => 'json'], ['name' => 'blob', 'type' => 'binary'], ['name' => 'ok', 'type' => 'bool'],
        ], []),
    ], []);
    $writer->writeManifest($manifest);

    return $manifest;
}

it('writes a gzipped ndjson file and a manifest under the dump id', function () {
    writeSampleArtifact('20260912-141500-aaaaaa');

    expect(Storage::disk('local')->exists('dead-drops/20260912-141500-aaaaaa/manifest.json'))->toBeTrue()
        ->and(Storage::disk('local')->exists('dead-drops/20260912-141500-aaaaaa/dd_test.things.ndjson.gz'))->toBeTrue();
});

it('reads rows back with types restored', function () {
    $manifest = writeSampleArtifact('20260912-141500-aaaaaa');
    $reader = new ArtifactReader(Storage::disk('local'), 'dead-drops');

    $rows = iterator_to_array($reader->rows($manifest->id, $manifest->tables[0]), false);

    expect($rows)->toHaveCount(2)
        ->and($rows[0]['id'])->toBe(1)
        ->and($rows[0]['name'])->toBe('héllo/wörld')
        ->and($rows[0]['flags'])->toBe('{"a":1}')
        ->and($rows[0]['blob'])->toBe("\x00\xff\x10")
        ->and($rows[0]['ok'])->toBeTrue()
        ->and($rows[1]['name'])->toBeNull()
        ->and($rows[1]['blob'])->toBeNull()
        ->and($rows[1]['ok'])->toBeFalse()
        ->and($manifest->tables[0]->rows)->toBe(2);
});

it('lists ids newest first and finds the latest complete manifest', function () {
    writeSampleArtifact('20260912-141500-aaaaaa');
    writeSampleArtifact('20260912-150000-bbbbbb', Manifest::STATUS_WRITING);
    writeSampleArtifact('20260911-090000-cccccc');
    $reader = new ArtifactReader(Storage::disk('local'), 'dead-drops');

    expect($reader->ids())->toBe(['20260912-150000-bbbbbb', '20260912-141500-aaaaaa', '20260911-090000-cccccc'])
        ->and($reader->latestComplete()?->id)->toBe('20260912-141500-aaaaaa')
        ->and(fn () => $reader->manifest('nope'))->toThrow(InvalidArgumentException::class, 'nope');
});

it('returns null when no complete artifact exists', function () {
    expect((new ArtifactReader(Storage::disk('local'), 'dead-drops'))->latestComplete())->toBeNull();
});
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `composer test:unit -- --filter='ManifestTest|ArtifactRoundTripTest'`
Expected: FAIL — classes / `fakeArtifactDisk()` not found.

- [ ] **Step 3: Implement**

Notes: `ArtifactReader::ids()` uses `$disk->directories($basePath)` and keeps those where `$disk->exists("$dir/manifest.json")`, mapping to basenames, `rsort`. `latestComplete()` iterates `ids()` and returns the first manifest with `isComplete()`. Reading: `$stream = $disk->readStream($path)`; if null → `RuntimeException("Missing artifact file [{$path}].")`; `stream_copy_to_stream` into a local temp file; `gzopen($tmp, 'rb')`; loop `gzgets` (lines up to 1 MiB; `RowCodec::decode` each non-empty line); `gzclose`; `unlink` in `finally`. Package version: not here (Task 7).

- [ ] **Step 4: Run tests to verify they pass**

Run: `composer test:unit -- --filter='ManifestTest|ArtifactRoundTripTest'`
Expected: PASS, 8 tests.

- [ ] **Step 5: Run the full gate and commit**

Run: `composer test`

```bash
git add src/Artifacts tests
git commit -m "feat: add artifact manifest, writer and reader"
```

---

## Task 5: Executor driver — interface, manager, PHP executor

**Files:**
- Create: `src/Extraction/Executor.php`, `src/Extraction/TableArtifact.php`, `src/Extraction/ExecutorManager.php`, `src/Extraction/PhpExecutor.php`
- Modify: `src/DeadDropServiceProvider.php` (`$this->app->singleton(ExecutorManager::class)`)
- Test: `tests/Feature/Extraction/ExecutorManagerTest.php`, `tests/Feature/Extraction/PhpExecutorTest.php`

**Interfaces:**
- Consumes: `DeadDrop\DeadDrop\Planning\PlanStep` (`->connection`, `->table`, `->keyTable: ?string`, `->rows`), `KeySet` (`->tableName`), `Table`, `TableConfig`, `Redactor`, `RedactionContext`, `ArtifactWriter`, `TableFileWriter`, `Illuminate\Contracts\Container\Container`.
- Produces (spec §4):
  - `interface Executor { public function name(): string; public function supports(string $connectionDriver): bool; public function export(PlanStep $step, Table $table, TableConfig $config, ?KeySet $keys, Redactor $redactor, ArtifactWriter $writer): TableArtifact; }` — note `Redactor` is passed in (built by the caller from the config and context) so executors never construct redaction themselves.
  - `final readonly class TableArtifact(string $connection, string $table, string $file, string $format, int $rows, int $bytes)`.
  - `final class ExecutorManager` with `__construct(Container $app)`, `driver(?string $name = null): Executor` (default `config('dead-drop.executor')`; built-in map `['php' => PhpExecutor::class]` resolved through the container; custom drivers via `extend(string $name, Closure $factory): void` where the closure receives the container; unknown → `InvalidArgumentException("Unsupported DeadDrop executor [{$name}].")`; instances cached per name), `extend(...)`.
  - `final class PhpExecutor implements Executor`: `name()` = `'php'`; `supports()` = `true` for `mysql`, `mariadb`, `pgsql`, `sqlite`; `export()` — file name `"{$step->connection}.{$step->table}.ndjson.gz"`; types map from `$table->columns`; query `DB::connection($step->connection)->table($table->name)->select("{$table->name}.*")->orderBy("{$table->name}.{$pk}")`, joined to `$keys->tableName` on `"{$table->name}.{$pk}" = "{$keys->tableName}.k"` when `$keys !== null`; iterate with `->chunk(1000, ...)` (never `get()`), `(array) $row` → `$redactor->apply()` → `$file->append()`; `finish()`; return `TableArtifact(..., 'ndjson', rows, bytes)`.

- [ ] **Step 1: Write the failing tests**

`tests/Feature/Extraction/ExecutorManagerTest.php`:

```php
<?php

declare(strict_types=1);

use DeadDrop\DeadDrop\Extraction\Executor;
use DeadDrop\DeadDrop\Extraction\ExecutorManager;
use DeadDrop\DeadDrop\Extraction\PhpExecutor;

it('resolves the php executor by default', function () {
    expect(app(ExecutorManager::class)->driver())->toBeInstanceOf(PhpExecutor::class)
        ->and(app(ExecutorManager::class))->toBe(app(ExecutorManager::class));
});

it('registers a custom executor through extend', function () {
    $custom = Mockery::mock(Executor::class);
    app(ExecutorManager::class)->extend('custom', fn () => $custom);

    expect(app(ExecutorManager::class)->driver('custom'))->toBe($custom);
});

it('rejects an unknown executor', function () {
    app(ExecutorManager::class)->driver('nope');
})->throws(InvalidArgumentException::class, 'nope');
```

`tests/Feature/Extraction/PhpExecutorTest.php`:

```php
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
use DeadDrop\DeadDrop\Schema\Introspector;
use DeadDrop\DeadDrop\Tests\Fixtures\SchemaBuilder;
use Illuminate\Support\Facades\Storage;

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
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `composer test:unit -- --filter='ExecutorManagerTest|PhpExecutorTest'`
Expected: FAIL — classes not found.

- [ ] **Step 3: Implement** per the Interfaces block. Register `ExecutorManager` as a singleton in the provider's `register()`.

- [ ] **Step 4: Run tests to verify they pass**

Run: `composer test:unit -- --filter='ExecutorManagerTest|PhpExecutorTest'`
Expected: PASS, 6 tests.

- [ ] **Step 5: Run the full gate and commit**

Run: `composer test`

```bash
git add src tests
git commit -m "feat: add executor driver interface, manager and php executor"
```

---

## Task 6: Extraction gate and root validation

**Files:**
- Create: `src/Extraction/GateReport.php`, `src/Extraction/ExtractionGate.php`
- Modify: `src/Console/Commands/DumpCommand.php` (dry-run validates root ids)
- Test: `tests/Feature/Extraction/ExtractionGateTest.php`, `tests/Feature/Commands/DumpDryRunTest.php` (one new test)

**Interfaces:**
- Consumes: `DriftDetector::detect(DatabaseSchema, ConnectionConfig): DriftReport` (`hasDrift()`, `toLines()`), `RedactionRules`, `ConfigSet`, `SchemaSet`, `Root`, `DB`.
- Produces (spec §7):
  - `final readonly class GateReport(array $lines)` with `passes(): bool` (`$lines === []`), `lines(): list<string>`.
  - `final class ExtractionGate` with `__construct(DriftDetector $drift, RedactionRules $rules)`, `rootIds(Root $root, ConfigSet $config, SchemaSet $schemas): list<string>` — returns `["Root id {id} does not exist in {connection}.{table}"]` per missing id (query `DB::connection($root->connection)->table($root->table)->whereIn($pk, $root->ids)->pluck($pk)` and diff, comparing as strings), and `check(Root $root, ConfigSet $config, SchemaSet $schemas, ?string $salt): GateReport` running in order and collecting all lines: (1) per connection in `$config`, drift lines prefixed `"{connection}: "` when `hasDrift()`; (2) `"redaction.salt must be set to at least 16 characters (DEAD_DROP_REDACTION_SALT)"` when `$salt === null || strlen($salt) < 16`; (3) `RedactionRules::violations()` for every non-skip, non-removed table present in its schema; (4) `rootIds()`.
- `DumpCommand` dry-run: after planning, if `rootIds()` returns lines, print them with `error()` and return FAILURE (drift/salt/rules are not required for dry-run).

- [ ] **Step 1: Write the failing tests**

`tests/Feature/Extraction/ExtractionGateTest.php`:

```php
<?php

declare(strict_types=1);

use DeadDrop\DeadDrop\Config\ConfigLoader;
use DeadDrop\DeadDrop\Extraction\ExtractionGate;
use DeadDrop\DeadDrop\Planning\Root;
use DeadDrop\DeadDrop\Schema\Introspector;
use DeadDrop\DeadDrop\Schema\SchemaSet;
use DeadDrop\DeadDrop\Tests\Fixtures\SchemaBuilder;
use Illuminate\Support\Facades\Schema;

beforeEach(function () {
    SchemaBuilder::migrate('dd_test');
    SchemaBuilder::seedTwoCompanies('dd_test');
});

function gateCheck(string $configDirectory, string $root = 'dd_test.companies:1', ?string $salt = null): array
{
    $config = (new ConfigLoader)->loadAll($configDirectory);
    $schemas = new SchemaSet(['dd_test' => app(Introspector::class)->inspect('dd_test')]);

    return app(ExtractionGate::class)->check(Root::parse($root), $config, $schemas, $salt ?? str_repeat('s', 32))->lines();
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

it('fails on a short or missing salt', function () {
    expect(gateCheck(initFixtureConfig(), salt: 'short'))->toContain('redaction.salt must be set to at least 16 characters (DEAD_DROP_REDACTION_SALT)');
});

it('fails on an invalid redaction placement', function () {
    $path = initFixtureConfig();
    file_put_contents($path.'/dd_test.php', str_replace("'email' => 'hash',\n            'password' => 'bcrypt:secret',", "'email' => 'null',\n            'password' => 'bcrypt:secret',", file_get_contents($path.'/dd_test.php')));

    expect(gateCheck($path))->toContain("users.email: 'null' is not allowed on a NOT NULL column");
});

it('fails on a root id that does not exist', function () {
    expect(gateCheck(initFixtureConfig(), 'dd_test.companies:1,999'))->toBe(['Root id 999 does not exist in dd_test.companies']);
});
```

Append to `tests/Feature/Commands/DumpDryRunTest.php`:

```php
it('refuses a dry run whose root id does not exist', function () {
    $path = initFixtureConfig();

    $this->artisan('dead-drop:dump', ['--root' => 'dd_test.companies:999', '--path' => $path, '--dry-run' => true])
        ->expectsOutputToContain('Root id 999 does not exist in dd_test.companies')
        ->assertFailed();
});
```

(The `'email' => 'null'` replacement targets the `users` block because `customers` has no `password` line; the two-line anchor makes it unambiguous.)

- [ ] **Step 2: Run tests to verify they fail**

Run: `composer test:unit -- --filter='ExtractionGateTest|refuses a dry run whose root'`
Expected: FAIL — class not found / dry run succeeds.

- [ ] **Step 3: Implement** per the Interfaces block. In `DumpCommand`, keep the gate call inside the existing `try` so the `finally` still drops key tables.

- [ ] **Step 4: Run tests to verify they pass**

Run: `composer test:unit -- --filter='ExtractionGateTest|DumpDryRunTest'`
Expected: PASS, 6 + 7 tests.

- [ ] **Step 5: Run the full gate and commit**

Run: `composer test`

```bash
git add src tests
git commit -m "feat: add the fail-closed extraction gate and validate root ids"
```

---

## Task 7: Real `dead-drop:dump` and `dead-drop:dumps`

**Files:**
- Create: `src/Extraction/ArtifactBuilder.php`, `src/Console/Commands/DumpsCommand.php`
- Modify: `src/Console/Commands/DumpCommand.php`, `src/DeadDropServiceProvider.php` (register `DumpsCommand`)
- Modify: `tests/Pest.php` (add `dumpFixture()`)
- Test: `tests/Feature/Commands/DumpCommandTest.php`, `tests/Feature/Commands/DumpsCommandTest.php`

**Interfaces:**
- Consumes: `Planner::plan(Root, ConfigSet, SchemaSet, ?DateTimeInterface): ExtractionPlan` (`->steps: list<PlanStep>`, `->unresolved`), `KeySetRepository::get()`, `ExecutorManager`, `Executor`, `ExtractionGate`, `Redactor`, `RedactionContext`, `ArtifactWriter`, `Manifest`, `TableManifest`, `Introspector`, `ConfigLoader`.
- Produces:
  - `final class ArtifactBuilder` with `__construct(ExecutorManager $executors, KeySetRepository $keys)` and `build(ExtractionPlan $plan, Root $root, ?DateTimeInterface $since, ConfigSet $config, SchemaSet $schemas, RedactionContext $context, Filesystem $disk, string $basePath, ?Closure $progress = null): Manifest` — resolves the executor (`driver()`), checks `supports()` for every connection's driver in the plan (throws `RuntimeException("Executor [{$name}] does not support the {$driver} driver used by connection [{$connection}].")`), creates the `ArtifactWriter` with `Manifest::newId(new DateTimeImmutable('now', new DateTimeZone('UTC')))`, writes the manifest with `STATUS_WRITING` and no tables, then for each step in plan order: `$keys->get()` (null for lookup steps), builds `Redactor::forTable()`, calls `export()`, appends a `TableManifest` (`columns` from `Table->columns` in schema order, `redacted` from the redactor), calls `$progress($tableArtifact)` when given, and finally writes the manifest again with `STATUS_COMPLETE`; returns the complete manifest. `packageVersion` = `Composer\InstalledVersions::isInstalled('kirilldakhniuk/dead-drop') ? InstalledVersions::getPrettyVersion('kirilldakhniuk/dead-drop') ?? 'dev' : 'dev'`.
  - `DumpCommand` signature gains `{--disk= : Disk holding artifacts (defaults to dead-drop.disk)}`. Without `--dry-run`: plan → `ExtractionGate::check(root, config, schemas, config('dead-drop.redaction.salt'))`; on failure print each line with `error()` and return FAILURE → `ArtifactBuilder::build()` with a progress closure printing `"  {connection}.{table} … {rows} rows"` → print the existing plan table, totals, unresolved list, then `Artifact: {disk}:{basePath}/{id}`; return SUCCESS. `--full` unchanged (fails). Exceptions: existing catches plus `RuntimeException` from the builder → `error()` + FAILURE. Key tables dropped in the `finally` as today.
  - `DumpsCommand` signature `dead-drop:dumps {--disk=} {--path=}`: lists `ArtifactReader::ids()` as a table `Id | Created | Root | Status | Tables | Rows | Size` (size human-readable, reuse the command's existing helper by extracting it to a small `protected` method on a shared abstract `DeadDropCommand` base class ONLY if both commands need it — otherwise duplicate the four-line helper); prints `No artifacts on {disk}:{path}.` when empty; SUCCESS.
  - `tests/Pest.php`: `function dumpFixture(string $root = 'dd_test.companies:1', ?string $configDirectory = null): string` — `fakeArtifactDisk()` must already have been called by the test; runs `initFixtureConfig()` unless a directory is given, then `test()->artisan('dead-drop:dump', ['--root' => $root, '--path' => $dir, '--disk' => 'local'])->assertSuccessful()`, and returns the newest id from `(new ArtifactReader(Storage::disk('local'), 'dead-drops'))->ids()[0]`.

- [ ] **Step 1: Write the failing tests**

`tests/Feature/Commands/DumpCommandTest.php`:

```php
<?php

declare(strict_types=1);

use DeadDrop\DeadDrop\Artifacts\ArtifactReader;
use DeadDrop\DeadDrop\Config\ConfigLoader;
use DeadDrop\DeadDrop\Planning\Planner;
use DeadDrop\DeadDrop\Planning\Root;
use DeadDrop\DeadDrop\Schema\Introspector;
use DeadDrop\DeadDrop\Schema\SchemaSet;
use DeadDrop\DeadDrop\Tests\Fixtures\SchemaBuilder;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    SchemaBuilder::migrate('dd_test');
    SchemaBuilder::seedTwoCompanies('dd_test');
    fakeArtifactDisk();
});

it('writes a complete artifact whose counts match the plan', function () {
    $path = initFixtureConfig();
    $id = dumpFixture('dd_test.companies:1', $path);
    $manifest = (new ArtifactReader(Storage::disk('local'), 'dead-drops'))->manifest($id);

    $plan = app(Planner::class)->plan(Root::parse('dd_test.companies:1'), (new ConfigLoader)->loadAll($path), new SchemaSet(['dd_test' => app(Introspector::class)->inspect('dd_test')]));
    $expected = [];
    foreach ($plan->steps as $step) {
        $expected["{$step->connection}.{$step->table}"] = $step->rows;
    }
    $actual = [];
    foreach ($manifest->tables as $table) {
        $actual[$table->key()] = $table->rows;
    }

    expect($manifest->isComplete())->toBeTrue()
        ->and($actual)->toBe($expected)
        ->and($manifest->root)->toBe('dd_test.companies:1')
        ->and($manifest->executor)->toBe('php')
        ->and(collect($manifest->tables)->firstWhere('table', 'users')->redacted)->toBe(['email', 'password']);
});

it('prints progress and the artifact location', function () {
    $path = initFixtureConfig();

    $this->artisan('dead-drop:dump', ['--root' => 'dd_test.companies:1', '--path' => $path, '--disk' => 'local'])
        ->expectsOutputToContain('dd_test.orders')
        ->expectsOutputToContain('Artifact: local:dead-drops/')
        ->assertSuccessful();
});

it('refuses to extract when the gate fails and writes nothing', function () {
    $path = initFixtureConfig();
    config()->set('dead-drop.redaction.salt', null);

    $this->artisan('dead-drop:dump', ['--root' => 'dd_test.companies:1', '--path' => $path, '--disk' => 'local'])
        ->expectsOutputToContain('redaction.salt must be set')
        ->assertFailed();

    expect(Storage::disk('local')->allFiles())->toBe([]);
});

it('refuses an executor that does not support the source driver', function () {
    $path = initFixtureConfig();
    $executor = Mockery::mock(\DeadDrop\DeadDrop\Extraction\Executor::class);
    $executor->shouldReceive('name')->andReturn('custom');
    $executor->shouldReceive('supports')->andReturn(false);
    app(\DeadDrop\DeadDrop\Extraction\ExecutorManager::class)->extend('custom', fn () => $executor);
    config()->set('dead-drop.executor', 'custom');

    $this->artisan('dead-drop:dump', ['--root' => 'dd_test.companies:1', '--path' => $path, '--disk' => 'local'])
        ->expectsOutputToContain('does not support the sqlite driver')
        ->assertFailed();
});

it('leaves no key tables behind after a real dump', function () {
    dumpFixture('dd_test.companies:1', initFixtureConfig());

    $leftovers = collect(\Illuminate\Support\Facades\Schema::connection('dd_test')->getTables())->pluck('name')->filter(fn ($n) => str_starts_with($n, 'dd_keys_'))->all();

    expect($leftovers)->toBe([]);
});
```

`tests/Feature/Commands/DumpsCommandTest.php`:

```php
<?php

declare(strict_types=1);

use DeadDrop\DeadDrop\Tests\Fixtures\SchemaBuilder;

beforeEach(function () {
    SchemaBuilder::migrate('dd_test');
    SchemaBuilder::seedTwoCompanies('dd_test');
    fakeArtifactDisk();
});

it('lists artifacts newest first', function () {
    $id = dumpFixture('dd_test.companies:1', initFixtureConfig());

    $this->artisan('dead-drop:dumps', ['--disk' => 'local'])
        ->expectsOutputToContain($id)
        ->expectsOutputToContain('complete')
        ->assertSuccessful();
});

it('says so when there are no artifacts', function () {
    $this->artisan('dead-drop:dumps', ['--disk' => 'local'])
        ->expectsOutputToContain('No artifacts on local:dead-drops.')
        ->assertSuccessful();
});
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `composer test:unit -- --filter='DumpCommandTest|DumpsCommandTest'`
Expected: FAIL — `dumpFixture()` undefined; dump refuses with "not implemented".

- [ ] **Step 3: Implement** per the Interfaces block. Remove the "extraction is not implemented yet" branch and its test in `DumpDryRunTest.php` (`refuses to extract without dry-run in this phase`) — replace that test with one asserting that `--full` still fails with `--full is not implemented yet`.

- [ ] **Step 4: Run tests to verify they pass**

Run: `composer test:unit -- --filter='DumpCommandTest|DumpsCommandTest|DumpDryRunTest'`
Expected: PASS, 5 + 2 + 7 tests.

- [ ] **Step 5: Run the full gate and commit**

Run: `composer test`

```bash
git add src tests
git commit -m "feat: extract artifacts with dead-drop:dump and list them with dead-drop:dumps"
```

---

## Task 8: Loading — loader, registry, pull runner

**Files:**
- Create: `src/Loading/Loader.php`, `src/Loading/NdjsonLoader.php`, `src/Loading/LoaderRegistry.php`, `src/Loading/PullReport.php`, `src/Loading/PullRunner.php`
- Test: `tests/Feature/Loading/PullRunnerTest.php`

**Interfaces:**
- Consumes: `ArtifactReader::rows()`, `Manifest`, `TableManifest`, `Introspector`, `DriverFactory`, `DatabaseDriver::disable/enableForeignKeyChecks()`, `RowCodec` (decoding already happened in the reader), `Illuminate\Database\Connection`.
- Produces (spec §9):
  - `interface Loader { public function format(): string; public function load(TableManifest $table, iterable $rows, Connection $target): int; }` — inserts `$rows` (already decoded arrays) in chunks of 500 with `$target->table($table->table)->insert($chunk)`, returns rows written.
  - `final class NdjsonLoader implements Loader` (`format()` = `'ndjson'`).
  - `final class LoaderRegistry` with `__construct(iterable $loaders)` (provider binds it with `[new NdjsonLoader]`), `for(string $format): Loader` (throws `InvalidArgumentException("No loader for artifact format [{$format}].")`).
  - `final readonly class PullReport(array $loaded, array $skipped)` — `loaded` is `array<string, int>` keyed `"{connection}.{table}"` in load order; `skipped` is `list<string>` (`"{connection}.{table}: not present on the target"`); `totalRows(): int`.
  - `final class PullRunner` with `__construct(Introspector $introspector, DriverFactory $drivers, LoaderRegistry $loaders)` and `run(Manifest $manifest, ArtifactReader $reader, string $targetConnection, ?Closure $progress = null): PullReport`:
    1. `$schema = $introspector->inspect($targetConnection)`; `$db = DB::connection($targetConnection)`; `$driver = $drivers->for($db)`.
    2. Pre-flight: for every manifest table present on the target, every `columns[].name` must exist on the target table, else throw `RuntimeException("Target table [{$table}] is missing columns: a, b")` before anything is written.
    3. `$driver->disableForeignKeyChecks($db)`; in `try`: for each table in manifest order — if absent on target, record skipped and continue; else `$db->transaction(function () { delete all rows; $written = $loader->load(...); if ($written !== $table->rows) throw new RuntimeException("Row count mismatch for [{$key}]: manifest says {$table->rows}, loaded {$written}"); })`, then `$progress($key, $written)`; `finally` `$driver->enableForeignKeyChecks($db)`.
    4. Return the report.

- [ ] **Step 1: Write the failing test**

`tests/Feature/Loading/PullRunnerTest.php`:

```php
<?php

declare(strict_types=1);

use DeadDrop\DeadDrop\Artifacts\ArtifactReader;
use DeadDrop\DeadDrop\Artifacts\Manifest;
use DeadDrop\DeadDrop\Artifacts\TableManifest;
use DeadDrop\DeadDrop\Loading\PullRunner;
use DeadDrop\DeadDrop\Tests\Fixtures\SchemaBuilder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    SchemaBuilder::migrate('dd_test');
    SchemaBuilder::seedTwoCompanies('dd_test');
    SchemaBuilder::migrate('dd_target');
    fakeArtifactDisk();
});

function pullFixtureArtifact(string $id): \DeadDrop\DeadDrop\Loading\PullReport
{
    $reader = new ArtifactReader(Storage::disk('local'), 'dead-drops');

    return app(PullRunner::class)->run($reader->manifest($id), $reader, 'dd_target');
}

it('loads every table of the artifact into the target in manifest order', function () {
    $id = dumpFixture('dd_test.companies:1', initFixtureConfig());

    $report = pullFixtureArtifact($id);

    expect($report->loaded['dd_test.orders'])->toBe(2)
        ->and(DB::connection('dd_target')->table('orders')->orderBy('id')->pluck('id')->all())->toBe([1, 2])
        ->and(DB::connection('dd_target')->table('orders')->where('id', 99)->exists())->toBeFalse()
        ->and(DB::connection('dd_target')->table('users')->where('id', 10)->value('email'))->toMatch('/^[0-9a-f]{16}@example\.test$/')
        ->and(DB::connection('dd_target')->table('companies')->where('id', 1)->value('stripe_id'))->toBe('redacted')
        ->and(array_keys($report->loaded)[0])->toBe('dd_test.companies');
});

it('replaces rows on a second pull instead of duplicating them', function () {
    $id = dumpFixture('dd_test.companies:1', initFixtureConfig());
    DB::connection('dd_target')->table('orders')->insert(['id' => 777, 'company_id' => 1, 'user_id' => 10, 'customer_id' => null, 'total' => 1, 'created_at' => null]);

    pullFixtureArtifact($id);
    pullFixtureArtifact($id);

    expect(DB::connection('dd_target')->table('orders')->count())->toBe(2)
        ->and(DB::connection('dd_target')->table('orders')->where('id', 777)->exists())->toBeFalse();
});

it('skips and names a table missing on the target', function () {
    $id = dumpFixture('dd_test.companies:1', initFixtureConfig());
    Schema::connection('dd_target')->drop('order_items');

    $report = pullFixtureArtifact($id);

    expect($report->skipped)->toBe(['dd_test.order_items: not present on the target'])
        ->and($report->loaded)->not->toHaveKey('dd_test.order_items');
});

it('refuses before writing when a target column is missing', function () {
    $id = dumpFixture('dd_test.companies:1', initFixtureConfig());
    Schema::connection('dd_target')->table('users', fn ($t) => $t->dropColumn('password'));

    expect(fn () => pullFixtureArtifact($id))->toThrow(RuntimeException::class, 'Target table [users] is missing columns: password')
        ->and(DB::connection('dd_target')->table('companies')->count())->toBe(0);
});

it('rolls a table back on a row count mismatch and restores foreign key checks', function () {
    $id = dumpFixture('dd_test.companies:1', initFixtureConfig());
    $reader = new ArtifactReader(Storage::disk('local'), 'dead-drops');
    $manifest = $reader->manifest($id);
    $tables = array_map(fn (TableManifest $t) => $t->table === 'orders' ? new TableManifest($t->connection, $t->table, $t->file, $t->format, 5, $t->bytes, $t->primaryKey, $t->columns, $t->redacted) : $t, $manifest->tables);
    $tampered = new Manifest($manifest->id, $manifest->status, $manifest->createdAt, $manifest->packageVersion, $manifest->root, $manifest->since, $manifest->executor, $manifest->connections, $tables, $manifest->unresolved);

    expect(fn () => app(PullRunner::class)->run($tampered, $reader, 'dd_target'))->toThrow(RuntimeException::class, 'Row count mismatch for [dd_test.orders]')
        ->and(DB::connection('dd_target')->table('orders')->count())->toBe(0)
        ->and((int) DB::connection('dd_target')->scalar('PRAGMA foreign_keys'))->toBe(1);
});
```

(The last assertion uses a raw PRAGMA in a TEST, which is allowed; the src rule bans it outside `Drivers/` only.)

- [ ] **Step 2: Run test to verify it fails**

Run: `composer test:unit -- --filter=PullRunnerTest`
Expected: FAIL — classes not found.

- [ ] **Step 3: Implement** per the Interfaces block; bind `LoaderRegistry` in the provider: `$this->app->singleton(LoaderRegistry::class, fn () => new LoaderRegistry([new NdjsonLoader]));`.

- [ ] **Step 4: Run test to verify it passes**

Run: `composer test:unit -- --filter=PullRunnerTest`
Expected: PASS, 5 tests.

- [ ] **Step 5: Run the full gate and commit**

Run: `composer test`

```bash
git add src tests
git commit -m "feat: load artifacts into a target connection with replace semantics"
```

---

## Task 9: `dead-drop:pull`

**Files:**
- Create: `src/Console/Commands/PullCommand.php`, `tests/Fixtures/RecordingAfterHook.php`
- Modify: `src/DeadDropServiceProvider.php` (register `PullCommand`)
- Test: `tests/Feature/Commands/PullCommandTest.php`

**Interfaces:**
- Consumes: `ArtifactReader`, `PullRunner`, `PullReport`, `Manifest`, `Laravel\Prompts\confirm`, `Illuminate\Support\Facades\Artisan`.
- Produces (spec §8):
  - Signature `dead-drop:pull {id? : Artifact id (defaults to the newest complete one)} {--connection= : Target connection (defaults to the default connection)} {--disk= : Disk holding artifacts} {--path= : Path on the disk} {--force : Skip the confirmation}`.
  - Flow: (1) `$allowed = (array) config('dead-drop.pull.allow_environments')`; if `! app()->environment($allowed)` → `error("dead-drop:pull refuses to run in the [{env}] environment; allowed: local, staging")` + FAILURE. (2) Resolve disk/path from options or config; `$reader = new ArtifactReader(Storage::disk($disk), $path)`. (3) `$manifest = $id ? $reader->manifest($id) : $reader->latestComplete()`; none → `error('No complete artifact found on {disk}:{path}.')` + FAILURE; `! isComplete()` → `error("Artifact [{$id}] is incomplete (status: writing) and cannot be loaded.")` + FAILURE. (4) Target = `--connection` or `config('database.default')`; validate against `config('database.connections')` like the other commands. (5) Unless `--force` or `! $this->input->isInteractive()`: `confirm(label: "Replace {N} tables on connection [{target}] with artifact [{id}]?", default: false)`; `false` → `info('Aborted.')` + SUCCESS. (6) `PullRunner::run()` with a progress closure printing `"  {key} … {rows} rows"`; catch `RuntimeException|QueryException|InvalidArgumentException` → `error()` + FAILURE. (7) `pull.after`: for each entry, string containing `:` or a space is treated as an Artisan command (`Artisan::call($command)`; non-zero exit → `error()` + FAILURE); a class-string that exists is resolved from the container and invoked with the `PullReport` (`__invoke(PullReport $report): void`). (8) Print `Loaded {totalRows} rows into {N} tables from artifact [{id}].` plus one line per skipped table; SUCCESS.
  - `tests/Fixtures/RecordingAfterHook.php`: `final class RecordingAfterHook { public static ?PullReport $report = null; public function __invoke(PullReport $report): void { self::$report = $report; } }`.

- [ ] **Step 1: Write the failing test**

`tests/Feature/Commands/PullCommandTest.php`:

```php
<?php

declare(strict_types=1);

use DeadDrop\DeadDrop\Tests\Fixtures\RecordingAfterHook;
use DeadDrop\DeadDrop\Tests\Fixtures\SchemaBuilder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    SchemaBuilder::migrate('dd_test');
    SchemaBuilder::seedTwoCompanies('dd_test');
    SchemaBuilder::migrate('dd_target');
    fakeArtifactDisk();
    config()->set('dead-drop.pull.allow_environments', ['testing']);
    RecordingAfterHook::$report = null;
});

it('refuses to run outside the allowed environments', function () {
    config()->set('dead-drop.pull.allow_environments', ['local']);

    $this->artisan('dead-drop:pull', ['--connection' => 'dd_target', '--disk' => 'local', '--force' => true])
        ->expectsOutputToContain('refuses to run in the [testing] environment')
        ->assertFailed();
});

it('loads the newest complete artifact into the target with --force', function () {
    dumpFixture('dd_test.companies:1', initFixtureConfig());

    $this->artisan('dead-drop:pull', ['--connection' => 'dd_target', '--disk' => 'local', '--force' => true])
        ->expectsOutputToContain('dd_test.orders')
        ->expectsOutputToContain('Loaded')
        ->assertSuccessful();

    expect(DB::connection('dd_target')->table('orders')->count())->toBe(2);
});

it('asks for confirmation and aborts on no', function () {
    dumpFixture('dd_test.companies:1', initFixtureConfig());
    $id = latestArtifactId();
    $count = count((new \DeadDrop\DeadDrop\Artifacts\ArtifactReader(Storage::disk('local'), 'dead-drops'))->manifest($id)->tables);

    $this->artisan('dead-drop:pull', ['--connection' => 'dd_target', '--disk' => 'local'])
        ->expectsConfirmation("Replace {$count} tables on connection [dd_target] with artifact [{$id}]?", 'no')
        ->expectsOutputToContain('Aborted.')
        ->assertSuccessful();

    expect(DB::connection('dd_target')->table('orders')->count())->toBe(0);
});

it('refuses an incomplete artifact and reports when none exists', function () {
    $this->artisan('dead-drop:pull', ['--connection' => 'dd_target', '--disk' => 'local', '--force' => true])
        ->expectsOutputToContain('No complete artifact found on local:dead-drops.')
        ->assertFailed();

    $id = dumpFixture('dd_test.companies:1', initFixtureConfig());
    $path = 'dead-drops/'.$id.'/manifest.json';
    Storage::disk('local')->put($path, str_replace('"complete"', '"writing"', Storage::disk('local')->get($path)));

    $this->artisan('dead-drop:pull', ['id' => $id, '--connection' => 'dd_target', '--disk' => 'local', '--force' => true])
        ->expectsOutputToContain('is incomplete')
        ->assertFailed();
});

it('runs the configured after hooks with the report', function () {
    dumpFixture('dd_test.companies:1', initFixtureConfig());
    config()->set('dead-drop.pull.after', [RecordingAfterHook::class]);

    $this->artisan('dead-drop:pull', ['--connection' => 'dd_target', '--disk' => 'local', '--force' => true])->assertSuccessful();

    expect(RecordingAfterHook::$report?->loaded['dd_test.orders'])->toBe(2);
});

it('refuses an unknown target connection', function () {
    dumpFixture('dd_test.companies:1', initFixtureConfig());

    $this->artisan('dead-drop:pull', ['--connection' => 'nope', '--disk' => 'local', '--force' => true])
        ->expectsOutputToContain('Unknown database connection [nope]')
        ->assertFailed();
});
```

Add to `tests/Pest.php`: `function latestArtifactId(): string { return (new ArtifactReader(Storage::disk('local'), 'dead-drops'))->ids()[0]; }` (with the imports at the top of `Pest.php`). The confirmation count `7` is the number of tables in the fixture plan for `companies:1` (companies, users, customers, orders, order_items, countries, comments only when it has rows — verify by reading the manifest in the test instead of hard-coding if the count differs: compute `count($manifest->tables)` via `ArtifactReader` and interpolate it).

- [ ] **Step 2: Run test to verify it fails**

Run: `composer test:unit -- --filter=PullCommandTest`
Expected: FAIL — command not found.

- [ ] **Step 3: Implement** per the Interfaces block. Reuse the connection validation shape from `CheckCommand`/`DumpCommand`.

- [ ] **Step 4: Run test to verify it passes**

Run: `composer test:unit -- --filter=PullCommandTest`
Expected: PASS, 6 tests.

- [ ] **Step 5: Run the full gate and commit**

Run: `composer test`

```bash
git add src tests
git commit -m "feat: add dead-drop:pull to load artifacts into local and staging databases"
```

---

## Task 10: End-to-end test and documentation

**Files:**
- Create: `tests/Feature/EndToEndTest.php`
- Modify: `README.md`, `CHANGELOG.md`, `resources/boost/skills/dead-drop-development/SKILL.md`

**Interfaces:** none new. Documentation must match implemented behaviour exactly (command names, options, defaults, config keys, messages, exit codes).

- [ ] **Step 1: Write the end-to-end test**

`tests/Feature/EndToEndTest.php`:

```php
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
    $this->artisan('dead-drop:dump', ['--root' => 'dd_test.companies:1', '--path' => $path, '--disk' => 'local'])->assertSuccessful();
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
```

- [ ] **Step 2: Run it**

Run: `composer test:unit -- --filter=EndToEndTest`
Expected: PASS, 2 tests (everything it uses exists after Task 9). If it fails, the failure is a real integration defect — fix it in the layer that owns it and add a focused test there.

- [ ] **Step 3: Update the docs**

- `README.md`: the Usage section gains "Dumping" (real `dump`: gate, salt env var `DEAD_DROP_REDACTION_SALT`, `DEAD_DROP_DISK`/`DEAD_DROP_PATH`, progress and `Artifact:` line, `--disk`), "Redaction" (the §6 table, `review`, key/reference columns never redacted, `hash` email form, `scramble` dates only, `exclude`/`window` scope descent only), "Artifacts" (layout, `manifest.json` fields, `dead-drop:dumps`), "Pulling" (`dead-drop:pull` options, allowed environments, replace semantics, confirmation and `--force`, `pull.after` hooks, planning and loading pin the write connection), "Executors" (`DEAD_DROP_EXECUTOR`, `ExecutorManager::extend()` example in a service provider, native executors not shipped), and the phase boundary updated (`--full`, native executors and composite keys still out). Remove statements that extraction is not implemented.
- `CHANGELOG.md`: extend the Unreleased entry.
- `resources/boost/skills/dead-drop-development/SKILL.md`: regenerate per the local `package-generate-skill` skill so it lists all five commands and the new config keys.

- [ ] **Step 4: Run the full gate and greps**

Run: `composer test`; the driver-boundary grep from Global Constraints (empty); `grep -rn 'App\\' src` (empty); `composer validate --strict`.

- [ ] **Step 5: Commit**

```bash
git add tests/Feature/EndToEndTest.php README.md CHANGELOG.md resources/boost
git commit -m "docs: document dumping, redaction, artifacts and pulling; add end-to-end test"
```

---

## Definition of done

- `composer test` green (PHPStan 7, Pint, 100% type coverage, Pest) with all pre-existing tests still passing.
- `init` → `check` → `dump` → `pull` works end to end on the SQLite fixture with redactions applied and referential completeness on the target (Task 10 test).
- `dump` refuses when any gate category fails and writes nothing; `pull` refuses outside allowed environments and on incomplete artifacts.
- The executor is selected through `ExecutorManager` and a custom executor registered with `extend()` is honoured.
- Both greps are empty; README documents every command and config key that exists and nothing that does not.

## After the plan: trial (spec §12)

Not part of the task loop. Once merged: install into `stupidbrains-app` as a path repository and run `init`, `check`, `dump --root=sqlite.users:1 --disk=local`, `pull --connection=target`; then the Testbench-CLI smoke test against `concom_app` through `dump`.

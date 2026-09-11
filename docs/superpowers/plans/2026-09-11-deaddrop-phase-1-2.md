# DeadDrop — Phase 1 & 2 Implementation Plan (repo-adapted)

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build the DeadDrop package skeleton, schema introspection, config generation with drift detection, and the traversal planner — ending with `dead-drop:dump --dry-run` reporting a referentially-complete row plan against a real schema.

**Architecture:** DeadDrop is a *planner*, not a data mover. This plan builds everything up to and including the plan object: driver abstraction over MySQL/Postgres/SQLite, a schema introspector, a three-source edge inferrer that scaffolds a reviewable config, and a traverser that descends from a root and ascends to referential closure using temporary key tables. No extraction, no redaction, no loading — those are phases 3+.

**Tech Stack:** PHP 8.3+, Laravel 12 and 13 (`illuminate/*` `^12.0||^13.0`), Orchestra Testbench 10/11, Pest 4/5, Laravel Prompts, Pint, PHPStan (Larastan) level 7.

**Spec:** not present in this repository. The original design spec (`docs/superpowers/specs/2026-09-11-deaddrop-design.md`) was not provided; this plan is the binding text. Rulings recorded in the SDD ledger are provisional against that missing spec.

## Global Constraints

- **This repository IS the package.** Package root is the repository root. There is no host application and no `artisan`. Package name `kirilldakhniuk/dead-drop`. Root namespace `DeadDrop\DeadDrop\` (PSR-4 from `src/`). Tests `DeadDrop\DeadDrop\Tests\` (from `tests/`).
- Every PHP file starts with `<?php` + `declare(strict_types=1);` (the arch test enforces this for the whole namespace, tests included).
- PHP `^8.3`. Laravel/Illuminate `^12.0||^13.0`. Code must work on both lanes and on Windows CI (no shell-outs, no hard-coded `/` path assumptions beyond what PHP normalises).
- **Tests are Pest** (`it('...')` closures, `expect()` and PHPUnit assertions via `$this`), never PHPUnit test classes and never `#[Test]`. `tests/Pest.php` binds `TestCase` to every test file. Shared test helpers are plain functions in `tests/Pest.php`; fixtures are classes under `tests/Fixtures/`.
- **Quality gates** (all must pass before every commit): `composer lint` (Pint, Laravel preset), `composer analyse` (PHPStan level 7 over `src`, `config`), `composer test:types` (100 % type coverage over `src`), `composer test:unit` (Pest). `composer test` runs all four. Every method, function parameter, return, and property in `src/` is fully typed; use `@param array<...>` / `@return array<...>` docblocks for arrays so PHPStan level 7 passes.
- **Arch presets ban these functions anywhere in the project (src and tests):** `md5`, `sha1`, `uniqid`, `rand`, `mt_rand`, `tempnam`, `str_shuffle`, `shuffle`, `array_rand`, `eval`, `exec`, `shell_exec`, `system`, `passthru`, `unserialize`, `extract`, `assert`, `var_export`, `var_dump`, `print_r`, `dump`, `dd`, `ddd`, `echo`, `print`, `die`, `exit`, `env()` (outside `config/`). Consequences: the config renderer hand-formats PHP (no `var_export`); temp directories in tests are `sys_get_temp_dir().'/dead-drop-'.Str::random(8)`.
- **Local toolchain:** the Homebrew `php` on PATH is broken. Prefix every command with the Herd binaries: `export PATH="$HOME/Library/Application Support/Herd/bin:$PATH"` (gives `php` 8.5, `php84`, `php83`, `composer`). Then `composer test:unit -- --filter='...'`, `composer lint`, `composer analyse`, `composer test`.
- Schema introspection goes through Laravel's native API — `Schema::connection()->getTables()`, `getColumns()`, `getIndexes()`, `getForeignKeys()`. Never doctrine/dbal.
- Driver-specific SQL lives **only** in `src/Drivers/`. `grep -rn "information_schema\|pg_class\|TEMPORARY" src --exclude-dir=Drivers` must return nothing.
- Collected primary keys live in temporary database tables, never in PHP arrays (arrays are only ever a chunk in flight).
- Every command must be fully drivable with `--no-interaction` plus flags. Laravel Prompts are a front door, never the only door.
- Commit after each task with the message given in the task, ending with the trailer line `Co-Authored-By: Claude Fable 5.1 <noreply@anthropic.com>`.
- Add only the files and dependencies a task names. No `App\` references anywhere in `src/`.

---

## File Structure

```
composer.json                      # deps: illuminate/console, illuminate/database, laravel/prompts
config/dead-drop.php
src/
  DeadDropServiceProvider.php
  Schema/
    Column.php  Index.php  ForeignKey.php  Table.php  DatabaseSchema.php  SchemaSet.php
    Introspector.php
    ColumnType.php                  # enum
  Drivers/
    DatabaseDriver.php              # interface
    MySqlDriver.php  PostgresDriver.php  SqliteDriver.php
    DriverFactory.php
  Inference/
    EdgeSource.php                  # enum: ForeignKey | Eloquent | Guessed | Manual
    InferredEdge.php
    EdgeInferrer.php                # composes the three sources
    Sources/
      ForeignKeySource.php  EloquentSource.php  NamingSource.php
    TableClassifier.php             # data | lookup | skip
    MorphPairDetector.php           # finds *_type / *_id column pairs
    SensitiveColumnDetector.php
  Config/
    TableClass.php                  # enum
    Reference.php  TableConfig.php  ConnectionConfig.php  ConfigSet.php
    ConfigLoader.php                # reads <dir>/<connection>.php into objects
    ConfigRenderer.php              # objects -> PHP source
    ConfigMerger.php                # preserves human decisions on re-run
    DriftReport.php  DriftDetector.php
  Planning/
    Root.php  Graph.php  GraphEdge.php
    KeySet.php  KeySetRepository.php
    Traverser.php  TraversalResult.php
    MorphResolver.php
    UnresolvedReference.php
    CircularConnectionException.php
    UnsupportedTableException.php
    PlanStep.php  ExtractionPlan.php
    Planner.php
  Console/Commands/
    InitCommand.php  CheckCommand.php  DumpCommand.php
tests/
  Pest.php  TestCase.php  ArchTest.php
  Unit/…  Feature/…
  Fixtures/SchemaBuilder.php
  Fixtures/Models/{Company,Order,Comment}.php
```

**Responsibility boundaries:**

- `Schema/` knows what the database looks like. It has no opinion about dumping.
- `Inference/` turns a schema into *suggestions*. Pure functions; no I/O, no writing.
- `Config/` is the human-reviewed contract. `ConfigMerger` is the file that makes the package survive a year.
- `Planning/` consumes schema + config + a root, and produces an `ExtractionPlan`. It is the only place that talks to the database during traversal.
- `Drivers/` is the only engine-specific code.

---

## Task 1: Package scaffold

**Files:**
- Modify: `composer.json` (add `illuminate/console`, `illuminate/database`, `laravel/prompts`; remove the facade alias)
- Modify: `phpstan.neon.dist` (paths: `src`, `config` only)
- Replace: `config/dead-drop.php`
- Replace: `src/DeadDropServiceProvider.php`
- Replace: `tests/TestCase.php`
- Delete: `src/DeadDrop.php`, `src/Facades/DeadDrop.php`, `src/Console/Commands/DeadDropCommand.php`, `routes/dead-drop.php`, `resources/views/placeholder.blade.php`, `lang/en/messages.php`, `database/migrations/2026_01_01_000000_create_dead_drop_placeholder_table.php`, `tests/Unit/ExampleTest.php`, `tests/Feature/ExampleTest.php` (remove the now-empty `routes/`, `lang/`, `database/`, `resources/views/` directories; keep `resources/boost/` and `public/`)
- Create: `tests/Feature/ServiceProviderTest.php`

**Interfaces:**
- Consumes: nothing.
- Produces: `DeadDrop\DeadDrop\DeadDropServiceProvider`; `DeadDrop\DeadDrop\Tests\TestCase` (extends `Orchestra\Testbench\TestCase`, registers the provider, and defines an in-memory `sqlite` connection named `dd_test` as the default connection).

- [ ] **Step 1: Write the failing test**

`tests/Feature/ServiceProviderTest.php`:

```php
<?php

declare(strict_types=1);

it('merges a config with the expected top-level keys', function () {
    $config = config('dead-drop');

    expect($config)->toBeArray()
        ->toHaveKeys(['disk', 'path', 'config_path', 'model_paths', 'redaction', 'binaries', 'pull']);
});

it('defaults to a reserved email domain', function () {
    expect(config('dead-drop.redaction.email_domain'))->toBe('example.test');
});

it('defines the in-memory test connection as the default', function () {
    expect(config('database.default'))->toBe('dd_test');
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `composer test:unit -- --filter=ServiceProviderTest`
Expected: FAIL — `dead-drop` config has only `placeholder`; default connection is `sqlite`.

- [ ] **Step 3: Rewrite the scaffold**

`composer.json` — add to `require`:

```json
"illuminate/console": "^12.0||^13.0",
"illuminate/database": "^12.0||^13.0",
"laravel/prompts": "^0.3"
```

(keep `illuminate/support`), remove the `extra.laravel.aliases` block, keep the provider entry. Run `composer update --lock` (Herd PATH) so the lock file, which is git-ignored, reflects the change; `composer validate` must pass.

`phpstan.neon.dist` — `paths:` becomes `src` and `config`.

`config/dead-drop.php`:

```php
<?php

declare(strict_types=1);

return [
    'disk' => env('DEAD_DROP_DISK', 's3'),
    'path' => env('DEAD_DROP_PATH', 'dead-drops'),

    // Directory (relative to config_path()) holding one <connection>.php per enrolled connection.
    'config_path' => 'dead-drop',

    // Directories (relative to base_path()) scanned for Eloquent models during inference.
    'model_paths' => ['app/Models'],

    'redaction' => [
        'salt' => env('DEAD_DROP_REDACTION_SALT'),
        'email_domain' => env('DEAD_DROP_EMAIL_DOMAIN', 'example.test'),
    ],

    'binaries' => [
        'psql' => env('DEAD_DROP_PSQL'),
        'mysql' => env('DEAD_DROP_MYSQL'),
        'mysqlsh' => env('DEAD_DROP_MYSQLSH'),
    ],

    'pull' => [
        'allow_environments' => ['local', 'staging'],
        'after' => [],
    ],
];
```

`src/DeadDropServiceProvider.php`:

```php
<?php

declare(strict_types=1);

namespace DeadDrop\DeadDrop;

use Illuminate\Support\ServiceProvider;

class DeadDropServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/dead-drop.php', 'dead-drop');
    }

    public function boot(): void
    {
        if (! $this->app->runningInConsole()) {
            return;
        }

        $this->publishes([
            __DIR__.'/../config/dead-drop.php' => config_path('dead-drop.php'),
        ], ['dead-drop', 'dead-drop-config']);
    }
}
```

`tests/TestCase.php`:

```php
<?php

declare(strict_types=1);

namespace DeadDrop\DeadDrop\Tests;

use DeadDrop\DeadDrop\DeadDropServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [DeadDropServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'dd_test');
        $app['config']->set('database.connections.dd_test', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => true,
            'use_native_json' => true,
        ]);
    }
}
```

(`use_native_json` makes Laravel's SQLite grammar declare `json()` columns as `json` rather than `text`, so type normalisation in later tasks sees the real intent.) Leave `tests/Pest.php` and `tests/ArchTest.php` as they are.

- [ ] **Step 4: Run test to verify it passes**

Run: `composer test:unit -- --filter=ServiceProviderTest`
Expected: PASS, 3 tests.

- [ ] **Step 5: Run the full gate and commit**

Run: `composer test` — all four gates green (the arch suite and the type-coverage gate must still pass with the placeholder files gone).

```bash
git add -A
git commit -m "feat: scaffold planner package, service provider and config"
```

---

## Task 2: Schema value objects

**Files:**
- Create: `src/Schema/ColumnType.php`, `src/Schema/Column.php`, `src/Schema/Index.php`, `src/Schema/ForeignKey.php`, `src/Schema/Table.php`, `src/Schema/DatabaseSchema.php`
- Test: `tests/Unit/Schema/TableTest.php`

**Interfaces:**
- Consumes: nothing.
- Produces:
  - `enum ColumnType: string { case Integer = 'int'; case String = 'string'; case Boolean = 'bool'; case Json = 'json'; case DateTime = 'datetime'; case Decimal = 'decimal'; case Uuid = 'uuid'; case Binary = 'binary'; case Other = 'other'; }`
  - `Column(string $name, ColumnType $type, string $nativeType, bool $nullable, bool $autoIncrement, ?string $default)`
  - `Index(string $name, array $columns, bool $unique, bool $primary)`
  - `ForeignKey(array $columns, string $foreignTable, array $foreignColumns)`
  - `Table(string $name, array $columns, array $indexes, array $foreignKeys, int $estimatedRows, int $estimatedBytes)` with `column(string): ?Column`, `primaryKey(): ?string`, `hasCompositePrimaryKey(): bool`, `isUnique(string $column): bool`, `columnNames(): array`
  - `DatabaseSchema(string $connection, string $driver, array $tables)` with `table(string): ?Table`, `tableNames(): array` (`$tables` keyed by table name)

- [ ] **Step 1: Write the failing test**

`tests/Unit/Schema/TableTest.php`:

```php
<?php

declare(strict_types=1);

use DeadDrop\DeadDrop\Schema\Column;
use DeadDrop\DeadDrop\Schema\ColumnType;
use DeadDrop\DeadDrop\Schema\Index;
use DeadDrop\DeadDrop\Schema\Table;

function usersTable(array $indexes): Table
{
    return new Table(
        name: 'users',
        columns: [
            'id' => new Column('id', ColumnType::Integer, 'bigint', false, true, null),
            'email' => new Column('email', ColumnType::String, 'varchar', false, false, null),
        ],
        indexes: $indexes,
        foreignKeys: [],
        estimatedRows: 100,
        estimatedBytes: 4096,
    );
}

it('reports a single column primary key', function () {
    $table = usersTable([new Index('PRIMARY', ['id'], true, true)]);

    expect($table->primaryKey())->toBe('id')
        ->and($table->hasCompositePrimaryKey())->toBeFalse();
});

it('detects a composite primary key', function () {
    $table = usersTable([new Index('PRIMARY', ['id', 'email'], true, true)]);

    expect($table->hasCompositePrimaryKey())->toBeTrue()
        ->and($table->primaryKey())->toBeNull();
});

it('knows a column is unique', function () {
    $table = usersTable([
        new Index('PRIMARY', ['id'], true, true),
        new Index('users_email_unique', ['email'], true, false),
    ]);

    expect($table->isUnique('email'))->toBeTrue();
});

it('does not treat a column in a composite unique index as unique on its own', function () {
    $table = usersTable([
        new Index('PRIMARY', ['id'], true, true),
        new Index('users_email_team_unique', ['email', 'team_id'], true, false),
    ]);

    expect($table->isUnique('email'))->toBeFalse();
});

it('lists column names and looks a column up by name', function () {
    $table = usersTable([]);

    expect($table->columnNames())->toBe(['id', 'email'])
        ->and($table->column('email')?->type)->toBe(ColumnType::String)
        ->and($table->column('missing'))->toBeNull();
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `composer test:unit -- --filter=TableTest`
Expected: FAIL — `Class "DeadDrop\DeadDrop\Schema\Column" not found`.

- [ ] **Step 3: Write the value objects**

All are `final readonly class` with promoted constructor properties and array-shape docblocks. The only logic lives in `Table`:

```php
<?php

declare(strict_types=1);

namespace DeadDrop\DeadDrop\Schema;

final readonly class Table
{
    /**
     * @param  array<string, Column>  $columns
     * @param  array<int, Index>  $indexes
     * @param  array<int, ForeignKey>  $foreignKeys
     */
    public function __construct(
        public string $name,
        public array $columns,
        public array $indexes,
        public array $foreignKeys,
        public int $estimatedRows,
        public int $estimatedBytes,
    ) {}

    public function column(string $name): ?Column
    {
        return $this->columns[$name] ?? null;
    }

    /** @return array<int, string> */
    public function columnNames(): array
    {
        return array_keys($this->columns);
    }

    private function primaryIndex(): ?Index
    {
        foreach ($this->indexes as $index) {
            if ($index->primary) {
                return $index;
            }
        }

        return null;
    }

    public function primaryKey(): ?string
    {
        $index = $this->primaryIndex();

        return $index !== null && count($index->columns) === 1 ? $index->columns[0] : null;
    }

    public function hasCompositePrimaryKey(): bool
    {
        $index = $this->primaryIndex();

        return $index !== null && count($index->columns) > 1;
    }

    public function isUnique(string $column): bool
    {
        foreach ($this->indexes as $index) {
            if ($index->unique && $index->columns === [$column]) {
                return true;
            }
        }

        return false;
    }
}
```

Note `isUnique` compares the whole column list — a column inside a composite unique index is *not* individually unique, and treating it as such would make the redaction layer pick the wrong transformer later.

- [ ] **Step 4: Run test to verify it passes**

Run: `composer test:unit -- --filter=TableTest`
Expected: PASS, 5 tests.

- [ ] **Step 5: Run the full gate and commit**

Run: `composer test`

```bash
git add src/Schema tests/Unit/Schema
git commit -m "feat: add schema value objects"
```

---

## Task 3: Driver contract and implementations

**Files:**
- Create: `src/Drivers/DatabaseDriver.php`, `src/Drivers/SqliteDriver.php`, `src/Drivers/MySqlDriver.php`, `src/Drivers/PostgresDriver.php`, `src/Drivers/DriverFactory.php`
- Test: `tests/Feature/Drivers/SqliteDriverTest.php`

**Interfaces:**
- Consumes: `DeadDrop\DeadDrop\Schema\ColumnType`.
- Produces:

```php
interface DatabaseDriver
{
    public function name(): string;                                   // 'sqlite' | 'mysql' | 'pgsql'
    public function normaliseType(string $nativeType): ColumnType;
    /** @return array<string, int> table => estimated rows */
    public function estimatedRowCounts(Connection $connection): array;
    public function createKeyTable(Connection $connection, string $name, ColumnType $keyType): void;
    /** @param array<int, int|string> $keys */
    public function insertKeys(Connection $connection, string $name, array $keys): void;
    public function countKeys(Connection $connection, string $name): int;
    public function dropKeyTable(Connection $connection, string $name): void;
    public function quote(string $identifier): string;
}
```

`DriverFactory::for(Connection $connection): DatabaseDriver` — an **instance** method (it is injected into `Introspector` and `KeySetRepository`), mapping `$connection->getDriverName()` (`sqlite`, `mysql`/`mariadb`, `pgsql`); any other driver throws `InvalidArgumentException`.

**Why `estimatedRowCounts` is a driver method:** `SELECT COUNT(*)` on a 200M-row table is a multi-minute table scan, and `init` calls it for every table. MySQL reads `information_schema.TABLES.TABLE_ROWS`; Postgres reads `pg_class.reltuples`; SQLite has no estimate and falls back to a real count per table (fine — it's only used in tests).

**Why key tables are driver methods:** temporary table syntax and lifetime differ, and this is the mechanism that keeps millions of collected ids out of PHP memory.

- [ ] **Step 1: Write the failing test**

`tests/Feature/Drivers/SqliteDriverTest.php`:

```php
<?php

declare(strict_types=1);

use DeadDrop\DeadDrop\Drivers\DriverFactory;
use DeadDrop\DeadDrop\Drivers\SqliteDriver;
use DeadDrop\DeadDrop\Schema\ColumnType;
use Illuminate\Support\Facades\DB;

it('resolves a driver from the connection', function () {
    expect((new DriverFactory)->for(DB::connection()))->toBeInstanceOf(SqliteDriver::class);
});

it('round trips keys through a key table', function () {
    $driver = new SqliteDriver;
    $connection = DB::connection();

    $driver->createKeyTable($connection, 'dd_keys_users', ColumnType::Integer);
    $driver->insertKeys($connection, 'dd_keys_users', range(1, 2500));

    expect($driver->countKeys($connection, 'dd_keys_users'))->toBe(2500);

    $driver->dropKeyTable($connection, 'dd_keys_users');
});

it('does not grow the key table when a duplicate key is inserted', function () {
    $driver = new SqliteDriver;
    $connection = DB::connection();

    $driver->createKeyTable($connection, 'dd_keys_users', ColumnType::Integer);
    $driver->insertKeys($connection, 'dd_keys_users', [1, 2, 3]);
    $driver->insertKeys($connection, 'dd_keys_users', [3, 4]);

    expect($driver->countKeys($connection, 'dd_keys_users'))->toBe(4);

    $driver->dropKeyTable($connection, 'dd_keys_users');
});

it('stores string keys when the key type is not integer', function () {
    $driver = new SqliteDriver;
    $connection = DB::connection();

    $driver->createKeyTable($connection, 'dd_keys_docs', ColumnType::Uuid);
    $driver->insertKeys($connection, 'dd_keys_docs', ['a', 'b', 'a']);

    expect($driver->countKeys($connection, 'dd_keys_docs'))->toBe(2);

    $driver->dropKeyTable($connection, 'dd_keys_docs');
});

it('normalises native types', function () {
    $driver = new SqliteDriver;

    expect($driver->normaliseType('integer'))->toBe(ColumnType::Integer)
        ->and($driver->normaliseType('varchar'))->toBe(ColumnType::String)
        ->and($driver->normaliseType('datetime'))->toBe(ColumnType::DateTime)
        ->and($driver->normaliseType('numeric'))->toBe(ColumnType::Decimal)
        ->and($driver->normaliseType('json'))->toBe(ColumnType::Json)
        ->and($driver->normaliseType('tinyint'))->toBe(ColumnType::Boolean);
});
```

The duplicate test matters: the traverser calls `insertKeys` repeatedly across closure iterations and relies on the key table de-duplicating, or the fixpoint never converges.

- [ ] **Step 2: Run test to verify it fails**

Run: `composer test:unit -- --filter=SqliteDriverTest`
Expected: FAIL — `Class "DeadDrop\DeadDrop\Drivers\DriverFactory" not found`.

- [ ] **Step 3: Implement the drivers**

Key table creation uses a primary key so duplicates are absorbed, and inserts chunk to stay under parameter limits:

```php
public function createKeyTable(Connection $connection, string $name, ColumnType $keyType): void
{
    $type = $keyType === ColumnType::Integer ? 'INTEGER' : 'TEXT';

    $connection->statement("DROP TABLE IF EXISTS {$this->quote($name)}");
    $connection->statement("CREATE TEMPORARY TABLE {$this->quote($name)} (k {$type} PRIMARY KEY)");
}

public function insertKeys(Connection $connection, string $name, array $keys): void
{
    foreach (array_chunk(array_values(array_unique($keys)), 500) as $chunk) {
        $connection->table($name)->insertOrIgnore(array_map(fn (int|string $k): array => ['k' => $k], $chunk));
    }
}
```

`MySqlDriver` uses `CREATE TEMPORARY TABLE … (k BIGINT UNSIGNED PRIMARY KEY)` for integer keys and `(k VARCHAR(191) PRIMARY KEY)` otherwise — **plain InnoDB temporary tables, not `ENGINE=MEMORY`** (MEMORY tables are capped by `max_heap_table_size`, 16 MB by default, which fails around 1.8M bigint keys). `PostgresDriver` uses `CREATE TEMPORARY TABLE … (k BIGINT PRIMARY KEY) ON COMMIT PRESERVE ROWS` / `(k TEXT PRIMARY KEY)`.

`quote()` returns backticks for MySQL, double quotes for Postgres and SQLite.

`estimatedRowCounts`:

```php
// MySqlDriver
$rows = $connection->select(
    'select TABLE_NAME as t, TABLE_ROWS as n from information_schema.TABLES where TABLE_SCHEMA = ?',
    [$connection->getDatabaseName()]
);

// PostgresDriver — reltuples is -1 for never-analysed tables, hence the clamp
$rows = $connection->select(
    "select relname as t, greatest(reltuples, 0)::bigint as n from pg_class c
     join pg_namespace n on n.oid = c.relnamespace
     where c.relkind = 'r' and n.nspname = current_schema()"
);

// SqliteDriver — no estimates exist; real count per table via Schema::getTableListing()
```

Each returns `array<string, int>` (`t => (int) n`).

`normaliseType` mapping (lower-cased native type name, `strtok` before any `(`):
- Integer: `int`, `integer`, `bigint`, `smallint`, `mediumint`, `int2`, `int4`, `int8`, `serial`, `bigserial`
- Boolean: `bool`, `boolean`, `tinyint` (MySQL `tinyint(1)` convention; document it)
- Decimal: `decimal`, `numeric`, `float`, `double`, `real`, `float4`, `float8`, `money`
- DateTime: `date`, `datetime`, `timestamp`, `timestamptz`, `time`, `timetz`, `year`
- Json: `json`, `jsonb`
- Uuid: `uuid`
- Binary: `blob`, `binary`, `varbinary`, `bytea`, `tinyblob`, `mediumblob`, `longblob`
- String: `char`, `varchar`, `text`, `tinytext`, `mediumtext`, `longtext`, `character varying`, `enum`, `set`, `citext`, `inet`
- Everything else: Other

Put the shared mapping in one place (a `TypeNormaliser` trait or a small private-const map on each driver — keep it greppable; do not add a fourth driver class).

- [ ] **Step 4: Run test to verify it passes**

Run: `composer test:unit -- --filter=SqliteDriverTest`
Expected: PASS, 5 tests.

- [ ] **Step 5: Verify the driver boundary holds**

Run: `grep -rn "information_schema\|pg_class\|TEMPORARY" src --exclude-dir=Drivers`
Expected: no output. If anything appears, move it into a driver.

- [ ] **Step 6: Run the full gate and commit**

Run: `composer test`

```bash
git add src/Drivers tests/Feature/Drivers
git commit -m "feat: add database driver abstraction with key tables"
```

---

## Task 4: Introspector

**Files:**
- Create: `src/Schema/Introspector.php`, `src/Schema/SchemaSet.php`
- Create: `tests/Fixtures/SchemaBuilder.php`
- Test: `tests/Feature/Schema/IntrospectorTest.php`

**Interfaces:**
- Consumes: `DatabaseDriver`, `DriverFactory`, all `Schema/` value objects.
- Produces:
  - `Introspector::__construct(DriverFactory $drivers)`, resolvable from the container with no bindings
  - `Introspector::inspect(string $connection): DatabaseSchema`
  - `SchemaSet(array $schemas)` (keyed by connection) with `for(string $connection): DatabaseSchema` (throws `InvalidArgumentException` for an unknown connection), `connections(): array`
  - `SchemaBuilder::migrate(string $connection): void` — test fixture creating the canonical schema below
  - `SchemaBuilder::seedTwoCompanies(string $connection): void` — test fixture seeding the canonical rows below (used from Task 12 on; build it now so the fixture is one file)

**The fixture schema** used by every downstream test (`Schema::connection($connection)->create(...)`):

| table | columns | notes |
|---|---|---|
| `companies` | `id`, `name` string, `stripe_id` string nullable | root; `stripe_id` is a sensitive column so classification is `data` |
| `users` | `id`, `company_id` unsignedBigInteger, `email` string unique, `password` string, `created_by` unsignedBigInteger nullable, `failed_job_id` unsignedBigInteger nullable | self-reference on `created_by`; `failed_job_id` is a guessed edge into a skipped table |
| `customers` | `id`, `email` string | reached only by closure |
| `orders` | `id`, `company_id` unsignedBigInteger, `user_id` unsignedBigInteger, `customer_id` unsignedBigInteger nullable, `total` decimal(10,2), `created_at` dateTime nullable | **declared FK** `company_id` → `companies.id` via `$table->foreign('company_id')->references('id')->on('companies')` |
| `order_items` | `id`, `order_id` unsignedBigInteger, `sku` string | two hops from root |
| `countries` | `id`, `code` string | lookup |
| `failed_jobs` | `id`, `payload` json | skip |
| `comments` | `id`, `commentable_type` string, `commentable_id` unsignedBigInteger, `body` text | polymorphic |

**The fixture rows** (`seedTwoCompanies`):

| table | rows |
|---|---|
| `companies` | 1 Acme (stripe_id `cus_1`), 2 Globex (null) |
| `users` | 10 (company 1, `a@acme.test`, created_by null, failed_job_id null); 50 (company 2, `b@globex.test`, created_by null, failed_job_id 1); 60 (company 2, `c@globex.test`, created_by 50, failed_job_id null) |
| `customers` | 7 `seven@example.test`; 8 `eight@example.test` |
| `orders` | 1 (company 1, user 50, customer 7, total 10.00, created_at `2026-01-15 00:00:00`); 2 (company 1, user 10, customer null, total 20.00, created_at `2026-06-15 00:00:00`); 99 (company 2, user 50, customer 8, total 30.00, created_at `2026-03-01 00:00:00`) |
| `order_items` | 1 (order 1, `SKU-1`); 2 (order 1, `SKU-2`); 3 (order 99, `SKU-3`) |
| `countries` | 1 `US`; 2 `CA` |
| `failed_jobs` | 1 payload `{}` |
| `comments` | none (Task 13 inserts its own) |

`password` values are any fixed string, e.g. `'secret'`.

- [ ] **Step 1: Write the failing test**

`tests/Feature/Schema/IntrospectorTest.php`:

```php
<?php

declare(strict_types=1);

use DeadDrop\DeadDrop\Schema\ColumnType;
use DeadDrop\DeadDrop\Schema\Introspector;
use DeadDrop\DeadDrop\Tests\Fixtures\SchemaBuilder;

beforeEach(fn () => SchemaBuilder::migrate('dd_test'));

it('finds every table', function () {
    $schema = app(Introspector::class)->inspect('dd_test');

    expect($schema->tableNames())->toContain('companies', 'order_items', 'comments')
        ->and($schema->connection)->toBe('dd_test')
        ->and($schema->driver)->toBe('sqlite');
});

it('reads columns with normalised types and nullability', function () {
    $orders = app(Introspector::class)->inspect('dd_test')->table('orders');

    expect($orders->column('company_id')->type)->toBe(ColumnType::Integer)
        ->and($orders->column('created_at')->type)->toBe(ColumnType::DateTime)
        ->and($orders->column('total')->type)->toBe(ColumnType::Decimal)
        ->and($orders->column('customer_id')->nullable)->toBeTrue()
        ->and($orders->column('company_id')->nullable)->toBeFalse()
        ->and($orders->column('id')->autoIncrement)->toBeTrue();
});

it('reads the primary key and unique indexes', function () {
    $users = app(Introspector::class)->inspect('dd_test')->table('users');

    expect($users->primaryKey())->toBe('id')
        ->and($users->isUnique('email'))->toBeTrue()
        ->and($users->isUnique('password'))->toBeFalse();
});

it('reads declared foreign keys', function () {
    $orders = app(Introspector::class)->inspect('dd_test')->table('orders');

    $targets = array_map(fn ($fk) => $fk->foreignTable, $orders->foreignKeys);

    expect($targets)->toContain('companies');
});

it('reports a row estimate per table', function () {
    SchemaBuilder::seedTwoCompanies('dd_test');

    expect(app(Introspector::class)->inspect('dd_test')->table('orders')->estimatedRows)->toBe(3);
});

it('ignores temporary tables', function () {
    DB::connection('dd_test')->statement('CREATE TEMPORARY TABLE "dd_keys_scratch" (k INTEGER PRIMARY KEY)');

    expect(app(Introspector::class)->inspect('dd_test')->tableNames())->not->toContain('dd_keys_scratch');
});
```

(Import `Illuminate\Support\Facades\DB` in the test file.)

- [ ] **Step 2: Run test to verify it fails**

Run: `composer test:unit -- --filter=IntrospectorTest`
Expected: FAIL — `Class "DeadDrop\DeadDrop\Tests\Fixtures\SchemaBuilder" not found`.

- [ ] **Step 3: Implement**

```php
public function inspect(string $connection): DatabaseSchema
{
    $db = DB::connection($connection);
    $driver = $this->drivers->for($db);
    $builder = Schema::connection($connection);
    $estimates = $driver->estimatedRowCounts($db);

    $tables = [];

    foreach ($builder->getTables() as $meta) {
        if (($meta['schema'] ?? null) === 'temp') {
            continue; // SQLite lists temporary tables alongside real ones
        }

        $name = $meta['name'];

        $columns = [];
        foreach ($builder->getColumns($name) as $col) {
            $columns[$col['name']] = new Column(
                name: $col['name'],
                type: $driver->normaliseType($col['type_name']),
                nativeType: $col['type'],
                nullable: $col['nullable'],
                autoIncrement: $col['auto_increment'],
                default: $col['default'] === null ? null : (string) $col['default'],
            );
        }

        $indexes = array_map(
            fn (array $i): Index => new Index($i['name'], $i['columns'], $i['unique'], $i['primary']),
            $builder->getIndexes($name),
        );

        $foreignKeys = array_map(
            fn (array $f): ForeignKey => new ForeignKey($f['columns'], $f['foreign_table'], $f['foreign_columns']),
            $builder->getForeignKeys($name),
        );

        $tables[$name] = new Table(
            name: $name,
            columns: $columns,
            indexes: $indexes,
            foreignKeys: $foreignKeys,
            estimatedRows: $estimates[$name] ?? 0,
            estimatedBytes: (int) ($meta['size'] ?? 0),
        );
    }

    return new DatabaseSchema($connection, $db->getDriverName(), $tables);
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `composer test:unit -- --filter=IntrospectorTest`
Expected: PASS, 6 tests.

- [ ] **Step 5: Run the full gate and commit**

Run: `composer test`

```bash
git add src/Schema tests
git commit -m "feat: introspect schema into value objects"
```

---

## Task 5: Foreign-key edge source

**Files:**
- Create: `src/Inference/EdgeSource.php`, `src/Inference/InferredEdge.php`, `src/Inference/Sources/ForeignKeySource.php`
- Test: `tests/Unit/Inference/ForeignKeySourceTest.php`

**Interfaces:**
- Consumes: `DatabaseSchema`, `Table`, `ForeignKey`.
- Produces:
  - `enum EdgeSource: string { case ForeignKey = 'fk'; case Eloquent = 'eloquent'; case Guessed = 'guessed'; case Manual = 'manual'; }` with `rank(): int` (fk 3, eloquent 2, guessed 1, manual 0). `Manual` is the source of a reference a human typed into the config by hand (Task 9).
  - `InferredEdge(string $table, string $column, ?string $targetConnection, string $targetTable, string $targetColumn, EdgeSource $source, bool $descend = true)` — `descend` is set false by `NamingSource` for audit-style columns (Task 7)
  - `ForeignKeySource::infer(DatabaseSchema $schema): array` returning `list<InferredEdge>`

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

use DeadDrop\DeadDrop\Inference\EdgeSource;
use DeadDrop\DeadDrop\Inference\Sources\ForeignKeySource;
use DeadDrop\DeadDrop\Schema\Introspector;
use DeadDrop\DeadDrop\Tests\Fixtures\SchemaBuilder;

beforeEach(fn () => SchemaBuilder::migrate('dd_test'));

it('infers an edge from a declared foreign key', function () {
    $schema = app(Introspector::class)->inspect('dd_test');

    $edges = (new ForeignKeySource)->infer($schema);

    $edge = collect($edges)->first(fn ($e) => $e->table === 'orders' && $e->column === 'company_id');

    expect($edge)->not->toBeNull()
        ->and($edge->targetTable)->toBe('companies')
        ->and($edge->targetColumn)->toBe('id')
        ->and($edge->targetConnection)->toBeNull()
        ->and($edge->source)->toBe(EdgeSource::ForeignKey)
        ->and($edge->descend)->toBeTrue();
});

it('ignores composite foreign keys', function () {
    Schema::connection('dd_test')->create('shipments', function ($table) {
        $table->id();
        $table->unsignedBigInteger('order_id');
        $table->unsignedBigInteger('company_id');
        $table->foreign(['order_id', 'company_id'])->references(['id', 'company_id'])->on('orders');
    });

    $edges = (new ForeignKeySource)->infer(app(Introspector::class)->inspect('dd_test'));

    expect(collect($edges)->where('table', 'shipments'))->toBeEmpty();
});
```

(Import `Illuminate\Support\Facades\Schema`.)

- [ ] **Step 2: Run test to verify it fails**

Run: `composer test:unit -- --filter=ForeignKeySourceTest`
Expected: FAIL — `Class "DeadDrop\DeadDrop\Inference\Sources\ForeignKeySource" not found`.

- [ ] **Step 3: Implement**

```php
public function infer(DatabaseSchema $schema): array
{
    $edges = [];

    foreach ($schema->tables as $table) {
        foreach ($table->foreignKeys as $fk) {
            if (count($fk->columns) !== 1) {
                continue;   // composite keys are refused in v1
            }

            $edges[] = new InferredEdge(
                table: $table->name,
                column: $fk->columns[0],
                targetConnection: null,
                targetTable: $fk->foreignTable,
                targetColumn: $fk->foreignColumns[0],
                source: EdgeSource::ForeignKey,
            );
        }
    }

    return $edges;
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `composer test:unit -- --filter=ForeignKeySourceTest`
Expected: PASS, 2 tests.

- [ ] **Step 5: Run the full gate and commit**

Run: `composer test`

```bash
git add src/Inference tests/Unit/Inference
git commit -m "feat: infer edges from foreign key constraints"
```

---

## Task 6: Eloquent edge source

**Files:**
- Create: `src/Inference/Sources/EloquentSource.php`
- Create: `tests/Fixtures/Models/Order.php`, `tests/Fixtures/Models/Company.php`, `tests/Fixtures/Models/Comment.php`, `tests/Fixtures/Models/Customer.php`
- Test: `tests/Unit/Inference/EloquentSourceTest.php`

**Interfaces:**
- Consumes: `InferredEdge`, `EdgeSource`, `DatabaseSchema`.
- Produces: `EloquentSource::__construct(array $modelPaths)` (absolute directory paths; missing directories are ignored), `EloquentSource::infer(DatabaseSchema $schema): array` returning `list<InferredEdge>`, `EloquentSource::skipped(): array` returning `list<string>` of `Class::method` names that were skipped for lacking a relation return type (so `init` can report them).

**Why this source exists and why it is dangerous:** most Laravel apps declare relationships in models, not in the database. But discovering relations means calling methods, and calling arbitrary methods can be slow and can have side effects.

**The safety rule:** only call a public method with **zero required parameters** whose **declared return type** is a subclass of `Illuminate\Database\Eloquent\Relations\Relation`. Never call an untyped method to see what falls out. Methods without a return type are skipped and reported, not guessed at.

Only `BelongsTo` produces edges — it is the relation where *this* table holds the key. `HasMany` is the same edge seen from the other end and would double-count. `MorphTo` emits nothing here — polymorphic columns are handled by `MorphPairDetector` (Task 8).

Only models whose `getTable()` is a table in `$schema` (and whose `getConnectionName()` is null or equals `$schema->connection`) are considered.

**Class discovery:** for each `*.php` file under each model path (recursive), read the file and extract `namespace X;` and `class Y` with a regex, `class_exists()` the FQCN (relying on the autoloader), and keep it only if it is a non-abstract subclass of `Illuminate\Database\Eloquent\Model`. No `include`/`require` of arbitrary files.

**Fixtures** (namespace `DeadDrop\DeadDrop\Tests\Fixtures\Models`, each `protected $connection = 'dd_test';`):
- `Order`: `belongsTo(Company::class)` typed `: BelongsTo`; `customer(): BelongsTo`.
- `Company`: `orders(): HasMany`; `public function explode()` with **no return type** whose body is `throw new RuntimeException('called');`.
- `Customer`: empty model (needed as the `belongsTo` target).
- `Comment`: `commentable(): MorphTo`.

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

use DeadDrop\DeadDrop\Inference\EdgeSource;
use DeadDrop\DeadDrop\Inference\Sources\EloquentSource;
use DeadDrop\DeadDrop\Schema\Introspector;
use DeadDrop\DeadDrop\Tests\Fixtures\SchemaBuilder;

beforeEach(fn () => SchemaBuilder::migrate('dd_test'));

function fixtureModelSource(): EloquentSource
{
    return new EloquentSource([__DIR__.'/../../Fixtures/Models']);
}

it('infers an edge from a belongs-to relation', function () {
    $edges = fixtureModelSource()->infer(app(Introspector::class)->inspect('dd_test'));

    $edge = collect($edges)->first(fn ($e) => $e->table === 'orders' && $e->column === 'customer_id');

    expect($edge)->not->toBeNull()
        ->and($edge->targetTable)->toBe('customers')
        ->and($edge->targetColumn)->toBe('id')
        ->and($edge->source)->toBe(EdgeSource::Eloquent);
});

it('does not infer edges from has-many relations', function () {
    $edges = fixtureModelSource()->infer(app(Introspector::class)->inspect('dd_test'));

    expect(collect($edges)->first(fn ($e) => $e->table === 'companies' && $e->targetTable === 'orders'))->toBeNull();
});

it('does not infer edges from morph-to relations', function () {
    $edges = fixtureModelSource()->infer(app(Introspector::class)->inspect('dd_test'));

    expect(collect($edges)->where('table', 'comments'))->toBeEmpty();
});

it('never calls a method without a relation return type and reports it', function () {
    // Fixtures\Models\Company::explode() throws if called.
    $source = fixtureModelSource();
    $source->infer(app(Introspector::class)->inspect('dd_test'));

    expect($source->skipped())->toContain(\DeadDrop\DeadDrop\Tests\Fixtures\Models\Company::class.'::explode');
});

it('ignores a model path that does not exist', function () {
    $edges = (new EloquentSource([__DIR__.'/nope']))->infer(app(Introspector::class)->inspect('dd_test'));

    expect($edges)->toBe([]);
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `composer test:unit -- --filter=EloquentSourceTest`
Expected: FAIL — `Class "DeadDrop\DeadDrop\Inference\Sources\EloquentSource" not found`.

- [ ] **Step 3: Implement**

```php
/** @return list<ReflectionMethod> */
private function relationMethods(ReflectionClass $class): array
{
    $methods = [];

    foreach ($class->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
        if ($method->isStatic() || $method->getDeclaringClass()->getName() !== $class->getName()) {
            continue;
        }

        if ($method->getNumberOfRequiredParameters() > 0) {
            continue;
        }

        $type = $method->getReturnType();

        if (! $type instanceof ReflectionNamedType || $type->isBuiltin() || ! is_subclass_of($type->getName(), Relation::class)) {
            $this->skipped[] = $class->getName().'::'.$method->getName();

            continue;
        }

        $methods[] = $method;
    }

    return $methods;
}
```

Report as skipped only methods that *could* have been relations: non-static, zero required params, declared on the model class itself, and either untyped or typed with something other than a `Relation` subclass. (Inherited `Model` methods are excluded by the declaring-class check.) Then instantiate the model, call each qualifying method, and for `BelongsTo` read `getForeignKeyName()` / `getOwnerKeyName()` / `getRelated()->getTable()`.

- [ ] **Step 4: Run test to verify it passes**

Run: `composer test:unit -- --filter=EloquentSourceTest`
Expected: PASS, 5 tests.

- [ ] **Step 5: Run the full gate and commit**

Run: `composer test`

```bash
git add src/Inference tests
git commit -m "feat: infer edges from eloquent belongsTo relations"
```

---

## Task 7: Naming source and the composed inferrer

**Files:**
- Create: `src/Inference/Sources/NamingSource.php`, `src/Inference/EdgeInferrer.php`
- Modify: `src/DeadDropServiceProvider.php` (bind `EloquentSource` with model paths from config)
- Test: `tests/Unit/Inference/EdgeInferrerTest.php`

**Interfaces:**
- Consumes: all three sources.
- Produces:
  - `EdgeInferrer::__construct(ForeignKeySource $fk, EloquentSource $eloquent, NamingSource $naming)`, `EdgeInferrer::infer(DatabaseSchema $schema): array` returning `array<string, InferredEdge>` keyed `"{table}.{column}"`.
  - Provider `register()`: `$this->app->bind(EloquentSource::class, fn ($app) => new EloquentSource(array_map(fn (string $p) => $app->basePath($p), (array) $app['config']->get('dead-drop.model_paths', []))));` — so `app(EdgeInferrer::class)` resolves. In tests, `config()->set('dead-drop.model_paths', [...])` with a path relative to `base_path()` is awkward, so tests build the inferrer explicitly (see helper below).

**Precedence:** foreign key beats Eloquent beats guess. The first source to claim a `table.column` wins, and the winning edge carries its source so the rendered config can record it.

`NamingSource` maps `foo_id` → table `Str::plural('foo')` (also try `Str::snake`/exact `foo` when the plural does not exist) when that table exists in the schema and has a single-column primary key; the edge targets that primary key. It **skips** the column when it is itself the table's primary key. It emits edges for a hard-coded blocklist of columns that look like references but aren't relationships worth descending — `created_by`, `updated_by`, `deleted_by`, `owner_id`, `parent_id` (and any `*_by` column) — resolving `*_by` to the `users` table when it exists, always with `descend: false`, because descending them is what turns a scoped dump into a full one.

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

use DeadDrop\DeadDrop\Inference\EdgeInferrer;
use DeadDrop\DeadDrop\Inference\EdgeSource;
use DeadDrop\DeadDrop\Inference\Sources\EloquentSource;
use DeadDrop\DeadDrop\Inference\Sources\ForeignKeySource;
use DeadDrop\DeadDrop\Inference\Sources\NamingSource;
use DeadDrop\DeadDrop\Schema\Introspector;
use DeadDrop\DeadDrop\Tests\Fixtures\SchemaBuilder;

beforeEach(fn () => SchemaBuilder::migrate('dd_test'));

function fixtureEdges(): array
{
    $inferrer = new EdgeInferrer(
        new ForeignKeySource,
        new EloquentSource([__DIR__.'/../../Fixtures/Models']),
        new NamingSource,
    );

    return $inferrer->infer(app(Introspector::class)->inspect('dd_test'));
}

it('lets a foreign key edge beat a guessed edge for the same column', function () {
    expect(fixtureEdges()['orders.company_id']->source)->toBe(EdgeSource::ForeignKey);
});

it('lets an eloquent edge beat a guessed edge for the same column', function () {
    expect(fixtureEdges()['orders.customer_id']->source)->toBe(EdgeSource::Eloquent);
});

it('guesses an edge from column naming when nothing else declares it', function () {
    $edge = fixtureEdges()['order_items.order_id'];

    expect($edge->targetTable)->toBe('orders')
        ->and($edge->targetColumn)->toBe('id')
        ->and($edge->source)->toBe(EdgeSource::Guessed)
        ->and($edge->descend)->toBeTrue();
});

it('does not guess an edge for a column with no matching table', function () {
    expect(fixtureEdges())->not->toHaveKey('comments.commentable_id');
});

it('marks self-referencing audit columns as ascend only', function () {
    $edge = fixtureEdges()['users.created_by'];

    expect($edge->targetTable)->toBe('users')
        ->and($edge->descend)->toBeFalse();
});

it('guesses an edge into a framework table so the planner can report it', function () {
    expect(fixtureEdges()['users.failed_job_id']->targetTable)->toBe('failed_jobs');
});

it('resolves the inferrer from the container', function () {
    expect(app(EdgeInferrer::class))->toBeInstanceOf(EdgeInferrer::class);
});
```

`InferredEdge` already carries `descend` (Task 5); `NamingSource` is what sets it false.

- [ ] **Step 2: Run test to verify it fails**

Run: `composer test:unit -- --filter=EdgeInferrerTest`
Expected: FAIL — `Class "DeadDrop\DeadDrop\Inference\EdgeInferrer" not found`.

- [ ] **Step 3: Implement**

```php
public function infer(DatabaseSchema $schema): array
{
    $edges = [];

    foreach ([$this->fk, $this->eloquent, $this->naming] as $source) {
        foreach ($source->infer($schema) as $edge) {
            $key = "{$edge->table}.{$edge->column}";

            $edges[$key] ??= $edge;
        }
    }

    ksort($edges);

    return $edges;
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `composer test:unit -- --filter=EdgeInferrerTest`
Expected: PASS, 7 tests.

- [ ] **Step 5: Run the full gate and commit**

Run: `composer test`

```bash
git add src tests/Unit/Inference
git commit -m "feat: compose edge inference with source precedence"
```

---

## Task 8: Table classification, morph pairs and sensitive columns

**Files:**
- Create: `src/Config/TableClass.php`, `src/Inference/MorphPairDetector.php`, `src/Inference/SensitiveColumnDetector.php`, `src/Inference/TableClassifier.php`
- Test: `tests/Unit/Inference/TableClassifierTest.php`, `tests/Unit/Inference/SensitiveColumnDetectorTest.php`

**Interfaces:**
- Produces:
  - `enum TableClass: string { case Data = 'data'; case Lookup = 'lookup'; case Skip = 'skip'; }`
  - `MorphPairDetector::detect(Table $table): ?array` returning `['type' => 'commentable_type', 'id' => 'commentable_id']` for the first `{prefix}_type` / `{prefix}_id` column pair, else `null`.
  - `SensitiveColumnDetector::detect(Table $table): array` returning `array<string, string>` `[column => suggested transformer string]`; `needsReview(Table $table): array` returning `list<string>` of columns a human must decide: every JSON column and the `type` column of every morph pair.
  - `TableClassifier::__construct(SensitiveColumnDetector $sensitive, MorphPairDetector $morphs)`, `classify(Table $table, array $edges): TableClass` where `$edges` is the full `array<string, InferredEdge>` from `EdgeInferrer`.

**Skip patterns** (exact or prefix): `migrations`, `jobs`, `job_batches`, `failed_jobs`, `cache`, `cache_locks`, `sessions`, `password_reset_tokens`, `password_resets`, `telescope_*`, `pulse_*`, `personal_access_tokens`.

**Lookup heuristic:** classified `lookup` when the table has **no outbound edges**, **no morph pair**, **no detected sensitive columns**, and an estimated row count under 10,000. Reference tables point at nothing and hold nothing personal; transaction tables always point at something; a table with personal data must be scoped, not copied whole. Everything else is `data`.

**Sensitive column patterns** → suggested transformer (match on lower-cased column name):

| pattern | suggestion |
|---|---|
| `email`, `email_address`, `*_email` | `hash` |
| `phone`, `mobile`, `telephone`, `fax`, `*_phone` | `mask` |
| `password`, `password_hash` | `bcrypt:secret` |
| `*token*`, `*secret*`, `api_key`, `*_key` | `null` |
| `ssn`, `tax_id`, `national_id` | `hash` |
| `iban`, `account_number`, `routing_number`, `card_number`, `cvv` | `null` |
| `date_of_birth`, `dob`, `birth_date` | `scramble` |
| `stripe_*`, `*_customer_id` where the column type is String | `fixed:redacted` |

`hash` is the default for unique-indexed sensitive columns because it is collision-safe and SQL-expressible; `faker` is never auto-suggested. `bcrypt:secret` means "a bcrypt hash of the literal `secret`, computed at extraction time" — the suggestion is a deterministic string so re-running `init` never churns the file.

- [ ] **Step 1: Write the failing tests**

`tests/Unit/Inference/TableClassifierTest.php`:

```php
<?php

declare(strict_types=1);

use DeadDrop\DeadDrop\Config\TableClass;
use DeadDrop\DeadDrop\Inference\EdgeInferrer;
use DeadDrop\DeadDrop\Inference\MorphPairDetector;
use DeadDrop\DeadDrop\Inference\SensitiveColumnDetector;
use DeadDrop\DeadDrop\Inference\Sources\EloquentSource;
use DeadDrop\DeadDrop\Inference\Sources\ForeignKeySource;
use DeadDrop\DeadDrop\Inference\Sources\NamingSource;
use DeadDrop\DeadDrop\Inference\TableClassifier;
use DeadDrop\DeadDrop\Schema\Introspector;
use DeadDrop\DeadDrop\Tests\Fixtures\SchemaBuilder;

beforeEach(fn () => SchemaBuilder::migrate('dd_test'));

function classifyFixtureTable(string $table): TableClass
{
    $schema = app(Introspector::class)->inspect('dd_test');
    $edges = (new EdgeInferrer(new ForeignKeySource, new EloquentSource([__DIR__.'/../../Fixtures/Models']), new NamingSource))->infer($schema);

    return (new TableClassifier(new SensitiveColumnDetector, new MorphPairDetector))->classify($schema->table($table), $edges);
}

it('skips framework tables', function () {
    Schema::connection('dd_test')->create('telescope_entries', fn ($t) => $t->id());

    expect(classifyFixtureTable('failed_jobs'))->toBe(TableClass::Skip)
        ->and(classifyFixtureTable('telescope_entries'))->toBe(TableClass::Skip);
});

it('classifies a small table with no outbound edges and nothing sensitive as lookup', function () {
    expect(classifyFixtureTable('countries'))->toBe(TableClass::Lookup);
});

it('classifies a table with outbound edges as data even when small', function () {
    expect(classifyFixtureTable('orders'))->toBe(TableClass::Data);
});

it('classifies a table holding sensitive columns as data even without outbound edges', function () {
    expect(classifyFixtureTable('companies'))->toBe(TableClass::Data)
        ->and(classifyFixtureTable('customers'))->toBe(TableClass::Data);
});

it('classifies a table with a morph pair as data', function () {
    expect(classifyFixtureTable('comments'))->toBe(TableClass::Data);
});
```

`tests/Unit/Inference/SensitiveColumnDetectorTest.php`:

```php
<?php

declare(strict_types=1);

use DeadDrop\DeadDrop\Inference\MorphPairDetector;
use DeadDrop\DeadDrop\Inference\SensitiveColumnDetector;
use DeadDrop\DeadDrop\Schema\Introspector;
use DeadDrop\DeadDrop\Schema\Table;
use DeadDrop\DeadDrop\Tests\Fixtures\SchemaBuilder;

beforeEach(fn () => SchemaBuilder::migrate('dd_test'));

function fixtureTable(string $name): Table
{
    return app(Introspector::class)->inspect('dd_test')->table($name);
}

it('suggests hash for an email column', function () {
    expect((new SensitiveColumnDetector)->detect(fixtureTable('users'))['email'])->toBe('hash');
});

it('suggests a deterministic bcrypt placeholder for a password column', function () {
    expect((new SensitiveColumnDetector)->detect(fixtureTable('users'))['password'])->toBe('bcrypt:secret');
});

it('suggests a fixed value for a stripe identifier', function () {
    expect((new SensitiveColumnDetector)->detect(fixtureTable('companies'))['stripe_id'])->toBe('fixed:redacted');
});

it('does not suggest a transformer for an ordinary column', function () {
    expect((new SensitiveColumnDetector)->detect(fixtureTable('orders')))->not->toHaveKey('total');
});

it('flags every json column for human review', function () {
    expect((new SensitiveColumnDetector)->needsReview(fixtureTable('failed_jobs')))->toContain('payload');
});

it('flags a polymorphic column pair for human review', function () {
    expect((new SensitiveColumnDetector)->needsReview(fixtureTable('comments')))->toContain('commentable_type');
});

it('detects a morph pair', function () {
    expect((new MorphPairDetector)->detect(fixtureTable('comments')))->toBe(['type' => 'commentable_type', 'id' => 'commentable_id'])
        ->and((new MorphPairDetector)->detect(fixtureTable('orders')))->toBeNull();
});
```

(Import `Illuminate\Support\Facades\Schema` where used.)

- [ ] **Step 2: Run tests to verify they fail**

Run: `composer test:unit -- --filter='TableClassifierTest|SensitiveColumnDetectorTest'`
Expected: FAIL — classes not found.

- [ ] **Step 3: Implement the three classes**

Pattern lists live as `private const` arrays on each class so they are greppable and reviewable in one place. `SensitiveColumnDetector::needsReview()` uses `MorphPairDetector` internally (construct it inline or inject with a default).

- [ ] **Step 4: Run tests to verify they pass**

Run: `composer test:unit -- --filter='TableClassifierTest|SensitiveColumnDetectorTest'`
Expected: PASS, 12 tests.

- [ ] **Step 5: Run the full gate and commit**

Run: `composer test`

```bash
git add src tests
git commit -m "feat: classify tables and detect sensitive columns"
```

---

## Task 9: Config objects, renderer, loader and merger

**Files:**
- Create: `src/Config/Reference.php`, `src/Config/TableConfig.php`, `src/Config/ConnectionConfig.php`, `src/Config/ConfigSet.php`, `src/Config/ConfigRenderer.php`, `src/Config/ConfigLoader.php`, `src/Config/ConfigMerger.php`
- Modify: `tests/Pest.php` (add the `tempDirectory()` helper)
- Test: `tests/Unit/Config/ConfigRoundTripTest.php`, `tests/Unit/Config/ConfigMergerTest.php`

**Interfaces:**
- Produces:
  - `Reference(?string $connection, string $table, string $column, bool $descend, EdgeSource $source)` with `toArray(): array` and `static fromArray(string|array $raw): self`. Accepted raw forms: `'companies.id'`, `'dd_test.companies.id'` (connection-qualified, three parts), or `[0 => 'companies.id', 'descend' => bool, 'source' => 'fk'|'eloquent'|'guessed'|'manual']`. Missing `descend` → `true`; missing `source` → `EdgeSource::Manual`.
  - `TableConfig(string $name, TableClass $class, array $columns, array $references, array $redact, ?string $window, ?string $exclude, ?array $morph, bool $removed = false)` — `columns` is `list<string>` (the schema's column names at last init; this is what makes column drift detectable), `references` is `array<string, Reference>` keyed by column, `redact` is `array<string, string>`, `morph` is `?array{type: string, id: string}`.
  - `ConnectionConfig(string $connection, array $tables)` (keyed by table name) with `table(string): ?TableConfig`, `static fromArray(string $connection, array $raw): self`, `toArray(): array`
  - `ConfigSet(array $connections)` (keyed by connection) with `for(string): ConnectionConfig` (throws `InvalidArgumentException` when unknown), `has(string): bool`, `connections(): array`
  - `ConfigRenderer::render(ConnectionConfig $config): string` — returns PHP source
  - `ConfigLoader::load(string $connection, string $directory): ?ConnectionConfig` (returns null when `<directory>/<connection>.php` does not exist), `ConfigLoader::loadAll(string $directory): ConfigSet` (every `*.php` in the directory; the basename is the connection name)
  - `ConfigMerger::merge(ConnectionConfig $existing, ConnectionConfig $discovered): ConnectionConfig`
  - `tests/Pest.php`: `function tempDirectory(): string` — creates and returns `sys_get_temp_dir().'/dead-drop-'.Str::random(8)` (never `tempnam`).

**Rendered format** (exact; the init test in Task 10 does a string replace against it, and Pint must leave it untouched):

```php
<?php

declare(strict_types=1);

return [
    'companies' => [
        'class' => 'data',
        'columns' => ['id', 'name', 'stripe_id'],
        'redact' => [
            'stripe_id' => 'fixed:redacted',
        ],
    ],
    'users' => [
        'class' => 'data',
        'columns' => ['id', 'company_id', 'email', 'password', 'created_by', 'failed_job_id'],
        'references' => [
            'company_id' => ['companies.id', 'source' => 'guessed'],
            'created_by' => ['users.id', 'descend' => false, 'source' => 'guessed'],
        ],
        'redact' => [
            'email' => 'hash',
            'password' => 'bcrypt:secret',
        ],
    ],
];
```

- Tables sort alphabetically; keys appear in the fixed order `class`, `removed` (only when true), `window`, `exclude`, `morph`, `columns`, `references`, `redact`; null/empty keys are omitted (`columns` is always present). `columns` keep schema order; `references` and `redact` entries sort alphabetically by column name.
- A reference is always rendered in array form with its `source`, and `'descend' => false` only when false. A connection-qualified target renders as `'dd_test.companies.id'`. The renderer emits **no comments**: the source is data the merger needs back, and comments cannot be parsed.
- Strings are single-quoted with `\\` and `\'` escaped; four-space indentation; trailing commas everywhere.
- `TableConfig::toArray()` / `ConnectionConfig::toArray()` are the single source of truth for key order and sorting; the renderer only formats their output and has no per-class special cases (a human-typed reference on a skip table is rendered, never dropped).

**This is the most important task in the plan.** `ConfigMerger` is what makes the package survive schema churn. Its contract:

- A human decision always wins: `class`, `redact` entries, `descend`, reference target, `window`, `exclude`, `morph` from `$existing` are preserved verbatim. A null `window`/`exclude`/`morph` in `$existing` is filled from `$discovered`.
- `columns` always come from `$discovered` (the schema is the truth about columns).
- New tables, new references and new redact suggestions from `$discovered` are added.
- A reference whose *source* improved (`rank()` strictly greater) upgrades its `source` but keeps the human's `descend` flag and target.
- References and redact entries present in `$existing` but absent from `$discovered` are kept (a human may have typed them).
- Tables present in `$existing` but gone from `$discovered` are retained and marked `removed: true` so a human deletes them deliberately. A table that comes back is un-marked.

- [ ] **Step 1: Write the failing tests**

`tests/Unit/Config/ConfigRoundTripTest.php`:

```php
<?php

declare(strict_types=1);

use DeadDrop\DeadDrop\Config\ConfigLoader;
use DeadDrop\DeadDrop\Config\ConfigRenderer;
use DeadDrop\DeadDrop\Config\ConnectionConfig;
use DeadDrop\DeadDrop\Config\Reference;
use DeadDrop\DeadDrop\Config\TableClass;
use DeadDrop\DeadDrop\Config\TableConfig;
use DeadDrop\DeadDrop\Inference\EdgeSource;

it('parses a rendered config back into an equal object', function () {
    $original = new ConnectionConfig('mysql', [
        'orders' => new TableConfig(
            name: 'orders',
            class: TableClass::Data,
            columns: ['id', 'company_id', 'created_by', 'created_at'],
            references: [
                'company_id' => new Reference(null, 'companies', 'id', true, EdgeSource::ForeignKey),
                'created_by' => new Reference('dd_test', 'users', 'id', false, EdgeSource::Guessed),
            ],
            redact: ['note' => 'null'],
            window: 'created_at',
            exclude: "status = 'draft'",
            morph: null,
        ),
        'comments' => new TableConfig('comments', TableClass::Data, ['id'], [], [], null, null, ['type' => 'commentable_type', 'id' => 'commentable_id']),
        'legacy' => new TableConfig('legacy', TableClass::Skip, ['id'], [], [], null, null, null, removed: true),
    ]);

    $directory = tempDirectory();
    file_put_contents($directory.'/mysql.php', (new ConfigRenderer)->render($original));

    $parsed = (new ConfigLoader)->load('mysql', $directory);

    expect($parsed)->toEqual($original);
});

it('renders tables alphabetically with keys in a fixed order', function () {
    $config = new ConnectionConfig('mysql', [
        'users' => new TableConfig('users', TableClass::Data, ['id', 'email'], [], ['email' => 'hash'], null, null, null),
        'companies' => new TableConfig('companies', TableClass::Lookup, ['id'], [], [], null, null, null),
    ]);

    $source = (new ConfigRenderer)->render($config);

    expect($source)->toStartWith("<?php\n\ndeclare(strict_types=1);\n\nreturn [\n    'companies' => [\n        'class' => 'lookup',\n        'columns' => ['id'],\n    ],\n    'users' => [\n        'class' => 'data',\n        'columns' => ['id', 'email'],\n        'redact' => [\n            'email' => 'hash',\n        ],\n    ],\n];\n");
});

it('accepts hand written shorthand references as manual', function () {
    $reference = Reference::fromArray('companies.id');

    expect($reference->table)->toBe('companies')
        ->and($reference->column)->toBe('id')
        ->and($reference->connection)->toBeNull()
        ->and($reference->descend)->toBeTrue()
        ->and($reference->source)->toBe(EdgeSource::Manual);
});

it('loads every connection file in a directory', function () {
    $directory = tempDirectory();
    file_put_contents($directory.'/a.php', "<?php\n\nreturn ['t' => ['class' => 'data', 'columns' => ['id']]];\n");
    file_put_contents($directory.'/b.php', "<?php\n\nreturn [];\n");

    $set = (new ConfigLoader)->loadAll($directory);

    expect($set->connections())->toBe(['a', 'b'])
        ->and($set->for('a')->table('t')->class)->toBe(TableClass::Data)
        ->and((new ConfigLoader)->load('missing', $directory))->toBeNull();
});
```

`tests/Unit/Config/ConfigMergerTest.php`:

```php
<?php

declare(strict_types=1);

use DeadDrop\DeadDrop\Config\ConfigMerger;
use DeadDrop\DeadDrop\Config\ConnectionConfig;
use DeadDrop\DeadDrop\Config\TableClass;
use DeadDrop\DeadDrop\Inference\EdgeSource;

function connectionConfig(array $tables): ConnectionConfig
{
    foreach ($tables as $name => &$table) {
        $table['columns'] ??= ['id'];
    }

    return ConnectionConfig::fromArray('mysql', $tables);
}

it('preserves a human edited class', function () {
    $merged = (new ConfigMerger)->merge(
        connectionConfig(['audit_log' => ['class' => 'skip']]),
        connectionConfig(['audit_log' => ['class' => 'data']]),
    );

    expect($merged->table('audit_log')->class)->toBe(TableClass::Skip);
});

it('preserves a human edited redaction and adds new suggestions', function () {
    $merged = (new ConfigMerger)->merge(
        connectionConfig(['users' => ['class' => 'data', 'redact' => ['email' => 'keep']]]),
        connectionConfig(['users' => ['class' => 'data', 'redact' => ['email' => 'hash', 'phone' => 'mask']]]),
    );

    expect($merged->table('users')->redact)->toBe(['email' => 'keep', 'phone' => 'mask']);
});

it('preserves a descend false flag while upgrading the source', function () {
    $merged = (new ConfigMerger)->merge(
        connectionConfig(['users' => ['class' => 'data', 'references' => [
            'created_by' => ['users.id', 'descend' => false, 'source' => 'guessed'],
        ]]]),
        connectionConfig(['users' => ['class' => 'data', 'references' => [
            'created_by' => ['users.id', 'descend' => true, 'source' => 'fk'],
        ]]]),
    );

    $reference = $merged->table('users')->references['created_by'];

    expect($reference->descend)->toBeFalse()
        ->and($reference->source)->toBe(EdgeSource::ForeignKey);
});

it('keeps a human edited reference target and a manual reference nothing rediscovered', function () {
    $merged = (new ConfigMerger)->merge(
        connectionConfig(['orders' => ['class' => 'data', 'references' => [
            'buyer_id' => 'customers.id',
            'legacy_ref' => 'legacy.id',
        ]]]),
        connectionConfig(['orders' => ['class' => 'data', 'references' => [
            'buyer_id' => ['users.id', 'source' => 'guessed'],
        ]]]),
    );

    expect($merged->table('orders')->references['buyer_id']->table)->toBe('customers')
        ->and($merged->table('orders')->references['buyer_id']->source)->toBe(EdgeSource::Guessed)
        ->and($merged->table('orders')->references)->toHaveKey('legacy_ref');
});

it('takes columns from the discovered schema', function () {
    $merged = (new ConfigMerger)->merge(
        connectionConfig(['users' => ['class' => 'data', 'columns' => ['id', 'old']]]),
        connectionConfig(['users' => ['class' => 'data', 'columns' => ['id', 'new']]]),
    );

    expect($merged->table('users')->columns)->toBe(['id', 'new']);
});

it('fills a null window and morph from discovery but never overrides a human value', function () {
    $merged = (new ConfigMerger)->merge(
        connectionConfig(['orders' => ['class' => 'data', 'window' => 'shipped_at']]),
        connectionConfig(['orders' => ['class' => 'data', 'window' => 'created_at', 'morph' => ['type' => 'a_type', 'id' => 'a_id']]]),
    );

    expect($merged->table('orders')->window)->toBe('shipped_at')
        ->and($merged->table('orders')->morph)->toBe(['type' => 'a_type', 'id' => 'a_id']);
});

it('adds a newly discovered table', function () {
    $merged = (new ConfigMerger)->merge(
        connectionConfig(['users' => ['class' => 'data']]),
        connectionConfig(['users' => ['class' => 'data'], 'invoices' => ['class' => 'data']]),
    );

    expect($merged->table('invoices'))->not->toBeNull();
});

it('retains a vanished table and marks it removed', function () {
    $merged = (new ConfigMerger)->merge(
        connectionConfig(['users' => ['class' => 'data'], 'legacy' => ['class' => 'data']]),
        connectionConfig(['users' => ['class' => 'data']]),
    );

    expect($merged->table('legacy')->removed)->toBeTrue()
        ->and($merged->table('users')->removed)->toBeFalse();
});
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `composer test:unit -- --filter='ConfigRoundTripTest|ConfigMergerTest'`
Expected: FAIL — classes not found.

- [ ] **Step 3: Implement**

`ConfigRenderer` hand-formats deterministic, Pint-clean PHP (no `var_export`). `ConfigLoader::load` does `require $path` on the file and passes the array to `ConnectionConfig::fromArray`; a file that does not return an array throws `RuntimeException` naming the file.

- [ ] **Step 4: Run tests to verify they pass**

Run: `composer test:unit -- --filter='ConfigRoundTripTest|ConfigMergerTest'`
Expected: PASS, 12 tests.

- [ ] **Step 5: Run the full gate and commit**

Run: `composer test`

```bash
git add src/Config tests
git commit -m "feat: render, load and merge table configs"
```

---

## Task 10: `dead-drop:init`

**Files:**
- Create: `src/Console/Commands/InitCommand.php`
- Modify: `src/DeadDropServiceProvider.php` (register the command)
- Modify: `tests/Pest.php` (add `initFixtureConfig(): string` helper — migrates nothing; runs `dead-drop:init --connection=dd_test --path=<tempDirectory()> --no-interaction` and returns the directory)
- Test: `tests/Feature/Commands/InitCommandTest.php`

**Interfaces:**
- Consumes: `Introspector`, `EdgeInferrer`, `TableClassifier`, `SensitiveColumnDetector`, `MorphPairDetector`, `ConfigRenderer`, `ConfigLoader`, `ConfigMerger`.
- Produces: signature
  `dead-drop:init {--connection=* : Connections to enroll} {--skip=* : Tables to force to skip} {--path= : Directory for the per-connection config files (defaults to config_path(config('dead-drop.config_path')))}`

**Discovered config per table:** `class` from the classifier (forced to `skip` when named in `--skip`); `columns` from the schema; `references` from every inferred edge whose `table` is this table (target connection null); `redact` from `SensitiveColumnDetector::detect()` plus `'review'` for every JSON column (from `needsReview()`, excluding morph type columns); `morph` from `MorphPairDetector::detect()`; `window` = `created_at` when the table has such a column, else null; `exclude` null. For skip-class tables init builds the discovered `TableConfig` with empty `references`/`redact` and null `window`/`exclude`/`morph`, so a fresh skip table renders as only `class` and `columns` (the renderer itself has no per-class special case, so anything a human typed on a skip table is preserved).

Interactive mode (only when `--connection` is empty **and** the input is interactive): `multiselect()` from Laravel Prompts over `array_keys(config('database.connections'))`, each labelled with its table count, then `multiselect()` over the ten largest tables by estimated rows to add to the skip list. With `--no-interaction` both prompts are skipped; `--connection` is then required and the command prints an error and returns `self::FAILURE` without it.

After writing, print one line per connection: path written, table counts per class, and the list from `EloquentSource::skipped()` (if any) under the heading "Skipped model methods without a relation return type".

- [ ] **Step 1: Write the failing test**

```php
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
```

The "preserves a human edit" test is the one that matters most — it is the regression guard for config rot.

- [ ] **Step 2: Run tests to verify they fail**

Run: `composer test:unit -- --filter=InitCommandTest`
Expected: FAIL — command `dead-drop:init` does not exist.

- [ ] **Step 3: Implement the command**

Composition only: introspect → infer edges → classify → detect sensitive → build `ConnectionConfig` → if a file exists, `ConfigLoader::load()` then `ConfigMerger::merge()` → `ConfigRenderer::render()` → write (creating the directory). Register the command in the provider's `boot()` inside the existing `runningInConsole()` guard via `$this->commands([...])`.

- [ ] **Step 4: Run tests to verify they pass**

Run: `composer test:unit -- --filter=InitCommandTest`
Expected: PASS, 6 tests.

- [ ] **Step 5: Run the full gate and commit**

Run: `composer test`

```bash
git add src tests
git commit -m "feat: add init command scaffolding table configs"
```

---

## Task 11: `dead-drop:check`

**Files:**
- Create: `src/Config/DriftReport.php`, `src/Config/DriftDetector.php`, `src/Console/Commands/CheckCommand.php`
- Modify: `src/DeadDropServiceProvider.php` (register the command)
- Test: `tests/Feature/Commands/CheckCommandTest.php`

**Interfaces:**
- Produces:
  - `DriftDetector::__construct(SensitiveColumnDetector $sensitive)`, `detect(DatabaseSchema $schema, ConnectionConfig $config): DriftReport`
  - `DriftReport(array $newTables, array $removedTables, array $newColumns, array $removedColumns, array $undecidedColumns)` with `hasDrift(): bool` and `toLines(): array` (`list<string>`, human-readable, one item per line, columns written `table.column`)
    - new tables: in schema, not in config
    - removed tables: in config (and not already marked `removed`), not in schema
    - new/removed columns: compared against `TableConfig->columns` for non-skip, non-removed tables
    - undecided columns: for non-skip, non-removed tables, every `redact` value equal to `'review'`, plus every column `SensitiveColumnDetector::detect()` flags that has no `redact` entry
  - Command `dead-drop:check {--connection=* : Connections to check (defaults to every config file in the directory)} {--path= : Config directory}`; prints the lines and exits `self::SUCCESS` (0) when clean, `self::FAILURE` (1) on drift. A connection with no config file is itself drift ("no config for connection X — run dead-drop:init").

- [ ] **Step 1: Write the failing test**

```php
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
```

The sensitive-column test is the fail-closed property, enforced in CI.

- [ ] **Step 2: Run tests to verify they fail**

Run: `composer test:unit -- --filter=CheckCommandTest`
Expected: FAIL — command does not exist.

- [ ] **Step 3: Implement**

`DriftDetector` is a pure comparison — no I/O. The command loads schema and config, runs the detector per connection, prints `toLines()`, and returns `hasDrift() ? self::FAILURE : self::SUCCESS`.

- [ ] **Step 4: Run tests to verify they pass**

Run: `composer test:unit -- --filter=CheckCommandTest`
Expected: PASS, 6 tests.

- [ ] **Step 5: Run the full gate and commit**

Run: `composer test`

```bash
git add src tests
git commit -m "feat: detect config drift with a CI-safe check command"
```

---

## Task 12: Graph and single-connection traversal

**Files:**
- Create: `src/Planning/Root.php`, `src/Planning/GraphEdge.php`, `src/Planning/Graph.php`, `src/Planning/KeySet.php`, `src/Planning/KeySetRepository.php`, `src/Planning/UnresolvedReference.php`, `src/Planning/TraversalResult.php`, `src/Planning/Traverser.php`
- Modify: `tests/Pest.php` (add `traverseFixture(string $rootSpec, ?string $configDirectory = null): TraversalResult` — runs init into a temp directory unless one is given, loads the `ConfigSet` and a `SchemaSet` for every connection in it, and calls `app(Traverser::class)->traverse(...)`; and `collectedKeys(TraversalResult $result, string $table, string $connection = 'dd_test'): array` returning the sorted list of keys in that key set, `[]` when there is none)
- Test: `tests/Feature/Planning/TraverserTest.php`

**Interfaces:**
- Produces:
  - `Root(string $connection, string $table, array $ids)` (`list<int|string>`), plus `static parse(string $spec): Root` for `"dd_test.companies:1,2"` — connection is everything before the first `.`, table between `.` and `:`, ids comma-separated after `:`, numeric strings cast to int; malformed specs throw `InvalidArgumentException`.
  - `GraphEdge(string $connection, string $table, string $column, string $targetConnection, string $targetTable, string $targetColumn, bool $descend)` — connection-resolved (a null `Reference->connection` becomes the owning connection).
  - `Graph::fromConfig(ConfigSet $config): Graph` with `inboundEdges(string $connection, string $table): array` and `outboundEdges(string $connection, string $table): array` (`list<GraphEdge>`), built only from tables whose class is not `skip` and that are not `removed`.
  - `KeySetRepository::__construct(DriverFactory $drivers)`, `create(string $connection, string $table, ColumnType $type): KeySet` (temp table named `dd_keys_{table}`; re-creating the same key set returns the existing one), `get(string $connection, string $table): ?KeySet`, `all(): array` (keyed `"{connection}.{table}"`), `dropAll(): void`.
  - `KeySet` with readonly `connection`, `table`, `type` (`ColumnType`), `tableName` (the temp table), and `add(array $keys): int` returning the count actually added (count after minus count before), `count(): int`, `chunk(int $size, callable $fn): void` (`$fn(list<int|string>)`), `query(): Builder` on the temp table.
  - `UnresolvedReference(string $connection, string $table, string $column, string $reason)`
  - `TraversalResult(array $keySets, array $unresolved)` with `keySets(): array` (keyed `"{connection}.{table}"`) and `unresolved(): array`
  - `Traverser::__construct(KeySetRepository $keys)`, `traverse(Root $root, ConfigSet $config, SchemaSet $schemas, ?DateTimeInterface $since = null): TraversalResult`. The caller owns `KeySetRepository::dropAll()` (the command's `finally`); tests leave temp tables to die with the in-memory connection.

**Edge eligibility:** an edge is usable only when its target table is in the config as `data` or `lookup`, is not `removed`, and its `targetColumn` is the target table's single-column primary key. Otherwise every non-null value of the edge column across the collected rows makes it an `UnresolvedReference` (one per edge, not per row) with reason `"target table {t} is skipped"`, `"target table {t} is not configured"`, or `"target column {t}.{c} is not the primary key"`. A root whose table is `skip` or not configured throws `InvalidArgumentException`. A `lookup` root is treated as `data` for the traversal.

**The algorithm, restated so the implementer does not have to re-derive it:**

1. Seed the root table's key set with the root ids (key type = the root table's primary-key `ColumnType`). Seed every `lookup` table's key set with all of its primary keys.
2. **Descend (BFS).** For each table in the frontier, find every *inbound* edge from a `data` table where `descend` is true and the edge is eligible. Select that table's primary keys where the edge column joins the frontier table's key table. Apply `window` (`>= $since`, only when the table has a window and `$since` is not null) and `exclude`. Add to the child's key set; enqueue the child whenever the key set actually grew — even if it was descended from before (joins run against the whole key set, so re-descending is idempotent and terminates because keys are finite; this is what makes diamonds and self-references complete). Lookup tables are never descended into, except a lookup *root*, which is seeded with its ids only and treated as data.
   `exclude` is a SQL boolean fragment naming rows to **drop**; the traverser applies it as `not coalesce((fragment), false)` so a NULL evaluation keeps the row and a top-level `or` cannot escape the predicate.
3. **Ascend (fixpoint).** For every key set, for every *outbound* edge that is eligible, select the distinct non-null values of the edge column from rows joined to the key table and add them to the target's key set (creating it if needed). Repeat the entire pass until no key set grows.
4. **Ascended rows are never descended into.** Phase 3 must not re-enter phase 2 — that is the rule that keeps the dump bounded.

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

use DeadDrop\DeadDrop\Planning\Root;
use DeadDrop\DeadDrop\Tests\Fixtures\SchemaBuilder;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    SchemaBuilder::migrate('dd_test');
    SchemaBuilder::seedTwoCompanies('dd_test');
});

it('parses a root spec', function () {
    $root = Root::parse('dd_test.companies:1,2');

    expect($root->connection)->toBe('dd_test')
        ->and($root->table)->toBe('companies')
        ->and($root->ids)->toBe([1, 2]);
});

it('descends from the root to direct children', function () {
    $result = traverseFixture('dd_test.companies:1');

    expect(collectedKeys($result, 'orders'))->toBe([1, 2]);
});

it('descends two hops', function () {
    $result = traverseFixture('dd_test.companies:1');

    expect(collectedKeys($result, 'order_items'))->toBe([1, 2]);
});

it('excludes rows belonging to another root', function () {
    $result = traverseFixture('dd_test.companies:1');

    expect(collectedKeys($result, 'orders'))->not->toContain(99);
});

it('ascends to pull a customer referenced by a collected order', function () {
    // customer 7 belongs to no company; order 1 references it. Customer 8 is only on order 99.
    $result = traverseFixture('dd_test.companies:1');

    expect(collectedKeys($result, 'customers'))->toBe([7]);
});

it('does not descend from an ascended row', function () {
    // user 50 belongs to company 2 but placed order 1 for company 1.
    // Ascend must pull user 50, but must NOT then pull company 2's other orders.
    $result = traverseFixture('dd_test.companies:1');

    expect(collectedKeys($result, 'users'))->toContain(50)
        ->and(collectedKeys($result, 'orders'))->not->toContain(99);
});

it('does not descend an edge marked ascend only', function () {
    // user 60 was created_by user 50 but belongs to company 2.
    $result = traverseFixture('dd_test.companies:1');

    expect(collectedKeys($result, 'users'))->toBe([10, 50]);
});

it('includes lookup tables whole', function () {
    $result = traverseFixture('dd_test.companies:1');

    expect($result->keySets()['dd_test.countries']->count())->toBe(DB::connection('dd_test')->table('countries')->count());
});

it('applies the window when a since date is given', function () {
    $result = traverseFixture('dd_test.companies:1', since: new DateTimeImmutable('2026-06-01'));

    expect(collectedKeys($result, 'orders'))->toBe([2]);
});

it('reports a reference into a skipped table as unresolved', function () {
    $result = traverseFixture('dd_test.companies:1');

    $reasons = array_map(fn ($u) => "{$u->table}.{$u->column}: {$u->reason}", $result->unresolved());

    expect($reasons)->toContain('users.failed_job_id: target table failed_jobs is skipped');
});

it('resolves every collected foreign key within the collected set', function () {
    $result = traverseFixture('dd_test.companies:1');

    foreach (collectedKeys($result, 'orders') as $orderId) {
        $order = DB::connection('dd_test')->table('orders')->find($orderId);

        expect(collectedKeys($result, 'companies'))->toContain($order->company_id)
            ->and(collectedKeys($result, 'users'))->toContain($order->user_id);

        if ($order->customer_id !== null) {
            expect(collectedKeys($result, 'customers'))->toContain($order->customer_id);
        }
    }
});
```

(`traverseFixture` therefore takes a third named argument `?DateTimeInterface $since = null`.) The last test is the referential-completeness guarantee — it is the single most valuable assertion in the package.

- [ ] **Step 2: Run tests to verify they fail**

Run: `composer test:unit -- --filter=TraverserTest`
Expected: FAIL — `Class "DeadDrop\DeadDrop\Planning\Root" not found`.

- [ ] **Step 3: Implement**

Descend selects against the parent key table rather than an `IN` list:

```php
$keys = $connection->table($child->name)
    ->join($parentKeySet->tableName, "{$child->name}.{$edge->column}", '=', "{$parentKeySet->tableName}.k")
    ->when($window !== null && $since !== null, fn ($q) => $q->where("{$child->name}.{$window}", '>=', $since))
    ->when($exclude !== null, fn ($q) => $q->whereRaw($exclude))
    ->distinct()
    ->pluck("{$child->name}.{$primaryKey}")
    ->all();

$added = $childKeySet->add($keys);
```

Ascend is the mirror, plucking distinct non-null edge-column values from the source table joined to its own key table. The outer loop repeats while any `add()` returns a non-zero count. Pluck in chunks (`->orderBy(pk)->chunk(5000, ...)`) so a single pass never holds more than one chunk of keys in PHP.

- [ ] **Step 4: Run tests to verify they pass**

Run: `composer test:unit -- --filter=TraverserTest`
Expected: PASS, 11 tests.

- [ ] **Step 5: Run the full gate and commit**

Run: `composer test`

```bash
git add src/Planning tests
git commit -m "feat: traverse the graph to referential closure"
```

---

## Task 13: Polymorphic edges

**Files:**
- Create: `src/Planning/MorphResolver.php`
- Modify: `src/Planning/Traverser.php`
- Test: `tests/Feature/Planning/MorphTraversalTest.php`

**Interfaces:**
- Consumes: `KeySetRepository`, `TableConfig->morph`, `UnresolvedReference`.
- Produces: `MorphResolver::tableFor(string $type): ?string` — resolves a morph type string to a table name via `Relation::morphMap()`, falling back to treating the string as a model class name and calling `getTable()`, and returning `null` when neither works.

A polymorphic column pair is declared in config (init fills it in from `MorphPairDetector`):

```php
'comments' => [
    'class' => 'data',
    'morph' => ['type' => 'commentable_type', 'id' => 'commentable_id'],
],
```

**Descend:** for a `data` table with a `morph` declaration, after the regular inbound edges of each frontier table, run a morph pass: `select distinct {type}` from the morph table; for each type string, `tableFor()` it; when it resolves to the frontier table (same connection), select the morph table's primary keys where `{type} = ?` and `{id}` joins the frontier key table. Unresolvable types produce one `UnresolvedReference` per type with reason `"unmapped morph type: {type}"` (deduplicated across passes).
**Ascend:** for each collected morph table, group the collected rows' `{type}` values, resolve each to a table, and add the distinct `{id}` values to that table's key set when the target is eligible (configured `data`/`lookup`, single PK). Unresolvable or skipped targets become `UnresolvedReference`s rather than exceptions — a dead morph type in old rows is common and must not fail the dump.

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

use DeadDrop\DeadDrop\Planning\MorphResolver;
use DeadDrop\DeadDrop\Tests\Fixtures\Models\Order;
use DeadDrop\DeadDrop\Tests\Fixtures\SchemaBuilder;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    SchemaBuilder::migrate('dd_test');
    SchemaBuilder::seedTwoCompanies('dd_test');

    Relation::morphMap(['order' => Order::class]);

    DB::connection('dd_test')->table('comments')->insert([
        ['id' => 1, 'commentable_type' => 'order', 'commentable_id' => 1, 'body' => 'kept'],
        ['id' => 2, 'commentable_type' => 'order', 'commentable_id' => 99, 'body' => 'other company'],
        ['id' => 3, 'commentable_type' => 'gone', 'commentable_id' => 1, 'body' => 'dead type'],
    ]);
});

afterEach(fn () => Relation::morphMap([], false));

it('resolves a morph type through the morph map', function () {
    expect((new MorphResolver)->tableFor('order'))->toBe('orders');
});

it('resolves a fully qualified model class name', function () {
    expect((new MorphResolver)->tableFor(Order::class))->toBe('orders');
});

it('returns null for an unmapped morph type', function () {
    expect((new MorphResolver)->tableFor('gone'))->toBeNull();
});

it('descends into comments attached to a collected order', function () {
    $result = traverseFixture('dd_test.companies:1');

    expect(collectedKeys($result, 'comments'))->toContain(1);
});

it('does not collect comments attached to another roots rows', function () {
    $result = traverseFixture('dd_test.companies:1');

    expect(collectedKeys($result, 'comments'))->not->toContain(2)
        ->and(collectedKeys($result, 'comments'))->not->toContain(3);
});

it('reports an unresolvable morph type instead of throwing', function () {
    $result = traverseFixture('dd_test.companies:1');

    $reasons = array_map(fn ($u) => $u->reason, $result->unresolved());

    expect($reasons)->toContain('unmapped morph type: gone');
});
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `composer test:unit -- --filter=MorphTraversalTest`
Expected: FAIL — `Class "DeadDrop\DeadDrop\Planning\MorphResolver" not found`.

- [ ] **Step 3: Implement**

```php
public function tableFor(string $type): ?string
{
    $class = Relation::morphMap()[$type] ?? $type;

    if (! class_exists($class) || ! is_subclass_of($class, Model::class)) {
        return null;
    }

    return (new $class)->getTable();
}
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `composer test:unit -- --filter=MorphTraversalTest`
Expected: PASS, 6 tests — and `composer test:unit -- --filter=TraverserTest` still 11/11.

- [ ] **Step 5: Run the full gate and commit**

Run: `composer test`

```bash
git add src/Planning tests
git commit -m "feat: traverse polymorphic edges"
```

---

## Task 14: Cross-connection key injection

**Files:**
- Create: `src/Planning/CircularConnectionException.php`
- Modify: `src/Planning/Traverser.php`, `src/Planning/KeySetRepository.php`, `src/Planning/Graph.php`
- Modify: `tests/TestCase.php` (add a second in-memory sqlite connection `dd_analytics`)
- Test: `tests/Feature/Planning/CrossConnectionTraversalTest.php`

**Interfaces:**
- Consumes: everything from Tasks 12 and 13.
- Produces:
  - `KeySetRepository::mirror(KeySet $source, string $targetConnection): KeySet` — bulk-inserts the source key set's keys into a temp table named `dd_mirror_{sourceConnection}_{table}` on another connection (re-mirroring refreshes it with any new keys).
  - `Graph::connectionOrder(): array` — `list<string>` of connections topologically sorted so that a connection appears after every connection its edges point *into* (an edge `dd_analytics.sessions.company_id → dd_test.companies` means `dd_test` precedes `dd_analytics`). A cycle throws `CircularConnectionException` naming the connections involved.

An edge whose target connection differs from the table's own connection cannot join. The traverser resolves it by mirroring the already-collected key set onto the connection that needs it and joining against the mirror — in both directions: descend (mirror the parent's keys onto the child's connection) and ascend (mirror is not needed; plucked values are added to the target's key set directly). Connections are processed in `connectionOrder()`: the root's connection first, then each dependent connection's descend phase; the ascend fixpoint runs across all connections until nothing grows.

- [ ] **Step 1: Write the failing test**

`tests/TestCase.php` gains a second connection `dd_analytics` (`sqlite`, `:memory:`). The test creates `analytics_sessions(id, company_id)` on it with rows `1 (company 1)`, `2 (company 1)`, `9 (company 2)`, runs init for both connections into one directory, and edits the analytics config so `analytics_sessions.company_id` reads `['dd_test.companies.id', 'source' => 'manual']` (init cannot infer a cross-connection edge; the human writes it).

```php
<?php

declare(strict_types=1);

use DeadDrop\DeadDrop\Config\ConfigLoader;
use DeadDrop\DeadDrop\Planning\CircularConnectionException;
use DeadDrop\DeadDrop\Planning\Graph;
use DeadDrop\DeadDrop\Tests\Fixtures\SchemaBuilder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

beforeEach(function () {
    SchemaBuilder::migrate('dd_test');
    SchemaBuilder::seedTwoCompanies('dd_test');

    Schema::connection('dd_analytics')->create('analytics_sessions', function ($t) {
        $t->id();
        $t->unsignedBigInteger('company_id');
    });
    DB::connection('dd_analytics')->table('analytics_sessions')->insert([
        ['id' => 1, 'company_id' => 1],
        ['id' => 2, 'company_id' => 1],
        ['id' => 9, 'company_id' => 2],
    ]);
});

function crossConnectionConfigDirectory(): string
{
    $path = tempDirectory();

    test()->artisan('dead-drop:init', ['--connection' => ['dd_test', 'dd_analytics'], '--path' => $path, '--no-interaction' => true]);

    $file = $path.'/dd_analytics.php';
    file_put_contents($file, str_replace(
        "'columns' => ['id', 'company_id'],",
        "'columns' => ['id', 'company_id'],\n        'references' => [\n            'company_id' => ['dd_test.companies.id', 'source' => 'manual'],\n        ],",
        file_get_contents($file),
    ));

    return $path;
}

it('collects rows on a second connection via a qualified edge', function () {
    $result = traverseFixture('dd_test.companies:1', crossConnectionConfigDirectory());

    expect(collectedKeys($result, 'analytics_sessions', 'dd_analytics'))->toBe([1, 2]);
});

it('orders connections so targets come before the connections that point at them', function () {
    $graph = Graph::fromConfig((new ConfigLoader)->loadAll(crossConnectionConfigDirectory()));

    expect($graph->connectionOrder())->toBe(['dd_test', 'dd_analytics']);
});

it('fails clearly when two connections reference each other', function () {
    $path = crossConnectionConfigDirectory();
    $file = $path.'/dd_test.php';
    file_put_contents($file, str_replace(
        "'company_id' => ['companies.id', 'source' => 'fk'],",
        "'company_id' => ['companies.id', 'source' => 'fk'],\n            'total' => ['dd_analytics.analytics_sessions.id', 'source' => 'manual'],",
        file_get_contents($file),
    ));

    Graph::fromConfig((new ConfigLoader)->loadAll($path))->connectionOrder();
})->throws(CircularConnectionException::class, 'dd_test');
```

Note the `analytics_sessions` table has no sensitive columns and no outbound edges, so init classifies it `lookup`; the human edit that adds the cross-connection reference must also flip its class to `data` — do that in `crossConnectionConfigDirectory()` with a second `str_replace` of `'class' => 'lookup'` → `'class' => 'data'` scoped to that file (the analytics file holds only that one table).

- [ ] **Step 2: Run tests to verify they fail**

Run: `composer test:unit -- --filter=CrossConnectionTraversalTest`
Expected: FAIL — `dd_analytics` connection / `mirror()` / `connectionOrder()` not defined.

- [ ] **Step 3: Implement**

```php
public function mirror(KeySet $source, string $targetConnection): KeySet
{
    $mirror = $this->create($targetConnection, "mirror_{$source->connection}_{$source->table}", $source->type);

    $source->chunk(5000, fn (array $keys) => $mirror->add($keys));

    return $mirror;
}
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `composer test:unit -- --filter='CrossConnectionTraversalTest|TraverserTest|MorphTraversalTest'`
Expected: PASS, 20 tests.

- [ ] **Step 5: Run the full gate and commit**

Run: `composer test`

```bash
git add src/Planning tests
git commit -m "feat: resolve cross-connection edges by mirroring key sets"
```

---

## Task 15: `dead-drop:dump --dry-run`

**Files:**
- Create: `src/Planning/PlanStep.php`, `src/Planning/ExtractionPlan.php`, `src/Planning/Planner.php`, `src/Planning/UnsupportedTableException.php`, `src/Console/Commands/DumpCommand.php`
- Modify: `src/DeadDropServiceProvider.php` (register the command)
- Test: `tests/Feature/Commands/DumpDryRunTest.php`

**Interfaces:**
- Produces:
  - `PlanStep(string $connection, string $table, ?string $keyTable, int $rows, int $estimatedBytes)` — `keyTable` null means "whole table" (lookup tables)
  - `ExtractionPlan(array $steps, array $unresolved)` with `totalRows(): int`, `estimatedBytes(): int`
  - `UnsupportedTableException` with `static compositePrimaryKey(string $connection, string $table)` (message `"{connection}.{table} has a composite primary key, which is not supported"`) and `static noPrimaryKey(...)` (message `"... has no primary key ..."`)
  - `Planner::__construct(Traverser $traverser, KeySetRepository $keys)`, `plan(Root $root, ConfigSet $config, SchemaSet $schemas, ?DateTimeInterface $since = null): ExtractionPlan` — first validates every configured non-skip, non-removed table has a single-column primary key (throwing `UnsupportedTableException`), runs the traverser, builds one step per key set with `count() > 0` (rows = count, estimatedBytes = `table.estimatedBytes * rows / max(table.estimatedRows, 1)`), then topologically sorts steps by edge dependency so referenced tables precede referencing tables (self-edges ignored; on a cycle, the remaining steps are appended in `connection.table` order). Steps of one connection stay grouped in `Graph::connectionOrder()`.
  - Command `dead-drop:dump {--root= : Root spec, e.g. mysql.companies:1,2} {--full : Dump every configured table whole} {--since= : Only rows on or after this date for windowed tables} {--connection=* : Limit to these connections} {--path= : Config directory} {--dry-run : Plan only, extract nothing}`

In this phase `--dry-run` is the **only** supported mode; without it the command prints "extraction is not implemented yet (phase 4)" and returns `self::FAILURE`. `--full` likewise prints "--full is not implemented yet" and fails. `--root` is required in dry-run mode. That keeps the command honest rather than half-working.

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

use DeadDrop\DeadDrop\Config\ConfigLoader;
use DeadDrop\DeadDrop\Planning\Planner;
use DeadDrop\DeadDrop\Planning\Root;
use DeadDrop\DeadDrop\Schema\Introspector;
use DeadDrop\DeadDrop\Schema\SchemaSet;
use DeadDrop\DeadDrop\Tests\Fixtures\SchemaBuilder;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    SchemaBuilder::migrate('dd_test');
    SchemaBuilder::seedTwoCompanies('dd_test');
});

it('reports row counts per table', function () {
    $path = initFixtureConfig();

    $this->artisan('dead-drop:dump', ['--root' => 'dd_test.companies:1', '--path' => $path, '--dry-run' => true])
        ->expectsOutputToContain('orders')
        ->expectsOutputToContain('order_items')
        ->expectsOutputToContain('Total rows')
        ->assertSuccessful();
});

it('orders parents before children', function () {
    $path = initFixtureConfig();
    $config = (new ConfigLoader)->loadAll($path);
    $schemas = new SchemaSet(['dd_test' => app(Introspector::class)->inspect('dd_test')]);

    $plan = app(Planner::class)->plan(Root::parse('dd_test.companies:1'), $config, $schemas);

    $tables = array_map(fn ($s) => $s->table, $plan->steps);

    expect(array_search('companies', $tables))->toBeLessThan(array_search('orders', $tables))
        ->and(array_search('orders', $tables))->toBeLessThan(array_search('order_items', $tables))
        ->and(array_search('users', $tables))->toBeLessThan(array_search('orders', $tables));
});

it('reports unresolved references', function () {
    $path = initFixtureConfig();

    $this->artisan('dead-drop:dump', ['--root' => 'dd_test.companies:1', '--path' => $path, '--dry-run' => true])
        ->expectsOutputToContain('Unresolved references')
        ->expectsOutputToContain('users.failed_job_id')
        ->assertSuccessful();
});

it('refuses a table with a composite primary key', function () {
    Schema::connection('dd_test')->create('tag_post', function ($t) {
        $t->unsignedBigInteger('tag_id');
        $t->unsignedBigInteger('post_id');
        $t->primary(['tag_id', 'post_id']);
    });
    $path = initFixtureConfig();

    $this->artisan('dead-drop:dump', ['--root' => 'dd_test.companies:1', '--path' => $path, '--dry-run' => true])
        ->expectsOutputToContain('composite primary key')
        ->assertFailed();
});

it('refuses to extract without dry-run in this phase', function () {
    $path = initFixtureConfig();

    $this->artisan('dead-drop:dump', ['--root' => 'dd_test.companies:1', '--path' => $path])
        ->expectsOutputToContain('not implemented')
        ->assertFailed();
});

it('writes no files and leaves no key tables behind', function () {
    Storage::fake('s3');
    $path = initFixtureConfig();

    $this->artisan('dead-drop:dump', ['--root' => 'dd_test.companies:1', '--path' => $path, '--dry-run' => true]);

    expect(Storage::disk('s3')->allFiles())->toBe([])
        ->and(collect(Schema::connection('dd_test')->getTables())->pluck('name')->filter(fn ($n) => str_starts_with($n, 'dd_keys_'))->all())->toBe([]);
});
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `composer test:unit -- --filter=DumpDryRunTest`
Expected: FAIL — command does not exist.

- [ ] **Step 3: Implement**

The command resolves the root, loads the `ConfigSet` from the directory, builds a `SchemaSet` by introspecting every connection in it (filtered by `--connection` when given), calls `Planner::plan()`, and renders a `table()` of connection / table / rows / estimated size, followed by `Total rows: N` and `Estimated size: X` lines and an `Unresolved references (N)` list (`connection.table.column — reason`). `KeySetRepository::dropAll()` runs in a `finally` so temp tables never leak. `UnsupportedTableException` and `CircularConnectionException` are caught and printed as errors with `self::FAILURE`.

- [ ] **Step 4: Run tests to verify they pass**

Run: `composer test:unit -- --filter=DumpDryRunTest`
Expected: PASS, 6 tests.

- [ ] **Step 5: Run the full gate and commit**

Run: `composer test` — all green, including `grep -rn "information_schema\|pg_class\|TEMPORARY" src --exclude-dir=Drivers` returning nothing.

```bash
git add src tests
git commit -m "feat: plan extraction and report it with --dry-run"
```

---

## Task 16: Documentation

**Files:**
- Modify: `README.md` (replace the placeholder description and the publish sections that no longer apply; document `dead-drop:init`, `dead-drop:check`, `dead-drop:dump --dry-run`, the config file shape with one annotated example, the `data`/`lookup`/`skip` classes, `descend`, `window`, `exclude`, `morph`, redaction placeholders `review`/`hash`/`mask`/`null`/`bcrypt:secret`/`fixed:...`, and the phase boundary: extraction is not implemented yet)
- Modify: `CHANGELOG.md` (an "Unreleased" entry listing the three commands and the planner)
- Modify: `resources/boost/skills/dead-drop-development/SKILL.md` — regenerate using the local `package-generate-skill` skill so it reflects the new commands and config

**Interfaces:** none. Documentation promises must match the implemented behaviour exactly (command names, option names, config keys).

- [ ] **Step 1: Update README and CHANGELOG** as described. Keep the badges and the Installation, Changelog, Contributing, Security, Credits and License sections. Remove the migrations/views/translations/assets publish sections (the package no longer ships them); keep the config publish section.
- [ ] **Step 2: Regenerate the Boost skill** via the `package-generate-skill` skill.
- [ ] **Step 3: Run the full gate and commit**

Run: `composer test`

```bash
git add README.md CHANGELOG.md resources/boost
git commit -m "docs: document init, check and dry-run planning"
```

---

## Definition of done

- `composer test` is green (PHPStan, Pint, type coverage, Pest).
- `dead-drop:init` scaffolds a reviewable config for any enrolled connection, and re-running it preserves human edits.
- `dead-drop:check` exits non-zero on a new table, a new column, an undecided sensitive column, or a leftover `review` placeholder.
- `dead-drop:dump --dry-run` produces a referentially-complete row plan against the fixture schema across two connections.
- `grep -rn "information_schema\|pg_class\|TEMPORARY" src --exclude-dir=Drivers` returns nothing.
- `grep -rn "App\\\\" src` returns nothing.
- README documents every command and config key that exists, and nothing that does not.

## Deferred to later phases

Redaction, artifact writing, native and PHP executors, loading, `doctor`/`install`/`scan`/`prune`, `--full`, queue sharding, running against a real production-sized MySQL schema (no host application exists in this repository; the workbench can be used for that once extraction exists). Task 15 deliberately refuses to extract so the boundary is explicit rather than half-built.

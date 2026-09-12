<div align="center">
    <h1>Dead Drop</h1>
</div>

<p align="center">
    <a href="https://packagist.org/packages/kirilldakhniuk/dead-drop"><img src="https://img.shields.io/packagist/v/kirilldakhniuk/dead-drop.svg?style=flat-square" alt="Packagist"></a>
    <a href="https://packagist.org/packages/kirilldakhniuk/dead-drop"><img src="https://img.shields.io/packagist/php-v/kirilldakhniuk/dead-drop.svg?style=flat-square" alt="PHP from Packagist"></a>
    <a href="https://packagist.org/packages/kirilldakhniuk/dead-drop"><img src="https://badge.laravel.cloud/badge/kirilldakhniuk/dead-drop?style=flat" alt="Laravel versions"></a>
    <a href="https://github.com/kirilldakhniuk/dead-drop/actions"><img alt="GitHub Workflow Status (main)" src="https://img.shields.io/github/actions/workflow/status/kirilldakhniuk/dead-drop/tests.yml?branch=main&label=Tests&style=flat-square"></a>
    <a href="https://packagist.org/packages/kirilldakhniuk/dead-drop"><img src="https://img.shields.io/packagist/dt/kirilldakhniuk/dead-drop.svg?style=flat-square" alt="Total Downloads"></a>
</p>

Dead Drop is a Laravel package that discovers your database schema, builds a reviewed per-connection config describing how each table should be classified, scoped and redacted, detects drift between that config and the live schema, dumps a redacted, referentially-complete slice of a root row — everything it points to or that points at it — to a portable artifact, and pulls that artifact into a local or staging database. Native executors (`mysqldump`, `mysqlsh`, `psql`), composite primary keys, `--full` (whole-database) dumps and schema creation on the target are not implemented yet. Requires PHP ^8.3 and Laravel 12 or 13.

## Installation

You can install the package via Composer:

```bash
composer require kirilldakhniuk/dead-drop
```

You may publish all of the package's resources at once:

```bash
php artisan vendor:publish --tag="dead-drop"
```

Or, you may publish the configuration file individually:

### Publishing the Configuration File

```bash
php artisan vendor:publish --tag="dead-drop-config"
```

This publishes `config/dead-drop.php`:

- `config_path` — the directory (relative to `config_path()`) holding one `<connection>.php` file per enrolled connection. Defaults to `dead-drop`, i.e. `config/dead-drop/<connection>.php`.
- `model_paths` — directories (relative to `base_path()`) scanned for Eloquent models when inferring relationships. Defaults to `['app/Models']`.
- `disk` (`DEAD_DROP_DISK`, default `s3`) — the disk `dead-drop:dump`, `dead-drop:dumps` and `dead-drop:pull` read and write artifacts on unless overridden with `--disk=`.
- `path` (`DEAD_DROP_PATH`, default `dead-drops`) — the base path on that disk under which each dump gets its own `<id>/` directory, unless overridden with `--path=`.
- `executor` (`DEAD_DROP_EXECUTOR`, default `php`) — which registered `Executor` moves rows during `dead-drop:dump`. See Executors below.
- `redaction.salt` (`DEAD_DROP_REDACTION_SALT`, no default) — must be at least 16 characters or `dead-drop:dump` refuses to run.
- `redaction.email_domain` (`DEAD_DROP_EMAIL_DOMAIN`, default `example.test`) — the domain used when `hash` redacts an email-shaped column.
- `binaries.psql` / `binaries.mysql` / `binaries.mysqlsh` (`DEAD_DROP_PSQL` / `DEAD_DROP_MYSQL` / `DEAD_DROP_MYSQLSH`) — reserved for native executors, which do not ship in this phase; they have no effect yet.
- `pull.allow_environments` (default `['local', 'staging']`) — the environments `dead-drop:pull` is allowed to run in; it refuses to run anywhere else.
- `pull.after` (default `[]`) — class-strings or Artisan command strings run after a successful `dead-drop:pull`. See Pulling below.

## Usage

### Enrolling a connection

`dead-drop:init` introspects one or more database connections, infers their relationships, classifies and scans their tables, and writes (or updates) a `<connection>.php` config file per connection:

```bash
php artisan dead-drop:init
```

Run without arguments in an interactive terminal, it prompts you to pick which of your `database.connections` to enroll and offers to skip the ten largest tables it found. A stock app ships connections nothing is configured for, so a connection the command cannot read — an unsupported driver, a host that is not up — is printed as `name (unavailable: <reason>)` and left out of the choices rather than failing the command. Non-interactively (for example in a script or CI), pass the connections and skips explicitly:

```bash
php artisan dead-drop:init --no-interaction --connection=mysql --connection=analytics --skip=audit_logs --path=config/dead-drop
```

- `--connection=` (repeatable) — connections to enroll. Required when the command is running non-interactively (e.g. `--no-interaction`, or in CI); otherwise you are prompted to select connections and, optionally, large tables to skip.
- `--skip=` (repeatable) — tables to force to the `skip` class, as a bare table name or `connection.table`.
- `--path=` — directory for the per-connection config files. Defaults to `config_path(config('dead-drop.config_path'))`.

The command refuses an unknown connection name, and fails if it cannot create or write to the config directory.

### The config file

Each `<connection>.php` file returns a plain array keyed by table name. Here is a real file `dead-drop:init` produced against a fixture schema (trimmed to a few tables):

```php
<?php

declare(strict_types=1);

return [
    'comments' => [
        'class' => 'data',
        'morph' => ['type' => 'commentable_type', 'id' => 'commentable_id'],
        'columns' => ['id', 'commentable_type', 'commentable_id', 'body'],
    ],
    'companies' => [
        'class' => 'data',
        'columns' => ['id', 'name', 'stripe_id'],
        'redact' => [
            'stripe_id' => 'fixed:redacted',
        ],
    ],
    'failed_jobs' => [
        'class' => 'skip',
        'columns' => ['id', 'payload'],
    ],
    'orders' => [
        'class' => 'data',
        'window' => 'created_at',
        'columns' => ['id', 'company_id', 'user_id', 'customer_id', 'total', 'created_at'],
        'references' => [
            'company_id' => ['companies.id', 'source' => 'fk'],
            'customer_id' => ['customers.id', 'source' => 'guessed'],
            'user_id' => ['users.id', 'source' => 'guessed'],
        ],
    ],
    'users' => [
        'class' => 'data',
        'columns' => ['id', 'company_id', 'email', 'password', 'created_by', 'failed_job_id'],
        'references' => [
            'company_id' => ['companies.id', 'source' => 'guessed'],
            'created_by' => ['users.id', 'descend' => false, 'source' => 'guessed'],
            'failed_job_id' => ['failed_jobs.id', 'source' => 'guessed'],
        ],
        'redact' => [
            'email' => 'hash',
            'password' => 'bcrypt:secret',
        ],
    ],
];
```

Every table entry can hold these keys, and nothing else:

- `class` — one of three values:
  - `data` — an ordinary table: scoped by the traversal, and redacted per its `redact` entries.
  - `lookup` — a small reference table (a known row estimate of at least one and fewer than 10,000, no outbound reference, no polymorphic pair, nothing sensitive) that is copied whole rather than scoped. Row counts are estimates and every engine reports an unknown one as zero, so a table whose size cannot be established is treated as `data`, not copied whole.
  - `skip` — never dumped. Framework bookkeeping tables (`migrations`, `jobs`, `job_batches`, `failed_jobs`, `cache`, `cache_locks`, `sessions`, `password_reset_tokens`, `password_resets`, `personal_access_tokens`, and anything prefixed `telescope_` or `pulse_`) are skipped automatically; anything else can be forced to `skip` with `--skip=` or by hand-editing the file.
- `removed` — present (and `true`) only when a table that used to be in this file has since disappeared from the schema. `dead-drop:init` never deletes an entry outright; it marks it `removed` so dropping it from the plan is a deliberate, reviewable edit.
- `window` — the name of a `created_at`-like column used to scope an incremental dump to rows on or after a `--since` date. `dead-drop:init` fills this in automatically when the table has a `created_at` column.
- `exclude` — a raw SQL boolean fragment naming rows to drop from a descending scope, e.g. `"status = 'test'"`. It is applied NULL-safe (a row whose fragment evaluates to `NULL` is kept, not dropped) and wrapped so it cannot escape the query. Never set by `init`; a human writes it.

`exclude` and `window` limit what is collected while descending from the root; they are not filters on the finished dump. A row an ascending pass needs to keep a collected row referentially complete is still pulled in, `exclude` and `window` notwithstanding — otherwise the dump would contain rows pointing at nothing. Use `skip` on the table, or a `redact` entry on the column, when data must be absent absolutely.
- `morph` — `['type' => '<type column>', 'id' => '<id column>']` for an Eloquent-style polymorphic pair (`commentable_type` / `commentable_id`), detected automatically from the `{prefix}_type` / `{prefix}_id` naming convention.
- `columns` — every column name, in schema order, as of the last `init`. This is what lets `dead-drop:check` detect a column that was added or removed since.
- `references` — keyed by the referencing column, one entry per outbound foreign-key-like edge. A reference can be written three ways:
  - `'table.column'` — shorthand for a same-connection target, always descended, `source: manual`.
  - `'connection.table.column'` — the same shorthand for a target on a different connection.
  - `['table.column', 'descend' => false, 'source' => 'fk']` — the full array form. `descend` (default `true`) says whether the traversal follows the edge downward to pull the target row in; `descend: false` means the edge is only followed upward (ascended) when some other collected row points at it — that is how a self-referencing column like `users.created_by` avoids pulling in every user who ever created another user. `source` records how the edge was found (see below) and is never used to decide behaviour — only to let a re-run upgrade a guess without discarding a human's `descend` or target edit.
- `redact` — keyed by column name, one transformer per sensitive column. Suggested automatically by name-based heuristics (see below); a human confirms, corrects or adds to these before the config is trusted.

`source` on a reference records how the edge was found: `fk` (a real foreign key constraint), `eloquent` (an Eloquent relationship method with a declared return type), `guessed` (a `{table}_id`/`{singular}_id`-shaped column with no declared relationship), or `manual` (written by a human). `dead-drop:init` never downgrades a `source` on merge — re-running it can only replace a guess with a firmer answer.

`redact` suggestions come from matching a column's name against a fixed set of patterns and use one of these placeholders:

- `hash` — a collision-safe one-way hash, the default suggestion for a sensitive-but-unique column: `email`, `email_address`, `*_email`, `ssn`, `tax_id`, `national_id`.
- `mask` — for `phone`, `mobile`, `telephone`, `fax`, `*_phone`.
- `null` — for `api_key`, `*_key`, anything containing `token` or `secret`, and `iban`, `account_number`, `routing_number`, `card_number`, `cvv`.
- `scramble` — for `date_of_birth`, `dob`, `birth_date`.
- `bcrypt:secret` — a deterministic placeholder for `password`/`password_hash`: the bcrypt hash of the literal string `secret`, computed at extraction time, so re-running `init` never churns the file.
- `fixed:redacted` — for a string-typed column with a `stripe_` prefix or a `_customer_id` suffix (e.g. `stripe_id`).
- `review` — not a redaction transformer at all, but a placeholder `dead-drop:init` writes for a JSON column of unknown shape that it cannot classify automatically. `dead-drop:check` treats a leftover `review` — or any sensitive column with no `redact` entry at all — as undecided and fails until a human replaces it with a real decision.

### Re-running `init`

`dead-drop:init` is safe to re-run against a changed schema: it merges the freshly discovered config into the existing file rather than overwriting it. A human's `class`, `redact` entries, `descend` flags and reference targets are always kept and never overwritten; `columns` (and an upgraded reference `source`) are refreshed from the schema, and `references` and `redact` entries discovered since the last run — a new foreign key, a newly added sensitive column — are added alongside the existing ones. A table that vanished from the schema is not deleted from the file — it is kept and marked `removed`, so dropping it from the plan stays a deliberate act. Re-running `init` with nothing changed produces an identical file: rendering is deterministic (tables, references and redactions are sorted, and keys appear in a fixed order).

### Checking for drift in CI

`dead-drop:check` compares each connection's live schema against its reviewed config and is safe to run non-interactively in CI — it makes no writes:

```bash
php artisan dead-drop:check
```

- `--connection=` (repeatable) — connections to check. Defaults to every config file found in the directory.
- `--path=` — config directory. Defaults to `config_path(config('dead-drop.config_path'))`.

It exits `0` when every checked connection is clean, and `1` when any checked connection has:

- a new table present in the schema but missing from the config,
- a table in the config that has vanished from the schema (and is not already marked `removed`),
- a new or removed column on a table still under review,
- a sensitive column the config has no `redact` entry for, or
- a `redact` entry still set to the `review` placeholder,

or when a named connection has no config file at all (it tells you to run `dead-drop:init --connection=<name>`). Running it with no `--connection=` against a directory holding no config at all is a failure too, not a pass — a CI job that never ran `init` should not go green.

### Dumping

`dead-drop:dump` plans a referentially-complete row set starting from one root row and — unless `--dry-run` — extracts it into a redacted artifact:

```bash
php artisan dead-drop:dump --root=mysql.companies:1
```

- `--root=` — the row to start from, as `connection.table:id[,id]` (a comma-separated list of ids is allowed).
- `--since=` — only take rows on or after this date for tables with a `window` column, for a narrower plan.
- `--connection=` (repeatable) — limit the plan to these connections.
- `--path=` — config directory. Defaults to `config_path(config('dead-drop.config_path'))`.
- `--disk=` — disk to write the artifact to. Defaults to `dead-drop.disk` (`DEAD_DROP_DISK`, default `s3`).
- `--dry-run` — plan only; extract nothing.

`--full` is not implemented yet; passing it fails with `--full is not implemented yet` rather than doing something partial.

#### Planning

Both a dry run and a real dump start by planning: the traversal holds its key sets in temporary tables, which only exist on the session that created them, so `dead-drop:dump` pins its reads to the write connection for the duration of the run (on MySQL this also means the connection needs the `CREATE TEMPORARY TABLES` privilege, and on any driver a transaction-pooled connection, e.g. PgBouncer in transaction mode, cannot be used — the temporary tables would not survive between statements).

The plan is printed as a table of connection, table, row count and estimated size, followed by the total row count, total estimated size, and any unresolved references. In plain words, the traversal:

- **descends** from the root: for every row it holds, it follows every `references` edge that has `descend: true` (the default) down to the child rows that point at it — breadth-first, re-visiting a table whenever it grows, so a self-reference or a second inbound edge still gets its own children collected. A `window` and `--since` narrow which child rows a descending pass takes; an `exclude` fragment removes rows the config names, NULL-safe. Polymorphic children are found by reading a table's distinct `*_type` values and resolving each one to a table via Laravel's morph map (or the class name itself);
- **ascends** afterward: every collected row's own outbound references (`descend: false` included) are followed upward to pull in the row it points at, repeated to a fixed point so a newly-ascended row's own parents are pulled in too. This is what keeps the dump referentially complete — a row is never left pointing at nothing;
- **lookup**-class tables are copied whole up front and never descended into;
- a reference an ascending pass cannot follow — because its target table is not configured, is `skip`ped, has no single-column primary key, or (for a declared edge) the target column is not that table's primary key — is recorded as an **unresolved reference** with a reason, but only once some collected row actually carries a non-null value in that column; an edge nothing ever points along is never reported, and never fails the plan. A polymorphic column whose type value resolves to nothing (an old value no model answers to any more) or names a table not configured for the dump is recorded the same way;
- a table with a composite primary key, or no primary key at all, cannot be addressed by this scheme and makes the whole plan fail with a clear error before anything is traversed.

`--dry-run` stops here, after checking that every id in `--root` exists (an id that does not is reported as `Root id {id} does not exist in {connection}.{table}` and fails the command).

#### Extraction

Without `--dry-run`, the plan is put through the extraction gate before a single row moves — the same fail-closed check described under Redaction below. Only once every category passes does `dead-drop:dump`:

1. select an executor through `ExecutorManager::driver()` — `config('dead-drop.executor')` (`DEAD_DROP_EXECUTOR`, default `php`) names it; an unknown name fails with `Unsupported DeadDrop executor [{name}].`;
2. write `manifest.json` with `status: "writing"`;
3. export every plan step in order, printing one progress line per table (`  mysql.companies … 3 rows`) as it goes — each row is redacted before it is written, and the whole read for a step happens through the write connection, for the same reason planning does;
4. flip the manifest's `status` to `"complete"`;
5. print the plan table, totals and unresolved references (as above), followed by `Artifact: {disk}:{path}/{id}`.

A `QueryException` during extraction — a hand-written `exclude` fragment, or a `window` column that turns out not to be one — is reported as `Extraction failed: {message}` and exits 1, leaving the manifest at `status: "writing"`; `dead-drop:dumps` (below) flags it and `dead-drop:pull` refuses to load it.

**Phase boundary:** native executors, composite primary keys and `--full` (dumping every configured table whole) are not implemented in this phase.

### Redaction

`dead-drop:dump` builds one `Transformer` per `redact` entry from `RedactionContext` (the salt and email domain from config, above) and applies it to every collected row before it is written. `null` values always stay `null`. From the `redact` value:

| `redact` value | result |
|---|---|
| `hash` | lowercase hex SHA-256 of `salt . value`, truncated to the column's declared length when known; on a column whose name matches `email`, `email_address` or `*_email`, the result is `{first 16 hex chars}@{email_domain}` instead |
| `mask` | the last 4 characters kept, every character before them replaced with `*`; a value of 4 characters or fewer becomes `****` |
| `null` | `null` |
| `scramble` | the date or datetime value shifted by a deterministic offset in `[-180, +180]` days derived from `salt` and the row's primary key |
| `bcrypt:<value>` | one `Hash::make(value)` computed once per dump and reused for every row it applies to |
| `fixed:<value>` | the literal `<value>` (an empty string is allowed) |
| `keep` | the value, unchanged — records a human decision that the flagged column is fine as-is |
| `review` | never reaches a transformer — refused at the gate (see below) |

Rules enforced before any row moves — the extraction gate — cover:

1. schema drift on any connection in the plan (the same checks `dead-drop:check` makes: new/removed tables or columns, undecided sensitive columns, a leftover `review` placeholder);
2. `redaction.salt` missing or shorter than 16 characters (`redaction.salt must be set to at least 16 characters (DEAD_DROP_REDACTION_SALT)`);
3. invalid redaction placements — `null` on a `NOT NULL` column, `scramble` on anything but a date/datetime column, `hash`/`mask` on anything but a string column, any entry on a primary key or a reference column, or an unknown transformer name — each reported against the column it names;
4. root ids that do not exist in the root table.

There is no bypass flag: every category runs and every violation is collected, so `dead-drop:dump` prints everything wrong in one pass. Primary key columns and reference (foreign-key-like) columns can never carry a `redact` entry at all.

Non-UTF-8 text in a string column is substituted with the Unicode replacement character (U+FFFD) when the row is JSON-encoded into the artifact, so a dump of latin1-style text is lossy. Binary columns are wrapped as `{"__base64": "<payload>"}` and round-trip exactly.

`exclude` and `window` only ever narrow what a *descending* pass collects — they never filter a finished dump or an ascended row (see the config file section above); use `skip` on the table, or a `redact` entry on the column, when data must be absent absolutely.

### Artifacts

A dump is written to `{disk}:{path}/{id}/`, where `{id}` is `Ymd-His-<6 random lowercase alphanumeric characters>` (UTC) — one `manifest.json` plus one gzip-compressed NDJSON file per table that has rows (`{connection}.{table}.ndjson.gz`, one JSON object per line, keys in the manifest's column order).

`manifest.json` fields:

- `version` — the manifest format version (currently `1`).
- `id`, `status` (`writing` or `complete`), `created_at`, `package_version`, `root`, `since`, `executor`.
- `connections` — every connection in the dump, keyed by name, each with its `driver`.
- `tables` — in plan-step (and load) order, each with `connection`, `table`, `file`, `format`, `rows`, `bytes`, `primary_key`, `columns` (`name` and `ColumnType` backing value, in schema order) and `redacted` (the columns that were transformed).
- `unresolved` — the same unresolved-reference entries the plan prints (`connection`, `table`, `column`, `reason`).

List what is on a disk with `dead-drop:dumps`:

```bash
php artisan dead-drop:dumps
```

- `--disk=` — defaults to `dead-drop.disk`.
- `--path=` — defaults to `dead-drop.path`.

It prints id, created, root, status, table count, total rows and total size, newest first, and prints `No artifacts on {disk}:{path}.` when there are none. A manifest it cannot read is listed with a status of `unreadable` rather than stopping the rest.

### Pulling

`dead-drop:pull` loads a dump artifact into a target connection, replacing the tables the artifact names and leaving every other table untouched:

```bash
php artisan dead-drop:pull [id] --connection=target
```

- `id` (optional argument) — the artifact to load. Defaults to the newest artifact with `status: "complete"`.
- `--connection=` — the target connection. Defaults to `database.default`.
- `--disk=` / `--path=` — where to read the artifact from. Default to `dead-drop.disk` / `dead-drop.path`.
- `--force` — skip the confirmation prompt.

It refuses to run unless `app()->environment()` matches one of `pull.allow_environments` (default `['local', 'staging']`) — everywhere else it prints `dead-drop:pull refuses to run in the [{environment}] environment; allowed: {comma-separated list, or "none"}` and exits 1. This is checked before the disk is even touched, because a pull is a destructive write and the one place it must never happen is the database the artifact came from.

It also refuses when no complete artifact is found (`No complete artifact found on {disk}:{path}.`), when a named artifact is not `status: "complete"` (`Artifact [{id}] is incomplete (status: {status}) and cannot be loaded.`), when the target connection is not one of `database.connections` (`Unknown database connection [{connection}].`), when a target table is missing a column the artifact carries (`Target table [{table}] is missing columns: {list}`), and when the artifact holds the same bare table name from two different connections (`Artifact holds table [{table}] from more than one connection; a single target cannot hold both.`) — a single target database cannot hold both, so the pull is refused intact rather than picking one. A table the artifact names but the target does not have is skipped and named in the summary, not refused.

Unless `--force` (or running non-interactively), it asks for confirmation before writing anything: `Replace {n} tables on connection [{target}] with artifact [{id}]?`. Once confirmed, it replaces one table at a time inside its own transaction: delete every row, insert the artifact's rows, and compare the count against the manifest, rolling that table back on a mismatch. Referential-integrity checks are turned off for the whole run (MySQL `SET FOREIGN_KEY_CHECKS = 0`, Postgres `SET session_replication_role = replica` — which needs superuser or an equivalent role on many managed hosts, e.g. Amazon RDS's `rds_superuser` — SQLite `PRAGMA foreign_keys = OFF`) because a slice arrives in plan order, not an order any one database would accept row by row, and are always restored afterward. Tables the artifact does not name are never touched, so this is a targeted replace, not a restore.

Once every table is replaced, the summary (`Loaded {rows} rows into {n} tables from artifact [{id}].`, plus one line per skipped table) is printed *before* `pull.after` runs, so a hook failure never hides that the load already happened. Each `pull.after` entry (default `[]`) is either a class-string — resolved from the container and invoked with the run's `PullReport` — or an Artisan command name run with `Artisan::call()`; the first one that throws, exits non-zero, or fails to resolve stops the rest and fails the command with the artifact already loaded.

### Executors

Which `Executor` moves rows during `dead-drop:dump` is chosen by `ExecutorManager`, a Laravel-manager-style singleton: `config('dead-drop.executor')` (`DEAD_DROP_EXECUTOR`, default `php`) names the driver, and only the bundled `php` executor ships in this phase — it reads every driver DeadDrop supports (`mysql`, `mariadb`, `pgsql`, `sqlite`) a chunk at a time through the query builder.

An application can register another executor from any service provider:

```php
use DeadDrop\DeadDrop\Extraction\ExecutorManager;

public function boot(ExecutorManager $executors): void
{
    $executors->extend('mysqlsh', fn () => new MySqlShellExecutor);
}
```

then select it with `DEAD_DROP_EXECUTOR=mysqlsh`. Native executors (`mysqldump`, `mysqlsh`, `psql`) are not implemented in this phase — the `binaries` config keys exist for them but currently do nothing.

## Changelog

Please see [CHANGELOG](CHANGELOG.md) for more information on what has changed recently.

## Contributing

Thank you for considering contributing to Dead Drop! Please review our [contributing guide](.github/CONTRIBUTING.md) to get started.

## Security Vulnerabilities

Please review [our security policy](.github/SECURITY.md) on how to report security vulnerabilities.

## Credits

- [Kirill D.](https://github.com/kirilldakhniuk)
- [All Contributors](../../contributors)

## License

Dead Drop is open-sourced software licensed under the [MIT license](LICENSE.md).

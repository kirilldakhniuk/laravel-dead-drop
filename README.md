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

Dead Drop is a Laravel package that discovers your database schema, builds a reviewed per-connection config describing how each table should be classified, scoped and redacted, detects drift between that config and the live schema, and plans a referentially-complete row extraction — a root row and everything it points to or that points at it. Extraction, redaction and loading are not implemented yet; this phase only discovers, reviews and plans. Requires PHP ^8.3 and Laravel 12 or 13.

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

This publishes `config/dead-drop.php`. Only two keys are used by the commands documented below:

- `config_path` — the directory (relative to `config_path()`) holding one `<connection>.php` file per enrolled connection. Defaults to `dead-drop`, i.e. `config/dead-drop/<connection>.php`.
- `model_paths` — directories (relative to `base_path()`) scanned for Eloquent models when inferring relationships. Defaults to `['app/Models']`.

The remaining keys (`disk`, `path`, `redaction`, `binaries`, `pull`) are reserved for later phases — extraction, redaction and pulling artifacts — and have no effect on `dead-drop:init`, `dead-drop:check` or `dead-drop:dump` today.

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

### Planning a dump

`dead-drop:dump --dry-run` plans, but does not extract, a referentially-complete row set starting from one root row:

```bash
php artisan dead-drop:dump --root=mysql.companies:1 --dry-run
```

- `--root=` — the row to start from, as `connection.table:id[,id]` (a comma-separated list of ids is allowed).
- `--dry-run` — required in this phase; the command refuses to run without it (extraction is not implemented yet).
- `--since=` — only take rows on or after this date for tables with a `window` column, for an incremental plan.
- `--connection=` (repeatable) — limit the plan to these connections.
- `--path=` — config directory. Defaults to `config_path(config('dead-drop.config_path'))`.

`--full` is not implemented yet; passing it fails with an explicit error rather than doing something partial.

Planning reads through the write connection: the traversal holds its key sets in temporary tables, which only exist on the session that created them, so on a read/write-split connection DeadDrop pins reads to the primary for the duration of the run.

The plan is printed as a table of connection, table, row count and estimated size, followed by the total row count, total estimated size, and any unresolved references. In plain words, the traversal:

- **descends** from the root: for every row it holds, it follows every `references` edge that has `descend: true` (the default) down to the child rows that point at it — breadth-first, re-visiting a table whenever it grows, so a self-reference or a second inbound edge still gets its own children collected. A `window` and `--since` narrow which child rows a descending pass takes; an `exclude` fragment removes rows the config names, NULL-safe. Polymorphic children are found by reading a table's distinct `*_type` values and resolving each one to a table via Laravel's morph map (or the class name itself);
- **ascends** afterward: every collected row's own outbound references (`descend: false` included) are followed upward to pull in the row it points at, repeated to a fixed point so a newly-ascended row's own parents are pulled in too. This is what keeps the dump referentially complete — a row is never left pointing at nothing;
- **lookup**-class tables are copied whole up front and never descended into;
- a reference an ascending pass cannot follow — because its target table is not configured, is `skip`ped, has no single-column primary key, or (for a declared edge) the target column is not that table's primary key — is recorded as an **unresolved reference** with a reason, but only once some collected row actually carries a non-null value in that column; an edge nothing ever points along is never reported, and never fails the plan. A polymorphic column whose type value resolves to nothing (an old value no model answers to any more) or names a table not configured for the dump is recorded the same way;
- a table with a composite primary key, or no primary key at all, cannot be addressed by this scheme and makes the whole plan fail with a clear error before anything is traversed.

**Phase boundary:** this command only plans. Actually extracting rows, `--full` (dumping every configured table whole), executing redaction, and loading a dump into another database are not implemented in this phase.

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

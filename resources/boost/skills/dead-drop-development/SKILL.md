---
name: dead-drop-development
description: >
  Enroll database connections, review the generated config, and dump, list
  and pull redacted, referentially-complete row extractions with the Dead
  Drop package.
license: MIT
metadata:
  author: Kirill D.
---

# Dead Drop

Use this skill when a Laravel application needs to adopt the Dead Drop package: enrolling a database connection, reviewing the config it generates, gating CI on drift, dumping a redacted slice of a row and everything it depends on, listing artifacts, or pulling an artifact into a local or staging database.

Dead Drop discovers a connection's schema, writes a reviewed `<connection>.php` config describing how each table should be classified, scoped and redacted, detects drift between that config and the live schema, dumps a referentially-complete, redacted artifact starting from one root row — or the whole database with `--all` — and pulls that artifact into a target connection. Native executors (`mysqldump`, `mysqlsh`, `psql`), composite primary keys, and schema creation on the target are not implemented — do not tell a consumer they can use them.

## Primary Goal

- Enroll a connection with `dead-drop:init`, keep it honest with `dead-drop:check`, dump a redacted slice with `dead-drop:dump`, see what is on a disk with `dead-drop:dumps`, and load one into a target database with `dead-drop:pull` — using only the commands and config keys the package actually implements.

## Workflow

### 1. Publish the config

```bash
php artisan vendor:publish --tag="dead-drop-config"
```

This publishes `config/dead-drop.php`:

- `config_path` (default `dead-drop`) — directory under `config_path()` holding one `<connection>.php` file per enrolled connection.
- `model_paths` (default `['app/Models']`) — directories under `base_path()` scanned for Eloquent models when inferring relationships.
- `disk` (`DEAD_DROP_DISK`, default `s3`) / `path` (`DEAD_DROP_PATH`, default `dead-drops`) — where `dead-drop:dump`, `dead-drop:dumps` and `dead-drop:pull` read and write artifacts by default.
- `executor` (`DEAD_DROP_EXECUTOR`, default `php`) — which registered executor moves rows during `dead-drop:dump`.
- `redaction.salt` (`DEAD_DROP_REDACTION_SALT`, required, at least 16 characters) and `redaction.email_domain` (`DEAD_DROP_EMAIL_DOMAIN`, default `example.test`).
- `pull.allow_environments` (default `['local', 'staging']`) and `pull.after` (default `[]`, class-strings or Artisan command names run after a successful pull).
- `binaries.psql` / `binaries.mysql` / `binaries.mysqlsh` — reserved for native executors, which do not ship yet; they do nothing today.

### 2. Enroll a connection

```bash
php artisan dead-drop:init --no-interaction --connection=<name> [--connection=<name> ...] [--skip=<table>] [--path=<dir>]
```

Run with no `--connection` in an interactive terminal to be prompted for which `database.connections` to enroll and which large tables to skip. `--connection` is required when running non-interactively. This writes (or, on a re-run, safely merges into) `config/dead-drop/<connection>.php` — a human's `class`, `redact`, `descend` flags and reference targets are preserved; a table that vanished from the schema is marked `removed`, never silently deleted.

### 3. Review the generated config

Open the written `<connection>.php` and check, per table:

- `class`: `data` (scoped and redacted), `lookup` (small reference table, copied whole), or `skip` (never dumped).
- `redact`: confirm or correct the suggested transformer (`hash`, `mask`, `null`, `scramble`, `bcrypt:secret`, `fixed:redacted`) for every sensitive column, and replace any `review` placeholder — `dead-drop:check` and the `dead-drop:dump` gate both fail while one is left. `hash`/`mask` only work on string columns and `scramble` only on date/datetime columns; a primary key or reference column can never carry a `redact` entry.
- `references`: each entry points at `table.column` (or `connection.table.column` for a cross-connection target) with a `descend` flag (`false` means the edge is only followed upward, never down — use it for self-references and audit columns like `created_by`) and a `source` (`fk`, `eloquent`, `guessed`, `manual`) that records how confidently the edge was found.
- `window` / `exclude`: `window` names a `created_at`-like column for `--since` scoping; `exclude` is a hand-written SQL boolean fragment naming rows to drop from a descending scope. Both only narrow what a *descending* pass collects — an ascended row is never filtered out by either.

### 4. Gate CI on drift

```bash
php artisan dead-drop:check [--connection=<name> ...] [--path=<dir>]
```

Non-interactive, makes no writes, and exits non-zero when the schema and config disagree: a new or removed table, a new or removed column on a reviewed table, a sensitive column with no `redact` entry, or a leftover `review` placeholder. Wire it into CI right after running migrations.

### 5. Dump a redacted, referentially-complete slice

```bash
php artisan dead-drop:dump [<table>] [<id> ...] [--all] [--connection=<name>] [--since=<date>] [--dry-run] [--disk=<disk>] [--path=<dir>]
```

The root is an argument, not a spec: `dead-drop:dump companies 1 2`, or `dead-drop:dump companies 1,2`. `--connection=` names the connection the root table lives on — every configured connection is still loaded, because cross-connection references need them — and is inferred when only one connection is configured or the default connection has a config (`Using connection [mysql].`). Anything left out is prompted for in an interactive terminal (connection, table ordered by inbound references, ids, then plan-or-dump); non-interactively nothing is prompted and the missing argument is named instead. A table that is not a `data` or `lookup` table in the config is refused.

`--all` dumps the whole database instead of a slice: every `data` and `lookup` table with rows, whole, on the named connection (or on every configured one when nothing names or infers a single connection). It takes no table or ids — `--all cannot be combined with a table or ids.` — nothing is traversed, so there are never unresolved references, and `--since` narrows only the tables that declare a `window`. The gate, the redaction and the composite-primary-key refusal all apply exactly as they do to a root dump. In an interactive terminal the same thing is offered as the first table choice, `Whole database (every data and lookup table)`.

`--dry-run` plans without extracting: it prints the row count and estimated size per table plus any unresolved references, and still validates that every root id exists. Without `--dry-run`, the plan is put through a fail-closed gate (schema drift, a missing or short `redaction.salt`, invalid `redact` placements, nonexistent root ids) before a single row moves; on success it writes a `manifest.json` (`status: "writing"`), exports every table through the configured `Executor` with each row redacted, flips the manifest to `status: "complete"`, and prints `Artifact: {disk}:{path}/{id}`.

Because the traversal's key sets are temporary tables scoped to one database session, `dead-drop:dump` pins its reads to the write connection: on MySQL the connection needs `CREATE TEMPORARY TABLES`, and a transaction-pooled connection (e.g. PgBouncer in transaction mode) cannot be used.

### 6. List and pull artifacts

```bash
php artisan dead-drop:dumps [--disk=<disk>] [--path=<dir>]
```

Lists id, created, root, status, table count, rows and size, newest first.

```bash
php artisan dead-drop:pull [id] [--connection=<target>] [--disk=<disk>] [--path=<dir>] [--force]
```

Refuses outside `pull.allow_environments` (checked before the disk is touched), on a missing or incomplete (`status !== "complete"`) artifact, on an unknown target connection, when a target table is missing a column the artifact carries, or when the artifact holds the same bare table name from two connections. A table the artifact names but the target lacks is skipped and named, not refused. Replaces each named table inside its own transaction (delete, insert, verify the row count) with foreign-key checks off for the run (Postgres needs superuser or an equivalent role for `SET session_replication_role`); everything else on the target is untouched. Asks for confirmation unless `--force` or non-interactive, prints the summary, then runs `pull.after` hooks.

## Rules, References, and Templates

Read before executing:

- `README.md` — full command reference, the config file shape, redaction semantics and the artifact/manifest layout
- `config/dead-drop.php` — every config key and its default
- `src/Console/Commands/{Init,Check,Dump,Dumps,Pull}Command.php` — exact signatures and behavior
- `src/Extraction/ExecutorManager.php` — how to register a custom executor with `extend()`

## Examples

- A team enabling Dead Drop on a fresh app: publish the config, set `DEAD_DROP_REDACTION_SALT`, run `dead-drop:init --connection=mysql`, review the generated `config/dead-drop/mysql.php` (confirm `redact` entries, fix any `review` placeholders), then add `php artisan dead-drop:check` as a CI step after migrations.
- Producing a support-ticket slice: `php artisan dead-drop:dump companies 482 --connection=mysql --disk=local` to write a redacted artifact, then `php artisan dead-drop:pull --connection=staging` to load it into a staging database.
- Refreshing a whole staging database from the last six months: `php artisan dead-drop:dump --all --since=2026-01-01 --connection=mysql`, then `php artisan dead-drop:pull --connection=staging`.
- Registering a custom executor from a service provider: inject `DeadDrop\DeadDrop\Extraction\ExecutorManager` and call `$executors->extend('mysqlsh', fn () => new MySqlShellExecutor)`, then select it with `DEAD_DROP_EXECUTOR=mysqlsh`.

## Anti-patterns

- Do not document native executors (`mysqldump`, `mysqlsh`, `psql`), composite primary keys, or schema creation on the target as available — none of these are implemented.
- Do not tell a consumer to configure `binaries.*` for working behavior; it is reserved for native executors and currently does nothing.
- Do not hand-write a `<connection>.php` config from scratch; always generate it with `dead-drop:init` and then review it.
- Do not run `dead-drop:pull` against a connection outside `pull.allow_environments`, and never treat it as anything but a full replace of the tables the artifact names.

---
name: dead-drop-development
description: >
  Enroll database connections, review the generated config, and plan
  referentially-complete row extractions with the Dead Drop package.
license: MIT
metadata:
  author: Kirill D.
---

# Dead Drop

Use this skill when a Laravel application needs to adopt the Dead Drop package: enrolling a database connection, reviewing the config it generates, gating CI on drift, or planning a dump of a row and everything it depends on.

Dead Drop discovers a connection's schema, writes a reviewed `<connection>.php` config describing how each table should be classified, scoped and redacted, detects drift between that config and the live schema, and plans (but does not yet execute) a referentially-complete extraction. Extraction, `--full`, redaction execution, and loading are not implemented in this phase — do not tell a consumer they can run them.

## Primary Goal

- Enroll a connection, get a reviewable config out of `dead-drop:init`, keep it honest with `dead-drop:check`, and plan a dump with `dead-drop:dump --dry-run` — using only the commands and config keys the package actually implements.

## Workflow

### 1. Publish the config

```bash
php artisan vendor:publish --tag="dead-drop-config"
```

This publishes `config/dead-drop.php`. Only two keys matter right now:

- `config_path` (default `dead-drop`) — directory under `config_path()` holding one `<connection>.php` file per enrolled connection.
- `model_paths` (default `['app/Models']`) — directories under `base_path()` scanned for Eloquent models when inferring relationships.

The other keys in the published file (`disk`, `path`, `redaction`, `binaries`, `pull`) are reserved for a later phase and do nothing yet — do not tell a consumer to configure them for a working feature.

### 2. Enroll a connection

```bash
php artisan dead-drop:init --no-interaction --connection=<name> [--connection=<name> ...] [--skip=<table>] [--path=<dir>]
```

Run with no `--connection` in an interactive terminal to be prompted for which `database.connections` to enroll and which large tables to skip. `--connection` is required when running non-interactively. This writes (or, on a re-run, safely merges into) `config/dead-drop/<connection>.php` — a human's `class`, `redact`, `descend` flags and reference targets are preserved; a table that vanished from the schema is marked `removed`, never silently deleted.

### 3. Review the generated config

Open the written `<connection>.php` and check, per table:

- `class`: `data` (scoped and redacted), `lookup` (small reference table, copied whole), or `skip` (never dumped).
- `redact`: confirm or correct the suggested transformer (`hash`, `mask`, `null`, `scramble`, `bcrypt:secret`, `fixed:redacted`) for every sensitive column, and replace any `review` placeholder — `dead-drop:check` fails while one is left.
- `references`: each entry points at `table.column` (or `connection.table.column` for a cross-connection target) with a `descend` flag (`false` means the edge is only followed upward, never down — use it for self-references and audit columns like `created_by`) and a `source` (`fk`, `eloquent`, `guessed`, `manual`) that records how confidently the edge was found.
- `window` / `exclude`: `window` names a `created_at`-like column for `--since` scoping; `exclude` is a hand-written SQL boolean fragment naming rows to drop.

### 4. Gate CI on drift

```bash
php artisan dead-drop:check [--connection=<name> ...] [--path=<dir>]
```

Non-interactive, makes no writes, and exits non-zero when the schema and config disagree: a new or removed table, a new or removed column on a reviewed table, a sensitive column with no `redact` entry, or a leftover `review` placeholder. Wire it into CI right after running migrations.

### 5. Plan a dump

```bash
php artisan dead-drop:dump --root=<connection>.<table>:<id>[,<id>...] --dry-run [--since=<date>] [--connection=<name> ...] [--path=<dir>]
```

`--dry-run` is required — the command refuses to run without it, and `--full` is rejected outright. The command prints, per table, the row count and estimated size the dump would take, plus any unresolved references (an edge the traversal could not follow, e.g. because its target table is `skip`ped or unconfigured). Nothing is extracted; this phase only plans.

## Rules, References, and Templates

Read before executing:

- `README.md` — full command reference, the config file shape, and an annotated example
- `config/dead-drop.php` — the two keys currently in effect (`config_path`, `model_paths`) and the reserved ones
- `src/Console/Commands/InitCommand.php`, `CheckCommand.php`, `DumpCommand.php` — exact signatures and behavior

## Examples

- A team enabling Dead Drop on a fresh app: publish the config, run `dead-drop:init --connection=mysql`, review the generated `config/dead-drop/mysql.php` (confirm `redact` entries, fix any `review` placeholders), then add `php artisan dead-drop:check` as a CI step after migrations.
- Planning what a support ticket would pull: `php artisan dead-drop:dump --root=mysql.companies:482 --dry-run` to see every table and row count a redacted dump of that company would eventually need, before extraction exists.

## Anti-patterns

- Do not document `--full`, real extraction, redaction execution, or loading as available — this phase only discovers, reviews and plans.
- Do not tell a consumer to configure `disk`, `path`, `redaction`, `binaries` or `pull` for working behavior; they are reserved and currently unused.
- Do not hand-write a `<connection>.php` config from scratch; always generate it with `dead-drop:init` and then review it.

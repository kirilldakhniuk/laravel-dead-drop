<div align="center">
    <h1>Dead Drop</h1>
</div>

<p align="center">
    <a href="https://packagist.org/packages/kirilldakhnyuk/laravel-dead-drop"><img src="https://img.shields.io/packagist/v/kirilldakhnyuk/laravel-dead-drop.svg?style=flat-square" alt="Packagist"></a>
    <a href="https://packagist.org/packages/kirilldakhnyuk/laravel-dead-drop"><img src="https://img.shields.io/packagist/php-v/kirilldakhnyuk/laravel-dead-drop.svg?style=flat-square" alt="PHP from Packagist"></a>
    <a href="https://badge.laravel.cloud/badge/kirilldakhnyuk/laravel-dead-drop"><img src="https://badge.laravel.cloud/badge/kirilldakhnyuk/laravel-dead-drop?style=flat" alt="Laravel versions"></a>
    <a href="https://github.com/kirilldakhnyuk/laravel-dead-drop/actions"><img alt="Tests" src="https://img.shields.io/github/actions/workflow/status/kirilldakhnyuk/laravel-dead-drop/tests.yml?branch=main&label=Tests&style=flat-square"></a>
    <a href="https://packagist.org/packages/kirilldakhnyuk/laravel-dead-drop"><img src="https://img.shields.io/packagist/dt/kirilldakhnyuk/laravel-dead-drop.svg?style=flat-square" alt="Total Downloads"></a>
</p>

**Get a copy of production data onto your laptop, with the personal data taken out.**

Dead Drop reads your schema, writes a config file saying how each table should be handled, dumps the database into a redacted artifact, and loads that artifact into a local database.

Requires PHP 8.3+, `ext-zlib`, and Laravel 12 or 13. Works with MySQL, MariaDB, PostgreSQL and SQLite.

## Installation

```bash
composer require kirilldakhnyuk/laravel-dead-drop
php artisan vendor:publish --tag="dead-drop-config"
```

## How it works

Four commands. You only run the first two once.

| | | |
|---|---|---|
| 1 | `dead-drop:init` | Reads your schema and writes `config/dead-drop/<connection>.php`, guessing which columns hold personal data. **You review that file.** |
| 2 | `dead-drop:check` | Tells you when the schema and the config have drifted apart. Run it in CI. |
| 3 | `dead-drop:dump` | Runs on production. Reads every table, redacts it, writes an artifact. |
| 4 | `dead-drop:pull` | Runs on your laptop. Loads that artifact into a local database. |

```bash
php artisan dead-drop:init     # once: discover and review
php artisan dead-drop:check    # in CI: catch drift
php artisan dead-drop:dump     # on production: write a redacted artifact
php artisan dead-drop:pull     # on your laptop: load it
```

It works with no configuration: artifacts go to the `local` disk, and the redaction salt is derived from your `APP_KEY`.

## The config file

`dead-drop:init` writes one file per connection. This is the part you own — it records your decisions, and nothing is dumped until it is valid.

```php
<?php

return [
    'users' => [
        'class' => 'data',
        'columns' => ['id', 'name', 'email', 'password'],
        'redact' => [
            'email' => 'hash',
            'password' => 'bcrypt:secret',
        ],
    ],
    'sessions' => [
        'class' => 'skip',
        'columns' => ['id', 'user_id', 'payload'],
    ],
];
```

| Key | Meaning |
|---|---|
| `class` | `data` dumps the table, `skip` never does. Framework tables (`migrations`, `jobs`, `sessions`, `cache`, `password_reset_tokens`, `personal_access_tokens`, `telescope_*`, `pulse_*`) are set to `skip` for you. |
| `columns` | The columns as of the last `init`, so `check` can spot new ones. |
| `redact` | How to redact a column. See below. |
| `removed` | Set when a table disappears from the schema. `init` marks it rather than throwing your decisions away. |

Re-running `init` is safe: it merges into the existing file and never overwrites a decision you made.

You may also see `references`, `window`, `morph` and `descend`. `init` works them out and `check` keeps them honest, but no command reads them yet — they exist for slice-around-one-row dumps, which are not exposed. Ignore them for now.

## Redaction

Every redacted value is derived from a salt, so the same input always gives the same output and rows still join up.

| `redact` value | Result |
|---|---|
| `hash` | SHA-256 of the value. On an email-shaped column, `{hex}@example.test`. |
| `mask` | Last 4 characters kept, the rest become `*`. |
| `null` | `null`. |
| `scramble` | A date shifted by a stable offset within ±180 days. |
| `bcrypt:<value>` | A bcrypt hash of `<value>`, computed once per dump. |
| `fixed:<value>` | The literal `<value>`. |
| `keep` | Unchanged — records that you looked and it was fine. |
| `review` | A placeholder `init` writes when it cannot decide. **A dump refuses to run until you replace it.** |

`init` suggests redactions for columns that look personal — `email`, `phone`, `password`, `*_token`, `ssn`, `card_number`, `date_of_birth` and others — and writes `review` for JSON columns, whose contents it cannot inspect.

**Nothing moves until the config is valid.** Before a single row is read, `dead-drop:dump` refuses on schema drift, a leftover `review`, a salt under 16 characters, or a redaction that cannot work (`null` on a `NOT NULL` column, `scramble` on something that is not a date, a hash too short to stay unique). There is no override flag.

## Dumping

```bash
php artisan dead-drop:dump                    # ask, then extract
php artisan dead-drop:dump --dry-run          # show the plan, write nothing
php artisan dead-drop:dump --connection=mysql
php artisan dead-drop:dump --queue            # hand it to a queue worker
```

Every `data` table with rows is taken whole and redacted, so the result is complete by construction — no row points at one that is missing. You get a directory holding one gzipped NDJSON file per table plus a `manifest.json`. `dead-drop:dumps` lists what is on a disk.

In a terminal it asks which connection to use and whether to plan or extract. Anywhere else — CI, cron, a container — it asks nothing and just runs.

On MySQL the dump runs inside a read-only `REPEATABLE READ` transaction so every table is read at the same instant. That needs InnoDB, so a MyISAM table or a view in scope is refused: `skip` it or convert it.

## Pulling

```bash
php artisan dead-drop:pull                          # pick an artifact and a target
php artisan dead-drop:pull <id> --connection=local --force
```

Each table the artifact names is replaced in its own transaction — delete, insert, verify the count. Tables the artifact does not name are left alone, so this is a targeted replace, not a restore. Dead Drop does not create schema: migrate the target first.

**`pull` refuses to run outside `local` and `staging`** (`pull.allow_environments`), checked before it touches anything. It also asks you to confirm, and a non-interactive run has to pass `--force` rather than have silence taken for consent.

Run something afterwards with `pull.after`, which takes Artisan command names or invokable class-strings.

## Configuration

`config/dead-drop.php`:

| Key | Default | |
|---|---|---|
| `disk` | `local` | Where artifacts live. Set `DEAD_DROP_DISK=s3` to hand one off. |
| `path` | `dead-drops` | Base path on that disk. |
| `config_path` | `dead-drop` | Directory under `config/` holding the per-connection files. |
| `model_paths` | `['app/Models']` | Scanned for Eloquent relationships during `init`. |
| `redaction.salt` | from `APP_KEY` | Set `DEAD_DROP_REDACTION_SALT` to keep hashes stable across a key rotation. |
| `redaction.email_domain` | `example.test` | Used by `hash` on email columns. |
| `pull.allow_environments` | `['local', 'staging']` | Where `pull` may run. |
| `pull.after` | `[]` | Run after a successful pull. |
| `queue.*` | | Used only by `dead-drop:dump --queue`. |

On `init`, `check` and `dump`, `--path=` means the config directory. On `dumps` and `pull` it means the artifact path.

## Not implemented yet

Native executors (`mysqldump`, `mysqlsh`, `psql`), tables with composite primary keys, creating schema on the target, and dumping a slice around one row rather than the whole database.

## Contributing

Please see the [contributing guide](.github/CONTRIBUTING.md), and run `composer test` before opening a pull request.

Tests that need a live MySQL server are skipped by default. To run them, point at a throwaway server whose user can create and drop databases:

```bash
DEAD_DROP_TEST_MYSQL_PORT=3306 DEAD_DROP_TEST_MYSQL_USER=root \
DEAD_DROP_TEST_MYSQL_PASSWORD=testing vendor/bin/pest tests/Feature/Extraction/MySqlSnapshotTest.php
```

## Security

Please review [our security policy](.github/SECURITY.md) on how to report vulnerabilities.

## Credits

- [Kirill D.](https://github.com/kirilldakhnyuk)
- [All Contributors](../../contributors)

## License

MIT. See [LICENSE.md](LICENSE.md).

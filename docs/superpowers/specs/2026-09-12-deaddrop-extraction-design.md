# DeadDrop — Extraction, Redaction and Loading (phase 3) — Design

**Status:** approved in conversation on 2026-09-12; this document is the binding spec for the phase-3 implementation plan.
**Builds on:** phases 1–2 (`docs/superpowers/plans/2026-09-11-deaddrop-phase-1-2.md`), merged to `main` at `b0b47c1`.

## 1. Goal

Make `dead-drop:dump` produce a redacted, referentially-complete artifact of a planned row set, and add `dead-drop:pull` to load that artifact into a local or staging database. Success is end to end: after `dump` on a source connection and `pull` on a target connection, the target holds exactly the planned rows with the configured redactions applied.

Row movement is done by a pluggable **executor** (driver pattern). This phase ships the `php` executor and the NDJSON artifact format; native-tool executors come later behind the same interface.

## 2. Non-goals

- Native executors (`mysqldump`, `mysqlsh`, `psql`) — the interface and the manifest `format` field exist so they can be added without touching the commands, but none ships.
- Composite primary keys — the planner keeps refusing them.
- `--full` (whole-database) dumps and incremental dumps.
- S3-specific behaviour beyond what `Illuminate\Support\Facades\Storage` already gives; the `local` disk is what the tests and the trial use.
- Schema creation on the target; `pull` loads rows into tables that already exist.

## 3. Architecture

Three new layers sit on top of `Planning/`. Each has one job and talks to the others only through the interfaces named here.

| Layer | Job | Knows about |
|---|---|---|
| `Extraction/` | move the rows of one `PlanStep` from the source into an artifact, redacting on the way | executors, `PlanStep`, `KeySet`, `Redactor`, `ArtifactWriter` |
| `Redaction/` | turn a source row into a redacted row according to a table's `redact` map | `TableConfig`, `Table` |
| `Artifacts/` | the on-disk layout: manifest plus per-table files, read and written through `Storage` | nothing about databases |
| `Loading/` | read an artifact and write it into a target connection | `ArtifactReader`, loaders keyed by format |

Data flow:

```
source DB ──plan──> key tables ──Executor::export (php: read chunks → Redactor → ndjson.gz)──> {disk}/{path}/{dump-id}/
target DB <──Loader (chunked delete + insert, FK checks off)── ArtifactReader <── manifest.json + *.ndjson.gz
```

The traversal and the extraction run in one process, because key tables are session-scoped temporary tables. `dump` drops them in a `finally`, as `--dry-run` does today.

## 4. Executor driver pattern

```php
namespace DeadDrop\DeadDrop\Extraction;

interface Executor
{
    /** Driver name as selected by config, e.g. 'php'. */
    public function name(): string;

    /** Whether this executor can read from a connection of the given Laravel driver ('mysql', 'pgsql', 'sqlite', 'mariadb'). */
    public function supports(string $connectionDriver): bool;

    /** Export one plan step: read rows scoped by $keys (null for a whole lookup table), redact, write, and describe the file. */
    public function export(PlanStep $step, Table $table, TableConfig $config, ?KeySet $keys, ArtifactWriter $writer): TableArtifact;
}
```

- `TableArtifact(string $connection, string $table, string $file, string $format, int $rows, int $bytes)` — `format` is `ndjson` for the PHP executor; a future native executor declares `csv` or `sql`.
- `ExecutorManager` (Laravel manager style): `driver(?string $name = null): Executor` resolves `config('dead-drop.executor')` by default; `extend(string $name, Closure $factory): void` registers a custom executor from any service provider. Unknown names throw `InvalidArgumentException("Unsupported DeadDrop executor [$name].")`. The manager is a singleton.
- `PhpExecutor` (`name() === 'php'`, supports every driver): for a step with a key set, reads `select {table}.* from {table} join {keyTable} on {table}.{pk} = {keyTable}.k order by {pk}` in chunks of 1,000 through the query builder (prefix-safe, write connection pinned as in planning); for a lookup step, reads the whole table ordered by primary key. Each row passes through the `Redactor`, then to `ArtifactWriter::appendRow()`. Memory holds at most one chunk.
- Redaction consistency is a contract, not shared code: the semantics in §6 are chosen so a SQL-side executor can reproduce `hash`, `mask`, `null`, `fixed`, `bcrypt` and `keep`; `scramble` is the one a native executor may refuse (it must then fail the dump for that column, never silently keep the value).

Config additions (`config/dead-drop.php`):

```php
'executor' => env('DEAD_DROP_EXECUTOR', 'php'),
```

`disk`, `path`, `redaction.salt`, `redaction.email_domain`, `pull.allow_environments`, `pull.after` already exist and become live in this phase. `binaries` stays reserved for native executors.

## 5. Artifact layout

Root: `{disk}:{path}/{dump-id}/` where `dump-id` is `Ymd-His-<6 random lowercase alnum>` (UTC). Files:

- `manifest.json`
- `{connection}.{table}.ndjson.gz` — one per `PlanStep` with rows; gzip-compressed, one JSON object per line, keys in the manifest's column order.

`manifest.json`:

```json
{
  "version": 1,
  "id": "20260912-141500-a8k2zq",
  "status": "writing" | "complete",
  "created_at": "2026-09-12T14:15:00+00:00",
  "package_version": "…",
  "root": "mysql.companies:994",
  "since": null | "2026-06-01T00:00:00+00:00",
  "executor": "php",
  "connections": { "mysql": { "driver": "mysql" } },
  "tables": [
    {
      "connection": "mysql", "table": "companies", "file": "mysql.companies.ndjson.gz",
      "format": "ndjson", "rows": 3, "bytes": 1234, "primary_key": "company_id",
      "columns": [ { "name": "company_id", "type": "int" }, … ],
      "redacted": [ "stripe_id" ]
    }
  ],
  "unresolved": [ { "connection": "mysql", "table": "…", "column": "…", "reason": "…" } ]
}
```

`tables` is in plan-step order (parents before children), which is also load order. `columns[].type` is the phase-1 `ColumnType` backing value; loaders use it to restore scalars (integers, booleans, decimals as strings, datetimes as strings, JSON columns as JSON text, binary as base64 with a `"__base64": true` wrapper). `status` is written as `writing` first and flipped to `complete` last, so a crash leaves an artifact that `pull` refuses and `dumps` flags.

`ArtifactWriter` and `ArtifactReader` are the only classes that know this layout. Both use `Storage::disk($disk)`. Writing streams: the writer opens a temporary local gzip stream per table, appends rows, and moves the finished file to the disk with `Storage::put(path, stream)`, so S3 gets one upload per table and nothing is buffered in memory.

## 6. Redaction

`Redactor::forTable(TableConfig $config, Table $table, RedactionContext $ctx): Redactor` builds one `Transformer` per `redact` entry. `apply(array $row): array` returns the redacted row. The context carries the salt, the email domain, and the per-dump bcrypt value.

Transformer semantics (value → result), `null` input always stays `null`:

| `redact` value | result |
|---|---|
| `hash` | lowercase hex SHA-256 of `salt . value`, truncated to the column's declared length when known; when the column name matches the detector's email patterns (`email`, `email_address`, `*_email`) the result is `{first 16 hex}@{email_domain}` |
| `mask` | last 4 characters kept, every preceding character replaced by `*`; values of 4 characters or fewer become `****` |
| `null` | `null`; rejected at the gate when the column is NOT NULL |
| `scramble` | for date/datetime columns: value shifted by a deterministic offset in `[-180, +180]` days derived from `salt . primaryKeyValue`; rejected at the gate for any other column type |
| `bcrypt:<value>` | one `Hash::make(value)` computed per dump and reused for every row |
| `fixed:<value>` | the literal `<value>` (empty allowed) |
| `keep` | passthrough (records a human decision that the flagged column is fine) |
| `review` | never reaches the redactor — the gate refuses |

Rules: primary key columns and any column that is the source of a configured reference are never redacted; a `redact` entry on one is rejected at the gate with the column named. Redaction runs on collected rows only and cannot change which rows are collected. `hash` uses `hash('sha256', …)` (the arch presets ban `md5`/`sha1`; SHA-256 is also what MySQL `SHA2(…, 256)` and Postgres `pgcrypto` produce, which keeps native executors reproducible).

## 7. The fail-closed gate

Before any row moves, `dump` (without `--dry-run`) runs `ExtractionGate::check(ConfigSet, SchemaSet, ExtractionPlan, RedactionContext)` and stops with exit code 1 on the first category that fails, printing the same lines `dead-drop:check` prints:

1. Drift on any connection in the plan (`DriftDetector`: new/removed tables or columns, undecided sensitive columns, `review` placeholders).
2. `redaction.salt` missing or shorter than 16 characters.
3. Invalid redaction placements: `null` on a NOT NULL column, `scramble` on a non-date column, any entry on a primary key or reference column, an unknown transformer name.
4. Root ids that do not exist in the root table (also applied to `--dry-run`; this closes the phase-2 smoke-test finding).

There is no bypass flag.

## 8. Commands

- `dead-drop:dump {--root=} {--since=} {--connection=*} {--path=} {--disk=} {--dry-run} {--full}` — `--dry-run` unchanged. Without it: plan → gate → executor selection (`ExecutorManager::driver()`; refuse when `supports()` is false for the source driver) → write `manifest.json` (`status: writing`) → export every step in plan order, printing one progress line per table (`mysql.companies … 3 rows`) → flip status to `complete` → print the plan table, totals, the unresolved list, and `Artifact: {disk}:{path}/{id}`. `--full` still fails with "not implemented". Key tables are dropped in a `finally`.
- `dead-drop:pull {id?} {--connection=} {--disk=} {--path=} {--force}` — refuses unless `app()->environment(config('dead-drop.pull.allow_environments'))`; with no `id`, uses the newest `complete` artifact; refuses `status !== 'complete'`; target = `--connection` or the default connection; refuses when a table's `columns` contain names missing on the target (and names them); asks for confirmation (`confirm()` from Laravel Prompts) listing the tables that will be replaced unless `--force` or `--no-interaction`; loads (see §9); runs `pull.after`; prints per-table counts and totals; exit 1 on any failure.
- `dead-drop:dumps {--disk=} {--path=}` — lists artifacts: id, created, root, status, tables, rows, size. Newest first.

Every command remains fully drivable non-interactively.

## 9. Loading

`Loader` interface: `format(): string; load(TableManifest $table, ArtifactReader $reader, Connection $target, Table $schema): int` returning rows written. `NdjsonLoader` is the only implementation now; `LoaderRegistry` maps `format → Loader` and throws for an unknown format.

`PullRunner::run(Manifest, string $targetConnection, callable $progress): PullReport`:

1. Introspect the target once (`Introspector::inspect`).
2. `driver->disableForeignKeyChecks($connection)` (new `DatabaseDriver` method; MySQL `SET FOREIGN_KEY_CHECKS=0`, Postgres `SET session_replication_role = replica`, SQLite `PRAGMA foreign_keys = OFF`), re-enabled in a `finally` with the matching statement.
3. For each manifest table in order, skipping and naming tables absent from the target: inside one transaction, `delete` all rows, then insert the artifact rows in chunks of 500 (scalars restored per `columns[].type`), then compare the inserted count with the manifest count and throw on mismatch (rolling the table back).
4. `pull.after`: each string entry is run as an Artisan command (`Artisan::call`), each class-string entry is resolved from the container and invoked with the `PullReport`.

Replace mode is the only mode. Tables not in the artifact are untouched.

## 10. Error handling

- Gate failures, unsupported executor, disk write failures, target environment refusals, incomplete artifacts, missing target columns and row-count mismatches are all printed with `$this->error()` and exit 1; none produce a stack trace.
- `QueryException` during extraction or loading is caught, reported with the table name, and exits 1; extraction leaves the artifact in `status: writing`; loading rolls the current table back and stops.
- Key tables are always dropped; foreign-key checks are always restored.
- Nothing writes to the source connection except temporary tables.

## 11. Testing

Pest, against the SQLite fixture, end to end and unit:

- **End to end:** `init` → finish the fixture's `review` entries by editing the generated config → `check` green → `dump` to `Storage::fake('local')` → manifest and per-table row counts equal the dry-run plan for the same root → `pull --connection=dd_target` into a second in-memory connection migrated with `SchemaBuilder::migrate('dd_target')` → every collected row exists on the target, `users.email` is `{hash}@example.test`, `users.password` verifies against `secret` with `Hash::check`, `companies.stripe_id` is `redacted`, order 99 is absent → `pull` again and counts are unchanged.
- **Transformers:** one test per row of the §6 table, plus null passthrough and the email form.
- **Gate:** one refusal test per §7 category, including a nonexistent root id.
- **Executor manager:** default resolves `php`; `extend()` registers a custom executor and `dump` uses it; unknown name refused; `supports()` false refused.
- **Artifacts:** writer/reader round trip including a JSON column and a binary column; `status` handling; `dumps` listing order.
- **Loader:** replace semantics; missing target table skipped and named; missing target column refused; row-count mismatch rolls back; foreign-key checks restored after an exception.
- **Environment guard:** `pull` refuses in `production`.
- Existing 125 tests keep passing; `composer test` (PHPStan 7, Pint, 100% type coverage, Pest) stays green.

## 12. Trial after implementation

1. `stupidbrains-app` (Laravel 12, SQLite): install as a path repository, `init`, resolve `review` lines, `check`, `dump --root=sqlite.users:1` to the `local` disk, `pull --connection=target` into a fresh migrated SQLite file, and query it. Exercises the Laravel 12 lane and the in-app morph map.
2. `concom_app` (MySQL, 282 tables) through the Testbench CLI as in the phase-2 smoke test, at least through `dump` to the local disk.

## 13. Also in this phase (phase-2 smoke-test findings)

- Root ids are validated (§7.4).
- `spatial_ref_sys` joins the skip list.
- `composer.json` `analyse` script gains `--memory-limit=1G` (PHPStan crashed a parallel worker at the 128M default in the main checkout).

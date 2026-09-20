# MySQL export benchmark

See the [measured local results](results/mysql-2026-09-20.md).

This opt-in benchmark runs the actual `DumpRunner`, including snapshot creation,
planning, redaction, gzip compression, manifest updates, and local disk upload.
It does not measure queue wait time or remote object storage. No production code
is replaced. A query listener records aggregate timings without retaining rows
or SQL statements; the snapshot connection uses an instrumented instance of
Laravel's normal `MySqlConnection` for that listener.

Use an isolated MySQL server with permission to create and drop databases.
Install the package's Composer development dependencies first. The script uses
Testbench to boot Laravel and requires PHP's PDO MySQL extension.

```bash
export DEAD_DROP_TEST_MYSQL_PORT=3306
export DEAD_DROP_TEST_MYSQL_USER=root
export DEAD_DROP_TEST_MYSQL_PASSWORD=testing

php -d memory_limit=512M benchmarks/mysql.php seed
php -d memory_limit=512M benchmarks/mysql.php dump
php -d memory_limit=512M benchmarks/mysql.php verify
php -d memory_limit=512M benchmarks/mysql.php dump
php -d memory_limit=512M benchmarks/mysql.php verify
php benchmarks/mysql.php cleanup
```

`DEAD_DROP_TEST_MYSQL_HOST` defaults to `127.0.0.1`. An optional second argument
selects a results directory; the default is `build/mysql-benchmark`, which Git
ignores. Use a fresh directory for a fresh dataset. Cleanup removes only the
generated `dead_drop_bench_*` database and artifacts in that results directory,
retaining JSON measurements. Run cleanup even if a benchmark fails.

The fixed dataset contains about 1.14 GB of logical column values:

| Table | Rows | Payload |
| --- | ---: | --- |
| `narrow_rows` | 200,000 | Email, password, and 192-character random text |
| `json_rows` | 131,072 | JSON with a 4,096-character random token and metadata |
| `binary_rows` | 8,192 | Independent 64 KiB random blobs |

Emails are hashed and replacement passwords use the cached bcrypt transformer.
Synthetic JSON and binary values are retained. Random content avoids an
unrealistically favorable compression result from repeated filler strings.
The database needs roughly 1.5 GB of table allocation on the measured MySQL
setup; each saved artifact and the temporary gzip staging file need additional
space. The seed command checks for at least 6 GiB free on the results filesystem;
check the MySQL data and system temporary filesystems separately if they differ.

`dataset.json` records input sizes and payload checksums. `runs.jsonl` records
wall time, PHP CPU time, peak PHP allocator memory, query timings, and compressed
sizes for every dump. Start each dump in a fresh PHP process. The first table's
elapsed time includes planning; query timings include client fetching, not just
MySQL server CPU. They are part of wall time and must not be added to it.
Peak PHP allocator memory excludes the MySQL server and is not process RSS.
For process RSS on macOS, prefix a run with `/usr/bin/time -l`.

Verification streams every row through `ArtifactReader::rows()`, checks primary
key order, row totals, all retained payload checksums, email redaction, and one
password hash. It runs outside the timed export and saves `verification.json`.

Export currently uses `chunkById(1000)` callbacks; import uses a generator.
Both avoid retaining the entire dataset. A batch of wide rows can still use
substantial memory, so bounded row counts do not mean a fixed byte budget.

Results depend on data shape, CPU, storage, database load, and network latency.
Repeated local runs may benefit from operating-system caches; these are not
cold-cache tests or predictions for a production 50 GB export.

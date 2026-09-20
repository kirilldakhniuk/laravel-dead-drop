<?php

declare(strict_types=1);

use DeadDrop\DeadDrop\Artifacts\ArtifactReader;
use DeadDrop\DeadDrop\Config\ConfigSet;
use DeadDrop\DeadDrop\Config\ConnectionConfig;
use DeadDrop\DeadDrop\Config\TableClass;
use DeadDrop\DeadDrop\Config\TableConfig;
use DeadDrop\DeadDrop\DeadDropServiceProvider;
use DeadDrop\DeadDrop\Extraction\DumpRunner;
use DeadDrop\DeadDrop\Extraction\TableArtifact;
use DeadDrop\DeadDrop\Planning\Root;
use DeadDrop\DeadDrop\Schema\Introspector;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Connection;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\MySqlConnection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Orchestra\Testbench\Foundation\Application;

require dirname(__DIR__).'/vendor/autoload.php';

$action = $argv[1] ?? '';
$directory = $argv[2] ?? dirname(__DIR__).'/build/mysql-benchmark';

if (! in_array($action, ['seed', 'dump', 'verify', 'cleanup'], true)) {
    exit("Usage: php benchmarks/mysql.php seed|dump|verify|cleanup [results-directory]\n");
}

if (! is_dir($directory)) {
    mkdir($directory, 0755, true);
}

$directory = realpath($directory);
$statePath = $directory.'/dataset.json';
$app = Application::create();
$app->make(Kernel::class)->bootstrap();
$app->register(DeadDropServiceProvider::class);
$settings = [
    'driver' => 'mysql',
    'host' => getenv('DEAD_DROP_TEST_MYSQL_HOST') ?: '127.0.0.1',
    'port' => getenv('DEAD_DROP_TEST_MYSQL_PORT') ?: '3306',
    'username' => getenv('DEAD_DROP_TEST_MYSQL_USER') ?: 'root',
    'password' => getenv('DEAD_DROP_TEST_MYSQL_PASSWORD') ?: '',
    'database' => 'mysql',
    'charset' => 'utf8mb4',
    'collation' => 'utf8mb4_unicode_ci',
];
config()->set('database.connections.benchmark_admin', $settings);
config()->set('dead-drop.redaction.salt', str_repeat('benchmark-', 4));
config()->set('filesystems.disks.benchmark', ['driver' => 'local', 'root' => $directory.'/artifacts', 'throw' => true]);
$admin = DB::connection('benchmark_admin');

if ($action === 'seed') {
    if (is_file($statePath)) {
        throw new RuntimeException('Dataset already exists. Clean it up or choose a new results directory.');
    }

    if (disk_free_space($directory) < 6 * 1024 ** 3) {
        throw new RuntimeException('This benchmark needs at least 6 GiB free for the database and artifacts.');
    }

    $state = ['database' => 'dead_drop_bench_'.bin2hex(random_bytes(6)), 'tables' => []];
    file_put_contents($statePath, json_encode($state, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
    $admin->statement('CREATE DATABASE `'.$state['database'].'`');
} else {
    $state = json_decode(file_get_contents($statePath), true, flags: JSON_THROW_ON_ERROR);
}

if (! preg_match('/^dead_drop_bench_[a-f0-9]{12}$/D', $state['database'])) {
    throw new RuntimeException('Refusing to operate on a database not created by this benchmark.');
}

$settings['database'] = $state['database'];
config()->set('database.connections.benchmark', $settings);
$db = DB::connection('benchmark');

if ($action === 'cleanup') {
    DB::purge('benchmark');
    $admin->statement('DROP DATABASE IF EXISTS `'.$state['database'].'`');
    Storage::disk('benchmark')->deleteDirectory('dumps');
    echo "Removed the synthetic database and dump files; retained measurement reports.\n";
    exit(0);
}

if ($action === 'seed') {
    $started = hrtime(true);
    $db->statement('CREATE TABLE narrow_rows (id BIGINT UNSIGNED PRIMARY KEY, email VARCHAR(128) NOT NULL, password VARCHAR(128) NOT NULL, note VARCHAR(256) NOT NULL) ENGINE=InnoDB');
    $db->statement('CREATE TABLE json_rows (id BIGINT UNSIGNED PRIMARY KEY, email VARCHAR(128) NOT NULL, payload JSON NOT NULL) ENGINE=InnoDB');
    $db->statement('CREATE TABLE binary_rows (id BIGINT UNSIGNED PRIMARY KEY, payload LONGBLOB NOT NULL) ENGINE=InnoDB');

    foreach (['narrow_rows' => [200000, 500], 'json_rows' => [131072, 250], 'binary_rows' => [8192, 16]] as $table => [$count, $batchSize]) {
        $hash = hash_init('sha256');
        $logicalBytes = 0;

        for ($first = 1; $first <= $count; $first += $batchSize) {
            $rows = [];

            for ($id = $first; $id < min($first + $batchSize, $count + 1); $id++) {
                $payload = match ($table) {
                    'narrow_rows' => bin2hex(random_bytes(96)),
                    'json_rows' => bin2hex(random_bytes(2048)),
                    'binary_rows' => random_bytes(65536),
                };
                hash_update($hash, $payload);
                $row = ['id' => $id];

                if ($table !== 'binary_rows') {
                    $row['email'] = "person{$id}@synthetic.invalid";
                }

                if ($table === 'narrow_rows') {
                    $row['password'] = 'synthetic-password-value';
                    $row['note'] = $payload;
                } elseif ($table === 'json_rows') {
                    $row['payload'] = json_encode(['token' => $payload, 'status' => 'active', 'tags' => ['synthetic', 'benchmark'], 'sequence' => $id], JSON_THROW_ON_ERROR);
                } else {
                    $row['payload'] = $payload;
                }

                foreach ($row as $value) {
                    $logicalBytes += strlen((string) $value);
                }

                $rows[] = $row;
            }

            $db->table($table)->insert($rows);
        }

        $state['tables'][$table] = ['rows' => $count, 'logical_bytes' => $logicalBytes, 'payload_sha256' => hash_final($hash)];
        file_put_contents($statePath, json_encode($state, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
        echo json_encode(['seeded' => $table] + $state['tables'][$table], JSON_THROW_ON_ERROR)."\n";
        $db->statement("ANALYZE TABLE `{$table}`");
    }

    $state['seed_seconds'] = (hrtime(true) - $started) / 1e9;
    $state['mysql_version'] = $db->scalar('select version()');
    $state['buffer_pool_bytes'] = (int) $db->scalar('select @@innodb_buffer_pool_size');
    $state['allocated_table_bytes'] = (int) $db->scalar('select sum(DATA_LENGTH + INDEX_LENGTH) from information_schema.TABLES where TABLE_SCHEMA = ?', [$state['database']]);
    file_put_contents($statePath, json_encode($state, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
    echo json_encode($state, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)."\n";
    exit(0);
}

if (count($state['tables']) !== 3) {
    throw new RuntimeException('Seeding did not finish. Clean up and seed a new results directory.');
}

$reader = new ArtifactReader(Storage::disk('benchmark'), 'dumps');

if ($action === 'verify') {
    $manifest = $reader->latestComplete() ?? throw new RuntimeException('No completed dump.');

    if (count($manifest->tables) !== count($state['tables'])) {
        throw new RuntimeException('Dump is missing a benchmark table.');
    }

    $started = hrtime(true);
    memory_reset_peak_usage();

    foreach ($manifest->tables as $table) {
        $hash = hash_init('sha256');
        $count = 0;

        foreach ($reader->rows($manifest->id, $table) as $row) {
            $count++;

            if ($row['id'] !== $count || (isset($row['email']) && ! str_ends_with($row['email'], '@example.test'))) {
                throw new RuntimeException("Invalid row ID or unredacted email in {$table->table}.");
            }

            if (isset($row['password']) && $count === 1 && ! password_verify('benchmark-password', $row['password'])) {
                throw new RuntimeException('Password redaction failed.');
            }

            $payload = match ($table->table) {
                'narrow_rows' => $row['note'],
                'json_rows' => json_decode($row['payload'], true, flags: JSON_THROW_ON_ERROR)['token'],
                'binary_rows' => $row['payload'],
            };
            hash_update($hash, $payload);
        }

        if ($count !== $state['tables'][$table->table]['rows'] || hash_final($hash) !== $state['tables'][$table->table]['payload_sha256']) {
            throw new RuntimeException("Row count or payload checksum mismatch in {$table->table}.");
        }

        echo "Verified {$table->table}: {$count} rows, payload checksum and redaction.\n";
    }

    $verification = ['artifact' => $manifest->id, 'seconds' => (hrtime(true) - $started) / 1e9, 'peak_php_bytes' => memory_get_peak_usage(true), 'verified' => true];
    file_put_contents($directory.'/verification.json', json_encode($verification, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
    echo json_encode($verification, JSON_THROW_ON_ERROR)."\n";
    exit(0);
}

$schemas = app(Introspector::class)->inspect('benchmark');
$tables = [];

foreach ($schemas->tables as $table) {
    $redact = match ($table->name) {
        'narrow_rows' => ['email' => 'hash', 'password' => 'bcrypt:benchmark-password'],
        'json_rows' => ['email' => 'hash', 'payload' => 'keep'],
        default => [],
    };
    $tables[$table->name] = new TableConfig($table->name, TableClass::Data, array_values($table->columnNames()), [], $redact, null, null, null);
}

$config = new ConfigSet(['benchmark' => new ConnectionConfig('benchmark', $tables)]);
Connection::resolverFor('mysql', function ($pdo, $database, $prefix, $settings): MySqlConnection {
    $connection = new MySqlConnection($pdo, $database, $prefix, $settings);
    $connection->setEventDispatcher(app('events'));

    return $connection;
});
$runner = app(DumpRunner::class);
$sql = ['queries' => 0, 'seconds' => 0.0, 'count_seconds' => 0.0, 'chunk_queries' => 0];
DB::listen(function (QueryExecuted $event) use (&$sql): void {
    $sql['queries']++;
    $sql['seconds'] += $event->time / 1000;

    if (str_contains(strtolower($event->sql), 'count(*)')) {
        $sql['count_seconds'] += $event->time / 1000;
    }

    if (str_contains(strtolower($event->sql), ' limit 1000')) {
        $sql['chunk_queries']++;
    }
});
$tableResults = [];
$started = $lastTable = hrtime(true);
$usageBefore = getrusage();
memory_reset_peak_usage();
$baselineMemory = memory_get_usage(true);
$result = $runner->run(Root::full('benchmark'), $config, 'benchmark', 'dumps', progress: function (TableArtifact $table) use (&$lastTable, &$tableResults): void {
    $now = hrtime(true);
    $tableResults[] = ['table' => $table->table, 'rows' => $table->rows, 'compressed_bytes' => $table->bytes, 'seconds_since_previous_table' => ($now - $lastTable) / 1e9, 'peak_php_bytes' => memory_get_peak_usage(true)];
    $lastTable = $now;
    echo json_encode(end($tableResults), JSON_THROW_ON_ERROR)."\n";
});
$seconds = (hrtime(true) - $started) / 1e9;
$peakMemory = memory_get_peak_usage(true);
$usageAfter = getrusage();

if (! $result->manifest?->isComplete()) {
    throw new RuntimeException('Dump failed: '.implode('; ', $result->violations));
}

$cpuSeconds = static fn (array $usage): float => $usage['ru_utime.tv_sec'] + $usage['ru_utime.tv_usec'] / 1e6 + $usage['ru_stime.tv_sec'] + $usage['ru_stime.tv_usec'] / 1e6;
$report = [
    'artifact' => $result->manifest->id,
    'php_version' => PHP_VERSION,
    'laravel_version' => $app->version(),
    'seconds' => $seconds,
    'php_cpu_seconds' => $cpuSeconds($usageAfter) - $cpuSeconds($usageBefore),
    'baseline_php_bytes' => $baselineMemory,
    'peak_php_bytes' => $peakMemory,
    'rows' => array_sum(array_column($state['tables'], 'rows')),
    'logical_bytes' => array_sum(array_column($state['tables'], 'logical_bytes')),
    'compressed_bytes' => array_sum(array_column($tableResults, 'compressed_bytes')),
    'sql' => $sql,
    'tables' => $tableResults,
];
file_put_contents($directory.'/runs.jsonl', json_encode($report, JSON_THROW_ON_ERROR)."\n", FILE_APPEND);
echo json_encode($report, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)."\n";

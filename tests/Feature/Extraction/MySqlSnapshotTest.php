<?php

declare(strict_types=1);

use DeadDrop\DeadDrop\Artifacts\ArtifactReader;
use DeadDrop\DeadDrop\Config\ConfigLoader;
use DeadDrop\DeadDrop\Extraction\DumpRunner;
use DeadDrop\DeadDrop\Extraction\SourceConnections;
use DeadDrop\DeadDrop\Extraction\TableArtifact;
use DeadDrop\DeadDrop\Planning\Root;
use DeadDrop\DeadDrop\Tests\Fixtures\SchemaBuilder;
use Illuminate\Database\QueryException;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    if (! getenv('DEAD_DROP_TEST_MYSQL_PORT')) {
        $this->markTestSkipped('Set DEAD_DROP_TEST_MYSQL_PORT to run isolated MySQL integration tests.');
    }

    $this->mysqlDatabase = 'dead_drop_test_'.bin2hex(random_bytes(6));
    $settings = [
        'driver' => 'mysql',
        'host' => getenv('DEAD_DROP_TEST_MYSQL_HOST') ?: '127.0.0.1',
        'port' => getenv('DEAD_DROP_TEST_MYSQL_PORT'),
        'username' => getenv('DEAD_DROP_TEST_MYSQL_USER') ?: 'root',
        'password' => getenv('DEAD_DROP_TEST_MYSQL_PASSWORD') ?: '',
        'database' => 'mysql',
        'charset' => 'utf8mb4',
        'collation' => 'utf8mb4_unicode_ci',
    ];
    config()->set('database.connections.dd_mysql_admin', $settings);
    DB::connection('dd_mysql_admin')->statement('CREATE DATABASE `'.$this->mysqlDatabase.'`');
    $settings['database'] = $this->mysqlDatabase;
    config()->set('database.connections.dd_mysql', $settings);
    SchemaBuilder::migrate('dd_mysql');
    SchemaBuilder::seedTwoCompanies('dd_mysql');
    Schema::connection('dd_mysql')->create('jobs', function ($table): void {
        $table->bigIncrements('id');
        $table->string('queue')->index();
        $table->longText('payload');
        $table->unsignedTinyInteger('attempts');
        $table->unsignedInteger('reserved_at')->nullable();
        $table->unsignedInteger('available_at');
        $table->unsignedInteger('created_at');
    });
    fakeArtifactDisk();
    $this->mysqlConfigPath = tempDirectory();
    $this->artisan('dead-drop:init', ['--connection' => ['dd_mysql'], '--path' => $this->mysqlConfigPath, '--no-interaction' => true])->assertSuccessful();
});

afterEach(function () {
    if (isset($this->mysqlDatabase)) {
        DB::purge('dd_mysql');
        DB::connection('dd_mysql_admin')->statement('DROP DATABASE `'.$this->mysqlDatabase.'`');
        DB::purge('dd_mysql_admin');
    }
});

it('keeps planning and table exports on one snapshot during concurrent writes', function () {
    $config = (new ConfigLoader)->loadAll($this->mysqlConfigPath);
    $source = DB::connection('dd_mysql');
    $source->statement('SET SESSION TRANSACTION ISOLATION LEVEL READ COMMITTED');
    $changed = false;

    $result = app(DumpRunner::class)->run(Root::full('dd_mysql'), $config, 'local', 'dead-drops', progress: function (TableArtifact $table) use ($source, &$changed): void {
        if ($table->table !== 'companies') {
            return;
        }

        $source->transaction(function () use ($source): void {
            $source->table('companies')->insert(['id' => 3, 'name' => 'New company']);
            $source->table('orders')->insert(['id' => 101, 'company_id' => 3, 'user_id' => 50, 'total' => 99]);
            $source->table('orders')->where('id', 1)->update(['total' => 500]);
        });
        $changed = true;
    });

    $reader = new ArtifactReader(Storage::disk('local'), 'dead-drops');
    $orders = collect($result->manifest->tables)->firstWhere('table', 'orders');
    $rows = collect(iterator_to_array($reader->rows($result->manifest->id, $orders)));
    expect($changed)->toBeTrue()
        ->and($rows->pluck('id')->all())->toBe([1, 2, 99])
        ->and($rows->firstWhere('id', 1)['total'])->toBe('10.00')
        ->and($source->table('orders')->count())->toBe(4)
        ->and($source->scalar('select @@SESSION.transaction_isolation'))->toBe('READ-COMMITTED')
        ->and($source->transactionLevel())->toBe(0);
});

it('preserves an application transaction and releases the snapshot after failure', function () {
    $source = DB::connection('dd_mysql');
    $source->beginTransaction();
    $source->table('companies')->insert(['id' => 3, 'name' => 'Uncommitted']);
    $connections = app(SourceConnections::class);
    $config = (new ConfigLoader)->loadAll($this->mysqlConfigPath);
    $snapshot = null;

    try {
        $run = function () use ($connections, $config, &$snapshot): void {
            $connections->snapshot($config, 'dd_mysql', function () use ($connections, &$snapshot): void {
                $snapshot = $connections->get('dd_mysql');
                expect($snapshot->table('companies')->count())->toBe(2);

                throw new RuntimeException('export failed');
            });
        };
        expect($run)->toThrow(RuntimeException::class, 'export failed');

        expect($connections->get('dd_mysql'))->toBe($source)
            ->and($source->transactionLevel())->toBe(1)
            ->and($snapshot->getRawPdo())->toBeNull();
    } finally {
        $source->rollBack();
    }
});

it('refuses nontransactional tables before creating an artifact', function () {
    DB::connection('dd_mysql')->statement('ALTER TABLE countries ENGINE=MyISAM');
    $config = (new ConfigLoader)->loadAll($this->mysqlConfigPath);

    expect(fn () => app(DumpRunner::class)->run(Root::full('dd_mysql'), $config, 'local', 'dead-drops'))
        ->toThrow(RuntimeException::class, 'require InnoDB');
    expect(Storage::disk('local')->allFiles())->toBe([])
        ->and(app(SourceConnections::class)->get('dd_mysql'))->toBe(DB::connection('dd_mysql'));
});

it('prevents writes through the snapshot connection', function () {
    $config = (new ConfigLoader)->loadAll($this->mysqlConfigPath);
    $connections = app(SourceConnections::class);

    $connections->snapshot($config, 'dd_mysql', function () use ($connections): void {
        expect(fn () => $connections->get('dd_mysql')->table('companies')->delete())
            ->toThrow(QueryException::class);
    });

    expect(DB::connection('dd_mysql')->table('companies')->count())->toBe(2);
});

it('reads snapshots from the writer even with an unavailable read replica', function () {
    config()->set('database.connections.dd_mysql.read', ['port' => 1]);
    config()->set('database.connections.dd_mysql.write', []);
    DB::purge('dd_mysql');
    $config = (new ConfigLoader)->loadAll($this->mysqlConfigPath);
    $result = app(DumpRunner::class)->run(Root::full('dd_mysql'), $config, 'local', 'dead-drops');

    expect($result->manifest->isComplete())->toBeTrue();
});

it('can start a fresh snapshot after a failed export', function () {
    $config = (new ConfigLoader)->loadAll($this->mysqlConfigPath);
    $runner = app(DumpRunner::class);
    expect(fn () => $runner->run(Root::full('dd_mysql'), $config, 'local', 'dead-drops', progress: function (): void {
        throw new RuntimeException('upload failed');
    }))->toThrow(RuntimeException::class, 'upload failed');

    $reader = new ArtifactReader(Storage::disk('local'), 'dead-drops');
    expect($reader->manifest($reader->ids()[0])->status)->toBe('failed');
    expect($runner->run(Root::full('dd_mysql'), $config, 'local', 'dead-drops')->manifest->isComplete())->toBeTrue();
});

it('uses a MySQL database queue without mixing its writes into the dump snapshot', function () {
    $failures = [];
    Event::listen(JobFailed::class, function (JobFailed $event) use (&$failures): void {
        $failures[] = $event->exception->getMessage();
    });
    config()->set('queue.connections.snapshot_queue', [
        'driver' => 'database', 'connection' => 'dd_mysql', 'table' => 'jobs',
        'queue' => 'dead-drop', 'retry_after' => 3700,
    ]);
    config()->set('dead-drop.queue.connection', 'snapshot_queue');
    $this->artisan('dead-drop:dump', ['--connection' => 'dd_mysql', '--path' => $this->mysqlConfigPath, '--queue' => true])->assertSuccessful();
    $this->artisan('queue:work', ['connection' => 'snapshot_queue', '--queue' => 'dead-drop', '--once' => true])->assertSuccessful();

    expect($failures)->toBe([]);
    $reader = new ArtifactReader(Storage::disk('local'), 'dead-drops');
    expect($reader->manifest($reader->ids()[0])->isComplete())->toBeTrue()
        ->and(DB::connection('dd_mysql')->table('jobs')->count())->toBe(0)
        ->and(DB::connection('dd_mysql')->transactionLevel())->toBe(0);
});

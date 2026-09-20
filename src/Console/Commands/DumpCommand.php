<?php

declare(strict_types=1);

namespace DeadDrop\DeadDrop\Console\Commands;

use DeadDrop\DeadDrop\Config\ConfigLoader;
use DeadDrop\DeadDrop\Console\Commands\Concerns\FormatsBytes;
use DeadDrop\DeadDrop\Console\Commands\Concerns\ResolvesArtifactLocation;
use DeadDrop\DeadDrop\Console\Commands\Concerns\ResolvesDumpConnection;
use DeadDrop\DeadDrop\Extraction\ArtifactBuilder;
use DeadDrop\DeadDrop\Extraction\DumpRunner;
use DeadDrop\DeadDrop\Extraction\TableArtifact;
use DeadDrop\DeadDrop\Jobs\DumpDatabase;
use DeadDrop\DeadDrop\Planning\CircularConnectionException;
use DeadDrop\DeadDrop\Planning\ExtractionPlan;
use DeadDrop\DeadDrop\Planning\PlanStep;
use DeadDrop\DeadDrop\Planning\Root;
use DeadDrop\DeadDrop\Planning\UnsupportedTableException;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

final class DumpCommand extends Command
{
    use FormatsBytes;
    use ResolvesArtifactLocation;
    use ResolvesDumpConnection;

    /** @var string */
    protected $signature = 'dead-drop:dump {--connection= : Connection to dump (prompted when several are configured)} {--dry-run : Plan only, extract nothing} {--queue : Dispatch the dump to a queue worker} {--disk= : Disk to write the artifact to (defaults to dead-drop.disk)} {--path= : Config directory}';

    /** @var string */
    protected $description = 'Dump every data and lookup table of a connection as a redacted artifact';

    public function handle(ConfigLoader $loader, DumpRunner $runner, ArtifactBuilder $builder): int
    {
        if ($this->option('queue') && $this->option('dry-run')) {
            $this->error('--queue cannot be combined with --dry-run.');

            return self::FAILURE;
        }

        try {
            $config = $loader->loadAll($this->configDirectory());
        } catch (InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $root = $this->dumpRoot($config);

        if ($root === null) {
            return self::FAILURE;
        }

        if ($this->option('queue')) {
            return $this->enqueue($root, $builder);
        }

        $dryRun = $this->planOnly($this->artifactDisk(), $this->artifactPath());

        try {
            $result = $runner->run($root, $config, $this->artifactDisk(), $this->artifactPath(), $dryRun, progress: function (TableArtifact $artifact): void {
                $this->line("  {$artifact->connection}.{$artifact->table} … {$artifact->rows} rows");
            });
        } catch (UnsupportedTableException|CircularConnectionException|InvalidArgumentException|RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        if ($dryRun || $result->violations === []) {
            $this->reportPlan($result->plan);
        }

        if ($result->violations !== []) {
            $this->error('This dump would be refused:');

            foreach ($result->violations as $violation) {
                $this->error($violation);
            }

            return self::FAILURE;
        }

        $manifest = $result->manifest;

        if ($manifest !== null) {
            $this->info("Artifact: {$this->artifactDisk()}:{$this->artifactPath()}/{$manifest->id}");
        } elseif ($this->plannedByChoice) {
            $this->line('Planned only; nothing was written.');
        }

        return self::SUCCESS;
    }

    private function enqueue(Root $root, ArtifactBuilder $builder): int
    {
        $connection = (string) (config('dead-drop.queue.connection') ?? config('queue.default'));
        $driver = config("queue.connections.{$connection}.driver");
        $timeout = (int) config('dead-drop.queue.timeout', 3600);
        $retryAfter = config("queue.connections.{$connection}.retry_after");

        if (in_array($driver, ['database', 'redis', 'beanstalkd'], true)) {
            $retryAfter ??= 90;
        }

        if (! is_string($driver) || in_array($driver, ['sync', 'null', 'deferred', 'background', 'failover'], true)) {
            $this->error('Choose a durable asynchronous queue connection using dead-drop.queue.connection.');

            return self::FAILURE;
        }

        if ($timeout < 1 || (is_numeric($retryAfter) && (int) $retryAfter <= $timeout)) {
            $this->error('dead-drop.queue.timeout must be positive and shorter than the queue connection retry_after.');

            return self::FAILURE;
        }

        $job = null;

        try {
            $manifest = $builder->reserve($root, Storage::disk($this->artifactDisk()), $this->artifactPath());
            $job = new DumpDatabase($manifest->id, $this->configDirectory(), $this->artifactDisk(), $this->artifactPath(), $timeout);
            Bus::dispatch($job->onConnection($connection)->onQueue((string) config('dead-drop.queue.name', 'dead-drop')));
        } catch (Throwable $e) {
            $job?->failed($e);
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info("Queued artifact: {$this->artifactDisk()}:{$this->artifactPath()}/{$manifest->id}");

        return self::SUCCESS;
    }

    private function reportPlan(ExtractionPlan $plan): void
    {
        $this->table(
            ['Connection', 'Table', 'Rows', 'Est. size'],
            array_map(
                fn (PlanStep $step): array => [$step->connection, $step->table, (string) $step->rows, $this->size($step->estimatedBytes)],
                $plan->steps,
            ),
        );

        $this->line("Total rows: {$plan->totalRows()}");
        $this->line('Estimated size: '.$this->size($plan->estimatedBytes()));
        $this->line('Unresolved references ('.count($plan->unresolved).'):');

        foreach ($plan->unresolved as $reference) {
            $this->line("  - {$reference->connection}.{$reference->table}.{$reference->column} — {$reference->reason}");
        }
    }

    private function artifactPath(): string
    {
        return (string) config('dead-drop.path');
    }

    private function configDirectory(): string
    {
        $path = $this->option('path');

        if (is_string($path) && $path !== '') {
            return $path;
        }

        return config_path((string) config('dead-drop.config_path'));
    }
}

<?php

declare(strict_types=1);

namespace DeadDrop\DeadDrop\Console\Commands;

use DeadDrop\DeadDrop\Artifacts\ArtifactReader;
use DeadDrop\DeadDrop\Artifacts\Manifest;
use DeadDrop\DeadDrop\Console\Commands\Concerns\ResolvesArtifactLocation;
use DeadDrop\DeadDrop\Console\Commands\Concerns\ResolvesPullTarget;
use DeadDrop\DeadDrop\Loading\PullReport;
use DeadDrop\DeadDrop\Loading\PullRunner;
use Illuminate\Console\Command;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

use function Laravel\Prompts\confirm;

final class PullCommand extends Command
{
    use ResolvesArtifactLocation;
    use ResolvesPullTarget;

    /** @var string */
    protected $signature = 'dead-drop:pull {id? : Artifact id (prompted when omitted)} {--connection= : Target connection (prompted when omitted; defaults to the app default connection)} {--disk= : Disk holding artifacts (defaults to dead-drop.disk)} {--path= : Path on the disk (defaults to dead-drop.path)} {--force : Skip the confirmation}';

    /** @var string */
    protected $description = 'Load a dump artifact into a local or staging database';

    public function handle(PullRunner $runner): int
    {
        $allowed = array_values(array_map('strval', (array) config('dead-drop.pull.allow_environments')));

        if (! $this->laravel->environment($allowed)) {
            $environment = (string) $this->laravel->environment();

            $this->error("dead-drop:pull refuses to run in the [{$environment}] environment; allowed: ".($allowed === [] ? 'none' : implode(', ', $allowed)));

            return self::FAILURE;
        }

        $disk = $this->artifactDisk();
        $path = $this->artifactPath();
        $reader = new ArtifactReader(Storage::disk($disk), $path);

        try {
            $manifest = $this->resolveManifest($reader, $disk, $path);
        } catch (InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        if ($manifest === null) {
            return self::FAILURE;
        }

        $target = $this->resolveTarget();

        if ($target === null) {
            return self::FAILURE;
        }

        $this->warnWhenTargetIsSource($manifest, $target);

        if ($this->option('force') !== true) {
            if (! $this->input->isInteractive()) {
                $this->error('Pass --force to load without confirmation when running non-interactively.');

                return self::FAILURE;
            }

            if (! $this->confirmReplacement($manifest, $target)) {
                $this->info('Aborted.');

                return self::SUCCESS;
            }
        }

        try {
            $report = $runner->run($manifest, $reader, $target, function (string $key, int $rows): void {
                $this->line("  {$key} … {$rows} rows");
            });
        } catch (RuntimeException|QueryException|InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        // Report the completed load before running hooks that may fail.
        $this->summarise($report, $manifest->id);

        if (! $this->runAfterHooks($report)) {
            $this->error('The artifact was loaded; only the after hook failed.');

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    private function summarise(PullReport $report, string $id): void
    {
        $this->info("Loaded {$report->totalRows()} rows into ".count($report->loaded)." tables from artifact [{$id}].");

        foreach ($report->skipped as $skipped) {
            $this->warn("  {$skipped}");
        }
    }

    private function confirmReplacement(Manifest $manifest, string $target): bool
    {
        $tables = count($manifest->tables);
        $label = "Replace {$tables} tables on connection [{$target}] ({$this->describeTarget($target)}) with artifact [{$manifest->id}]?";

        if ($this->targetSharesSourceDatabaseName($manifest, $target)) {
            $label .= ' — same database name as the source';
        }

        return confirm(label: $label, default: false);
    }

    private function runAfterHooks(PullReport $report): bool
    {
        foreach ((array) config('dead-drop.pull.after') as $entry) {
            if (! is_string($entry) || $entry === '') {
                $this->warn('Skipping non-string after hook entry.');

                continue;
            }

            try {
                $exit = $this->invokeAfterHook($entry, $report);
            } catch (Throwable $e) {
                $this->error("After hook [{$entry}] failed: {$e->getMessage()}");

                return false;
            }

            if ($exit !== 0) {
                $this->error("After hook [{$entry}] exited with {$exit}.");

                return false;
            }
        }

        return true;
    }

    /**
     * @throws Throwable when the hook cannot be resolved, is not invokable, or throws
     */
    private function invokeAfterHook(string $entry, PullReport $report): int
    {
        if (! class_exists($entry)) {
            return Artisan::call($entry, [], $this->output);
        }

        $hook = $this->laravel->make($entry);

        if (! is_callable($hook)) {
            throw new RuntimeException('the resolved instance is not invokable.');
        }

        $hook($report);

        return 0;
    }
}

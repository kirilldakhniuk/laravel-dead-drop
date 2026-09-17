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

/**
 * Loads a dump artifact into a target connection, replacing the tables the
 * artifact names and leaving every other table alone.
 *
 * The command guards the environment before it reads anything, picks the
 * artifact, asks once, and then hands the work to `PullRunner`; the loading
 * rules themselves live there.
 */
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
        // The guard comes before the disk is even touched: a pull is a
        // destructive write, and — now that any connection can be its target —
        // the environment is what keeps it off a production database.
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

        $target = $this->resolveTarget($manifest);

        if ($target === null) {
            return self::FAILURE;
        }

        $this->warnWhenTargetIsSource($manifest, $target);

        if (! $this->confirmed($manifest, $target)) {
            $this->info('Aborted.');

            return self::SUCCESS;
        }

        try {
            $report = $runner->run($manifest, $reader, $target, function (string $key, int $rows): void {
                $this->line("  {$key} … {$rows} rows");
            });
        } catch (RuntimeException|QueryException|InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        // The target has already been replaced by the time the hooks run, so
        // the summary is printed first: whatever a hook does next, the
        // operator can see what is now in their database.
        $this->summarise($report, $manifest->id);

        if (! $this->after($report)) {
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

    private function confirmed(Manifest $manifest, string $target): bool
    {
        if ($this->option('force') === true || ! $this->input->isInteractive()) {
            return true;
        }

        $tables = count($manifest->tables);
        $label = "Replace {$tables} tables on connection [{$target}] ({$this->describeConnection($target)}) with artifact [{$manifest->id}]?";

        if ($this->targetSharesSourceDatabaseName($manifest, $target)) {
            $label .= ' — same database name as the source';
        }

        return confirm(label: $label, default: false);
    }

    /**
     * Runs the configured `pull.after` entries, reporting whether they all
     * succeeded. A class-string is resolved and invoked with the report; any
     * other string is an Artisan command.
     */
    private function after(PullReport $report): bool
    {
        foreach ((array) config('dead-drop.pull.after') as $entry) {
            if (! is_string($entry) || $entry === '') {
                $this->warn('Skipping non-string after hook entry.');

                continue;
            }

            try {
                $exit = $this->invoke($entry, $report);
            } catch (Throwable $e) {
                // A misspelled entry reaches Artisan as a command name, a hook
                // class can fail to resolve, and a hook can throw anything at
                // all; none of that is worth a stack trace.
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
     * Runs one hook and reports its exit code: a class-string is resolved from
     * the container and invoked with the report, anything else is an Artisan
     * command, which writes to this command's output rather than into a buffer
     * nobody reads.
     *
     * @throws Throwable when the hook cannot be resolved, is not invokable, or throws
     */
    private function invoke(string $entry, PullReport $report): int
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

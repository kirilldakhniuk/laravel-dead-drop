<?php

declare(strict_types=1);

namespace DeadDrop\DeadDrop\Console\Commands;

use DeadDrop\DeadDrop\Artifacts\ArtifactReader;
use DeadDrop\DeadDrop\Artifacts\Manifest;
use DeadDrop\DeadDrop\Loading\PullReport;
use DeadDrop\DeadDrop\Loading\PullRunner;
use Illuminate\Console\Command;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use RuntimeException;

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
    /** @var string */
    protected $signature = 'dead-drop:pull {id? : Artifact id (defaults to the newest complete one)} {--connection= : Target connection (defaults to the default connection)} {--disk= : Disk holding artifacts} {--path= : Path on the disk} {--force : Skip the confirmation}';

    /** @var string */
    protected $description = 'Load a dump artifact into a local or staging database';

    public function handle(PullRunner $runner): int
    {
        // The guard comes before the disk is even touched: a pull is a
        // destructive write, and the one place it must never happen is the
        // database the artifact came from.
        $allowed = array_values(array_map('strval', (array) config('dead-drop.pull.allow_environments')));

        if (! $this->laravel->environment($allowed)) {
            $environment = (string) $this->laravel->environment();

            $this->error("dead-drop:pull refuses to run in the [{$environment}] environment; allowed: ".implode(', ', $allowed));

            return self::FAILURE;
        }

        $disk = $this->disk();
        $path = $this->path();
        $reader = new ArtifactReader(Storage::disk($disk), $path);

        try {
            $manifest = $this->manifest($reader, $disk, $path);
        } catch (InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        if ($manifest === null) {
            return self::FAILURE;
        }

        $target = $this->target();

        if (! in_array($target, array_keys((array) config('database.connections')), true)) {
            $this->error("Unknown database connection [{$target}].");

            return self::FAILURE;
        }

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

        if (! $this->after($report)) {
            return self::FAILURE;
        }

        $this->info("Loaded {$report->totalRows()} rows into ".count($report->loaded)." tables from artifact [{$manifest->id}].");

        foreach ($report->skipped as $skipped) {
            $this->warn("  {$skipped}");
        }

        return self::SUCCESS;
    }

    /**
     * The artifact to load, or `null` once the reason there is none has been
     * printed.
     *
     * @throws InvalidArgumentException when a named artifact is not on the disk
     */
    private function manifest(ArtifactReader $reader, string $disk, string $path): ?Manifest
    {
        $id = $this->argument('id');
        $manifest = is_string($id) && $id !== ''
            ? $reader->manifest($id)
            : $reader->latestComplete();

        if ($manifest === null) {
            $this->error("No complete artifact found on {$disk}:{$path}.");

            return null;
        }

        // An artifact still marked `writing` is a dump that died halfway, so
        // loading it would replace whole tables with part of a slice.
        if (! $manifest->isComplete()) {
            $this->error("Artifact [{$manifest->id}] is incomplete (status: {$manifest->status}) and cannot be loaded.");

            return null;
        }

        return $manifest;
    }

    private function confirmed(Manifest $manifest, string $target): bool
    {
        if ($this->option('force') === true || ! $this->input->isInteractive()) {
            return true;
        }

        $tables = count($manifest->tables);

        return confirm(
            label: "Replace {$tables} tables on connection [{$target}] with artifact [{$manifest->id}]?",
            default: false,
        );
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
                continue;
            }

            if (class_exists($entry)) {
                $hook = $this->laravel->make($entry);

                if (! is_callable($hook)) {
                    $this->error("After hook [{$entry}] is not invokable.");

                    return false;
                }

                $hook($report);

                continue;
            }

            $exit = Artisan::call($entry);

            if ($exit !== 0) {
                $this->error("After hook [{$entry}] exited with {$exit}.");

                return false;
            }
        }

        return true;
    }

    private function target(): string
    {
        $connection = $this->option('connection');

        return is_string($connection) && $connection !== '' ? $connection : (string) config('database.default');
    }

    private function disk(): string
    {
        $disk = $this->option('disk');

        return is_string($disk) && $disk !== '' ? $disk : (string) config('dead-drop.disk');
    }

    private function path(): string
    {
        $path = $this->option('path');

        return is_string($path) && $path !== '' ? $path : (string) config('dead-drop.path');
    }
}

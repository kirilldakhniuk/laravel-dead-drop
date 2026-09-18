<?php

declare(strict_types=1);

namespace DeadDrop\DeadDrop\Console\Commands;

use DeadDrop\DeadDrop\Artifacts\Manifest;
use DeadDrop\DeadDrop\Config\ConfigLoader;
use DeadDrop\DeadDrop\Config\ConfigSet;
use DeadDrop\DeadDrop\Console\Commands\Concerns\FormatsBytes;
use DeadDrop\DeadDrop\Console\Commands\Concerns\ResolvesArtifactLocation;
use DeadDrop\DeadDrop\Console\Commands\Concerns\ResolvesDumpConnection;
use DeadDrop\DeadDrop\Extraction\ArtifactBuilder;
use DeadDrop\DeadDrop\Extraction\ExtractionGate;
use DeadDrop\DeadDrop\Extraction\TableArtifact;
use DeadDrop\DeadDrop\Planning\CircularConnectionException;
use DeadDrop\DeadDrop\Planning\ExtractionPlan;
use DeadDrop\DeadDrop\Planning\Planner;
use DeadDrop\DeadDrop\Planning\PlanStep;
use DeadDrop\DeadDrop\Planning\Root;
use DeadDrop\DeadDrop\Planning\UnsupportedTableException;
use DeadDrop\DeadDrop\Redaction\RedactionContext;
use DeadDrop\DeadDrop\Schema\DatabaseSchema;
use DeadDrop\DeadDrop\Schema\Introspector;
use DeadDrop\DeadDrop\Schema\SchemaSet;
use Illuminate\Console\Command;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use RuntimeException;

/**
 * Dumps a database whole: every table the reviewed config marks `data` or
 * `lookup`. It plans what the dump contains, puts that plan past the
 * extraction gate, and — unless `--dry-run` — hands it to the executor that
 * writes the redacted artifact.
 *
 * Taking every table whole is referentially complete by construction, so
 * nothing is traversed and there is nothing to narrow: `window`, `exclude`
 * and a root row all scope a traversal, and this command runs none. The
 * planner can still traverse from a single root row; that is not exposed as
 * a command yet.
 *
 * The command composes the config, schema, planning and extraction layers and
 * prints what they answer; it decides nothing about the plan itself.
 */
final class DumpCommand extends Command
{
    use FormatsBytes;
    use ResolvesArtifactLocation;
    use ResolvesDumpConnection;

    /** @var string */
    protected $signature = 'dead-drop:dump {--connection= : Connection to dump (prompted when several are configured)} {--dry-run : Plan only, extract nothing} {--disk= : Disk to write the artifact to (defaults to dead-drop.disk)} {--path= : Config directory}';

    /** @var string */
    protected $description = 'Dump every data and lookup table of a connection as a redacted artifact';

    public function handle(Planner $planner, ConfigLoader $loader, Introspector $introspector, ExtractionGate $gate, ArtifactBuilder $builder, RedactionContext $context): int
    {
        try {
            $config = $loader->loadAll($this->directory());
        } catch (InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $root = $this->dumpRoot($config);

        if ($root === null) {
            return self::FAILURE;
        }

        $dryRun = $this->planOnly($this->artifactDisk(), $this->artifactPath());
        $phase = 'Planning';

        try {
            $schemas = $this->schemas($config, $introspector);
            $plan = $planner->planFull($config, $schemas, $root->scope());

            if ($plan->steps === []) {
                $this->error('Nothing to dump: every table in scope is skipped or missing.');

                return self::FAILURE;
            }

            // The gate runs on a dry run too, as a rehearsal: what a dump
            // would be refused for is exactly what a plan is read to find out.
            $violations = $gate->check($root, $config, $schemas, $context->salt, $this->configuredSalt())->lines();
            $manifest = null;

            if (! $dryRun) {
                if ($violations !== []) {
                    foreach ($violations as $violation) {
                        $this->error($violation);
                    }

                    return self::FAILURE;
                }

                $phase = 'Extraction';
                $manifest = $this->extract($builder, $plan, $root, $config, $schemas, $context);
            }
        } catch (UnsupportedTableException|CircularConnectionException|InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        } catch (QueryException $e) {
            // A table the engine refuses to read — a permission the run does
            // not have, a view behind a broken definition — gets the engine's
            // complaint rather than a stack trace, and the phase that produced
            // it rather than a guess.
            $this->error("{$phase} failed: {$e->getMessage()}");

            return self::FAILURE;
        } catch (RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->report($plan);

        // A refused dry run still prints its plan: the plan is what was asked
        // for, and the violations are what stands between it and one.
        if ($violations !== []) {
            $this->error('This dump would be refused:');

            foreach ($violations as $violation) {
                $this->error($violation);
            }

            return self::FAILURE;
        }

        if ($manifest !== null) {
            $this->info("Artifact: {$this->artifactDisk()}:{$this->artifactPath()}/{$manifest->id}");
        } elseif ($this->plannedByChoice) {
            // An operator who answered the prompt could have meant to dump.
            $this->line('Planned only; nothing was written.');
        }

        return self::SUCCESS;
    }

    private function extract(ArtifactBuilder $builder, ExtractionPlan $plan, Root $root, ConfigSet $config, SchemaSet $schemas, RedactionContext $context): Manifest
    {
        return $builder->build(
            $plan,
            $root,
            // Every table is taken whole, so there is no date to narrow by.
            null,
            $config,
            $schemas,
            $context,
            Storage::disk($this->artifactDisk()),
            $this->artifactPath(),
            function (TableArtifact $artifact): void {
                $this->line("  {$artifact->connection}.{$artifact->table} … {$artifact->rows} rows");
            },
        );
    }

    private function schemas(ConfigSet $config, Introspector $introspector): SchemaSet
    {
        $schemas = [];

        foreach ($config->connections() as $connection) {
            $schemas[$connection] = $introspector->inspect($connection);
        }

        /** @var array<string, DatabaseSchema> $schemas */
        return new SchemaSet($schemas);
    }

    private function report(ExtractionPlan $plan): void
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

    /**
     * The raw `dead-drop.redaction.salt` value, before APP_KEY derivation —
     * an empty string counts as unset, same as `SaltResolver`. Only used to
     * pick the wording of a gate violation; the gate judges the resolved
     * salt on `$context->salt`, not this one.
     */
    private function configuredSalt(): ?string
    {
        $salt = config('dead-drop.redaction.salt');

        return is_string($salt) && $salt !== '' ? $salt : null;
    }

    /**
     * `--path` names this command's config directory, not a location on the
     * artifact disk, so the artifact path is always the configured one and
     * the shared option-reading version is deliberately overridden.
     */
    private function artifactPath(): string
    {
        return (string) config('dead-drop.path');
    }

    private function directory(): string
    {
        $path = $this->option('path');

        if (is_string($path) && $path !== '') {
            return $path;
        }

        return config_path((string) config('dead-drop.config_path'));
    }
}

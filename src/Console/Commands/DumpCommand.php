<?php

declare(strict_types=1);

namespace DeadDrop\DeadDrop\Console\Commands;

use DateMalformedStringException;
use DateTimeImmutable;
use DeadDrop\DeadDrop\Artifacts\Manifest;
use DeadDrop\DeadDrop\Config\ConfigLoader;
use DeadDrop\DeadDrop\Config\ConfigSet;
use DeadDrop\DeadDrop\Console\Commands\Concerns\FormatsBytes;
use DeadDrop\DeadDrop\Console\Commands\Concerns\ResolvesArtifactLocation;
use DeadDrop\DeadDrop\Console\Commands\Concerns\ResolvesDumpRoot;
use DeadDrop\DeadDrop\Extraction\ArtifactBuilder;
use DeadDrop\DeadDrop\Extraction\ExtractionGate;
use DeadDrop\DeadDrop\Extraction\TableArtifact;
use DeadDrop\DeadDrop\Planning\CircularConnectionException;
use DeadDrop\DeadDrop\Planning\ExtractionPlan;
use DeadDrop\DeadDrop\Planning\KeySetRepository;
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
 * Dumps a root row set — or, with `--all`, every dumpable table whole: it
 * plans what the dump contains, puts that plan past the extraction gate, and
 * — unless `--dry-run` — hands it to the executor that writes the redacted
 * artifact.
 *
 * The command composes the config, schema, planning and extraction layers and
 * prints what they answer; it decides nothing about the plan itself.
 */
final class DumpCommand extends Command
{
    use FormatsBytes;
    use ResolvesArtifactLocation;
    use ResolvesDumpRoot;

    /** @var string */
    protected $signature = 'dead-drop:dump {table? : Root table} {ids?* : One or more root row ids} {--connection= : Connection holding the root table} {--all : Dump every data and lookup table whole} {--since= : Only rows on or after this date for windowed tables} {--dry-run : Plan only, extract nothing} {--disk= : Disk to write the artifact to (defaults to dead-drop.disk)} {--path= : Config directory}';

    /** @var string */
    protected $description = 'Dump a redacted, referentially complete slice of a root row set';

    public function handle(Planner $planner, ConfigLoader $loader, Introspector $introspector, KeySetRepository $keys, ExtractionGate $gate, ArtifactBuilder $builder, RedactionContext $context): int
    {
        try {
            $config = $loader->loadAll($this->directory());
            $since = $this->since();
        } catch (InvalidArgumentException|DateMalformedStringException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $root = $this->resolveRoot($config);

        if ($root === null) {
            return self::FAILURE;
        }

        $dryRun = $this->planOnly($this->artifactDisk(), $this->artifactPath());
        $phase = 'Planning';

        try {
            $schemas = $this->schemas($config, $introspector);
            $plan = $root->isFull()
                ? $planner->planFull($config, $schemas, $root->scope())
                : $planner->plan($root, $config, $schemas, $since);

            // A dry run reads nothing out of the tables it plans, so only the
            // root ids have to hold; an extraction has to clear the whole gate.
            $violations = $dryRun
                ? $gate->rootIds($root, $config, $schemas)
                : $gate->check($root, $config, $schemas, $context->salt)->lines();

            if ($violations !== []) {
                foreach ($violations as $violation) {
                    $this->error($violation);
                }

                return self::FAILURE;
            }

            $manifest = null;

            if (! $dryRun) {
                $phase = 'Extraction';
                $manifest = $this->extract($builder, $plan, $root, $since, $config, $schemas, $context);
            }
        } catch (UnsupportedTableException|CircularConnectionException|InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        } catch (QueryException $e) {
            // A hand-written `exclude` fragment or a window column that is not
            // one reaches the database as-is; the operator gets the engine's
            // complaint rather than a stack trace, and the phase that produced
            // it rather than a guess.
            $this->error("{$phase} failed: {$e->getMessage()}");

            return self::FAILURE;
        } catch (RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        } finally {
            // The plan is read out of temporary key tables, and the extraction
            // joins them, so they outlive both and only the caller can clean
            // them up.
            $keys->dropAll();
        }

        $this->report($plan);

        if ($manifest !== null) {
            $this->info("Artifact: {$this->artifactDisk()}:{$this->artifactPath()}/{$manifest->id}");
        }

        return self::SUCCESS;
    }

    private function extract(ArtifactBuilder $builder, ExtractionPlan $plan, Root $root, ?DateTimeImmutable $since, ConfigSet $config, SchemaSet $schemas, RedactionContext $context): Manifest
    {
        return $builder->build(
            $plan,
            $root,
            $since,
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

    /**
     * @throws DateMalformedStringException
     */
    private function since(): ?DateTimeImmutable
    {
        $since = $this->option('since');

        return is_string($since) && $since !== '' ? new DateTimeImmutable($since) : null;
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

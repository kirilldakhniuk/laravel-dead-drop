<?php

declare(strict_types=1);

namespace DeadDrop\DeadDrop\Extraction;

use Closure;
use DeadDrop\DeadDrop\Artifacts\Manifest;
use DeadDrop\DeadDrop\Config\ConfigSet;
use DeadDrop\DeadDrop\Planning\Planner;
use DeadDrop\DeadDrop\Planning\Root;
use DeadDrop\DeadDrop\Redaction\RedactionContext;
use DeadDrop\DeadDrop\Schema\Introspector;
use DeadDrop\DeadDrop\Schema\SchemaSet;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

final class DumpRunner
{
    public function __construct(
        private readonly Planner $planner,
        private readonly Introspector $introspector,
        private readonly ExtractionGate $gate,
        private readonly ArtifactBuilder $builder,
        private readonly SourceConnections $connections,
        private readonly RedactionContext $context,
    ) {}

    /** @param Closure(TableArtifact): void|null $progress */
    public function run(Root $root, ConfigSet $config, string $disk, string $path, bool $dryRun = false, ?Manifest $pending = null, ?Closure $progress = null): DumpResult
    {
        $phase = 'Planning';

        try {
            return $this->connections->snapshot($config, $root->scope(), function () use ($root, $config, $disk, $path, $dryRun, $pending, $progress, &$phase): DumpResult {
                $schemas = [];

                foreach ($config->connections() as $connection) {
                    $schemas[$connection] = $this->introspector->inspect($connection);
                }

                $schemas = new SchemaSet($schemas);
                $plan = $this->planner->planFull($config, $schemas, $root->scope());

                if ($plan->steps === []) {
                    throw new RuntimeException('Nothing to dump: every table in scope is skipped or missing.');
                }

                $salt = config('dead-drop.redaction.salt');
                $violations = $this->gate->check($root, $config, $schemas, $this->context->salt, is_string($salt) && $salt !== '' ? $salt : null)->lines();

                if ($dryRun || $violations !== []) {
                    return new DumpResult($plan, $violations, null);
                }

                $phase = 'Extraction';
                $manifest = $this->builder->build($plan, $root, null, $config, $schemas, $this->context, Storage::disk($disk), $path, $progress, $pending);

                return new DumpResult($plan, [], $manifest);
            });
        } catch (QueryException $e) {
            // Omit SQL bindings: they can contain row data.
            $message = explode(' (Connection:', $e->getMessage(), 2)[0];

            throw new RuntimeException("{$phase} failed: {$message}", previous: $e);
        }
    }
}

<?php

declare(strict_types=1);

namespace DeadDrop\DeadDrop\Extraction;

use DeadDrop\DeadDrop\Config\ConfigSet;
use DeadDrop\DeadDrop\Config\DriftDetector;
use DeadDrop\DeadDrop\Config\TableClass;
use DeadDrop\DeadDrop\Planning\Root;
use DeadDrop\DeadDrop\Redaction\RedactionRules;
use DeadDrop\DeadDrop\Schema\SchemaSet;
use Illuminate\Support\Facades\DB;

/**
 * The fail-closed check that decides whether a dump may start: schema drift,
 * a missing or weak redaction salt, invalid redaction placements, and root
 * ids that do not exist. Every category runs and every violation is
 * collected, so an operator sees everything wrong in one pass rather than
 * fixing issues one at a time.
 */
final class ExtractionGate
{
    public function __construct(
        private readonly DriftDetector $drift,
        private readonly RedactionRules $rules,
    ) {}

    /**
     * @param  ?string  $configuredSalt  the raw `dead-drop.redaction.salt` value, before APP_KEY derivation —
     *                                   used only to choose the wording of a salt violation, never its own length.
     */
    public function check(Root $root, ConfigSet $config, SchemaSet $schemas, ?string $salt, ?string $configuredSalt = null): GateReport
    {
        $lines = [];

        foreach ($config->connections() as $connection) {
            $report = $this->drift->detect($schemas->for($connection), $config->for($connection));

            if ($report->hasDrift()) {
                foreach ($report->toLines() as $line) {
                    $lines[] = "{$connection}: {$line}";
                }
            }
        }

        if ($salt === null || strlen($salt) < 16) {
            $lines[] = $configuredSalt !== null && $configuredSalt !== ''
                ? 'redaction.salt must be at least 16 characters (DEAD_DROP_REDACTION_SALT)'
                : 'redaction.salt is not set and APP_KEY is empty; set DEAD_DROP_REDACTION_SALT (generate one with: openssl rand -hex 16)';
        }

        foreach ($config->connections() as $connection) {
            $connectionConfig = $config->for($connection);
            $schema = $schemas->for($connection);

            foreach ($connectionConfig->tables as $tableConfig) {
                if ($tableConfig->class === TableClass::Skip || $tableConfig->removed) {
                    continue;
                }

                $table = $schema->table($tableConfig->name);

                if ($table === null) {
                    continue;
                }

                array_push($lines, ...$this->rules->violations($tableConfig, $table));
            }
        }

        array_push($lines, ...$this->rootIds($root, $config, $schemas));

        return new GateReport($lines);
    }

    /**
     * The root ids the source does not have. A whole-database dump starts
     * from no row at all, so there is nothing to look for and no query to
     * run — every other category still applies to it.
     *
     * @return list<string>
     */
    public function rootIds(Root $root, ConfigSet $config, SchemaSet $schemas): array
    {
        if ($root->isFull()) {
            return [];
        }

        $schema = $schemas->for($root->connection)->table($root->table);
        $pk = $schema?->primaryKey();

        if ($schema === null || $pk === null) {
            return ["Root table {$root->connection}.{$root->table} has no single primary key"];
        }

        $found = DB::connection($root->connection)
            ->table($root->table)
            ->whereIn($pk, $root->ids)
            ->pluck($pk)
            ->map(fn (mixed $v): string => (string) $v)
            ->all();

        $lines = [];

        foreach ($root->ids as $id) {
            if (! in_array((string) $id, $found, true)) {
                $lines[] = "Root id {$id} does not exist in {$root->connection}.{$root->table}";
            }
        }

        return $lines;
    }
}

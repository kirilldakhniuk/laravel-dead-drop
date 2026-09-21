<?php

declare(strict_types=1);

namespace DeadDrop\DeadDrop\Extraction;

use DeadDrop\DeadDrop\Artifacts\ArtifactWriter;
use DeadDrop\DeadDrop\Config\TableConfig;
use DeadDrop\DeadDrop\Planning\KeySet;
use DeadDrop\DeadDrop\Planning\PlanStep;
use DeadDrop\DeadDrop\Redaction\Redactor;
use DeadDrop\DeadDrop\Schema\Table;

/**
 * Moves the rows of one plan step out of the source database and into an
 * artifact file. The redactor is built by the caller from the reviewed
 * config, so an executor never decides what a column becomes — it only has
 * to apply what it is handed, or reproduce the same semantics itself.
 */
interface Executor
{
    public function name(): string;

    /**
     * Whether this executor can read from a connection of the given Laravel
     * driver ('mysql', 'mariadb', 'pgsql', 'sqlite').
     */
    public function supports(string $connectionDriver): bool;

    /**
     * A null key set means the whole table is taken, which is how a
     * whole-database dump takes every table.
     */
    public function export(PlanStep $step, Table $table, TableConfig $config, ?KeySet $keys, Redactor $redactor, ArtifactWriter $writer): TableArtifact;
}

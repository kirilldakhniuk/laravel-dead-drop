<?php

declare(strict_types=1);

namespace DeadDrop\DeadDrop\Inference;

use DeadDrop\DeadDrop\Config\TableClass;
use DeadDrop\DeadDrop\Schema\Table;

/**
 * Decides how a table should be treated by a dump: `skip` for framework
 * bookkeeping tables, `lookup` for small reference tables that point at
 * nothing and hold nothing personal, `data` for everything else.
 *
 * Reference tables can be copied whole because they carry no relationships
 * to scope and no personal data to redact. Transaction tables always point
 * at something and must be scoped rather than copied; a table holding
 * personal data must be scoped and redacted rather than copied wholesale.
 */
final class TableClassifier
{
    /**
     * Exact table names that are always skipped, regardless of size or
     * relationships.
     *
     * @var list<string>
     */
    private const array SKIP_TABLES = [
        'migrations',
        'jobs',
        'job_batches',
        'failed_jobs',
        'cache',
        'cache_locks',
        'sessions',
        'password_reset_tokens',
        'password_resets',
        'personal_access_tokens',
    ];

    /**
     * Table name prefixes that are always skipped.
     *
     * @var list<string>
     */
    private const array SKIP_PREFIXES = [
        'telescope_',
        'pulse_',
    ];

    /**
     * A lookup table must be smaller than this many rows.
     */
    private const int LOOKUP_ROW_LIMIT = 10_000;

    public function __construct(
        private readonly SensitiveColumnDetector $sensitive,
        private readonly MorphPairDetector $morphs,
    ) {}

    /**
     * @param  array<string, InferredEdge>  $edges
     */
    public function classify(Table $table, array $edges): TableClass
    {
        if ($this->isSkipped($table->name)) {
            return TableClass::Skip;
        }

        if ($this->isLookup($table, $edges)) {
            return TableClass::Lookup;
        }

        return TableClass::Data;
    }

    /**
     * @param  array<string, InferredEdge>  $edges
     */
    private function isLookup(Table $table, array $edges): bool
    {
        if ($this->hasOutboundEdge($table, $edges)) {
            return false;
        }

        if ($this->morphs->detect($table) !== null) {
            return false;
        }

        if ($this->sensitive->detect($table) !== []) {
            return false;
        }

        return $table->estimatedRows < self::LOOKUP_ROW_LIMIT;
    }

    /**
     * @param  array<string, InferredEdge>  $edges
     */
    private function hasOutboundEdge(Table $table, array $edges): bool
    {
        foreach ($edges as $edge) {
            if ($edge->table === $table->name) {
                return true;
            }
        }

        return false;
    }

    private function isSkipped(string $table): bool
    {
        if (in_array($table, self::SKIP_TABLES, true)) {
            return true;
        }

        foreach (self::SKIP_PREFIXES as $prefix) {
            if (str_starts_with($table, $prefix)) {
                return true;
            }
        }

        return false;
    }
}

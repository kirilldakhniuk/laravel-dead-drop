<?php

declare(strict_types=1);

namespace DeadDrop\DeadDrop\Inference;

use DeadDrop\DeadDrop\Config\TableClass;
use DeadDrop\DeadDrop\Schema\Table;

/**
 * Decides how a table should be treated by a dump: `skip` for the framework
 * bookkeeping tables a dump has no business carrying, `data` for everything
 * else. A human reviewing the generated config skips whatever else they do
 * not want; this only names the ones nobody ever does.
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
        'spatial_ref_sys',
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

    public function classify(Table $table): TableClass
    {
        return $this->isSkipped($table->name) ? TableClass::Skip : TableClass::Data;
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

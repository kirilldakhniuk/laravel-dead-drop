<?php

declare(strict_types=1);

namespace DeadDrop\DeadDrop\Config;

/**
 * The outcome of comparing a connection's live schema against its reviewed
 * config: what changed, and which sensitive columns still need a human
 * decision.
 */
final readonly class DriftReport
{
    /**
     * @param  list<string>  $newTables  schema tables missing from the config
     * @param  list<string>  $removedTables  config tables gone from the schema
     * @param  list<string>  $newColumns  `table.column` entries present in the schema but not the config
     * @param  list<string>  $removedColumns  `table.column` entries present in the config but not the schema
     * @param  list<string>  $undecidedColumns  `table.column` entries with no final redaction decision
     */
    public function __construct(
        public array $newTables,
        public array $removedTables,
        public array $newColumns,
        public array $removedColumns,
        public array $undecidedColumns,
    ) {}

    public function hasDrift(): bool
    {
        return $this->newTables !== []
            || $this->removedTables !== []
            || $this->newColumns !== []
            || $this->removedColumns !== []
            || $this->undecidedColumns !== [];
    }

    /**
     * @return list<string>
     */
    public function toLines(): array
    {
        $lines = [];

        $this->appendSection($lines, 'New tables (not in config):', $this->newTables);
        $this->appendSection($lines, 'Removed tables (in config, gone from schema):', $this->removedTables);
        $this->appendSection($lines, 'New columns (not in config):', $this->newColumns);
        $this->appendSection($lines, 'Removed columns (in config, gone from schema):', $this->removedColumns);
        $this->appendSection($lines, 'Undecided sensitive columns (add a redact entry):', $this->undecidedColumns);

        return $lines;
    }

    /**
     * @param  list<string>  $lines
     * @param  list<string>  $items
     */
    private function appendSection(array &$lines, string $heading, array $items): void
    {
        if ($items === []) {
            return;
        }

        $lines[] = $heading;

        foreach ($items as $item) {
            $lines[] = "  - {$item}";
        }
    }
}

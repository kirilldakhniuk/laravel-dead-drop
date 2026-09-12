<?php

declare(strict_types=1);

namespace DeadDrop\DeadDrop\Loading;

/**
 * What a pull did: every table it replaced with the rows it wrote, in load
 * order, and every table it passed over because the target does not have it.
 */
final readonly class PullReport
{
    /**
     * @param  array<string, int>  $loaded  `"{connection}.{table}"` => rows written, in load order
     * @param  list<string>  $skipped  one sentence per table the target does not have
     */
    public function __construct(
        public array $loaded,
        public array $skipped,
    ) {}

    public function totalRows(): int
    {
        return array_sum($this->loaded);
    }
}

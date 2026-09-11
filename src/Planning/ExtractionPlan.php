<?php

declare(strict_types=1);

namespace DeadDrop\DeadDrop\Planning;

/**
 * A traversal turned into the ordered work a dump would do: every table with
 * rows to take, in an order that writes a referenced table before the tables
 * referencing it, plus the references the traversal could not follow.
 */
final readonly class ExtractionPlan
{
    /**
     * @param  list<PlanStep>  $steps
     * @param  list<UnresolvedReference>  $unresolved
     */
    public function __construct(
        public array $steps,
        public array $unresolved,
    ) {}

    public function totalRows(): int
    {
        return array_sum(array_map(fn (PlanStep $step): int => $step->rows, $this->steps));
    }

    public function estimatedBytes(): int
    {
        return array_sum(array_map(fn (PlanStep $step): int => $step->estimatedBytes, $this->steps));
    }
}

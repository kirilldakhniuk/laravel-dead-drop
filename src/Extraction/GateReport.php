<?php

declare(strict_types=1);

namespace DeadDrop\DeadDrop\Extraction;

/**
 * The outcome of `ExtractionGate::check()`: every violation found, across
 * every category, in the order the gate checked them. Empty means the dump
 * may proceed.
 */
final readonly class GateReport
{
    /**
     * @param  list<string>  $lines
     */
    public function __construct(
        public array $lines,
    ) {}

    public function passes(): bool
    {
        return $this->lines === [];
    }

    /**
     * @return list<string>
     */
    public function lines(): array
    {
        return $this->lines;
    }
}

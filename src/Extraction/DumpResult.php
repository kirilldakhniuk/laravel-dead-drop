<?php

declare(strict_types=1);

namespace DeadDrop\DeadDrop\Extraction;

use DeadDrop\DeadDrop\Artifacts\Manifest;
use DeadDrop\DeadDrop\Planning\ExtractionPlan;

final readonly class DumpResult
{
    /** @param list<string> $violations */
    public function __construct(
        public ExtractionPlan $plan,
        public array $violations,
        public ?Manifest $manifest,
    ) {}
}

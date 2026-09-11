<?php

declare(strict_types=1);

namespace DeadDrop\DeadDrop\Planning;

/**
 * What a traversal collected: a key set per table, plus every reference it
 * could not follow.
 */
final readonly class TraversalResult
{
    /**
     * @param  array<string, KeySet>  $keySets  keyed "{connection}.{table}"
     * @param  list<UnresolvedReference>  $unresolved
     */
    public function __construct(
        private array $keySets,
        private array $unresolved,
    ) {}

    /** @return array<string, KeySet> keyed "{connection}.{table}" */
    public function keySets(): array
    {
        return $this->keySets;
    }

    /** @return list<UnresolvedReference> */
    public function unresolved(): array
    {
        return $this->unresolved;
    }
}

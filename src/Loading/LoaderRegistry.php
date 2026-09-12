<?php

declare(strict_types=1);

namespace DeadDrop\DeadDrop\Loading;

use InvalidArgumentException;

/**
 * The loaders this installation can read artifacts with, keyed by the format
 * each one names. An artifact written by a newer package version can carry a
 * format this one has no loader for, so the lookup refuses by name instead of
 * guessing.
 */
final class LoaderRegistry
{
    /** @var array<string, Loader> */
    private array $loaders = [];

    /**
     * @param  iterable<int, Loader>  $loaders
     */
    public function __construct(iterable $loaders)
    {
        foreach ($loaders as $loader) {
            $this->loaders[$loader->format()] = $loader;
        }
    }

    public function for(string $format): Loader
    {
        return $this->loaders[$format]
            ?? throw new InvalidArgumentException("No loader for artifact format [{$format}].");
    }
}

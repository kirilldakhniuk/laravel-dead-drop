<?php

declare(strict_types=1);

namespace DeadDrop\DeadDrop\Console\Commands\Concerns;

use DateTimeImmutable;
use DateTimeInterface;
use DeadDrop\DeadDrop\Artifacts\ArtifactReader;
use DeadDrop\DeadDrop\Artifacts\Manifest;
use DeadDrop\DeadDrop\Planning\Root;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Throwable;

use function Laravel\Prompts\search;
use function Laravel\Prompts\select;

trait ResolvesPullTarget
{
    private const int ARTIFACT_CHOICE_LIMIT = 15;

    /**
     * @throws InvalidArgumentException when a named artifact is not on the disk
     */
    private function resolveManifest(ArtifactReader $reader, string $disk, string $path): ?Manifest
    {
        $id = $this->argument('id');

        if (is_string($id) && $id !== '') {
            return $this->loadable($reader->manifest($id));
        }

        if (! $this->input->isInteractive()) {
            return $reader->latestComplete() ?? $this->noArtifact($disk, $path);
        }

        return $this->chooseArtifact($reader, $disk, $path);
    }

    private function loadable(Manifest $manifest): ?Manifest
    {
        if (! $manifest->isComplete()) {
            $this->error("Artifact [{$manifest->id}] is incomplete (status: {$manifest->status}) and cannot be loaded.");

            return null;
        }

        return $manifest;
    }

    private function noArtifact(string $disk, string $path): ?Manifest
    {
        $this->error("No complete artifact found on {$disk}:{$path}. Run dead-drop:dump first.");

        return null;
    }

    private function chooseArtifact(ArtifactReader $reader, string $disk, string $path): ?Manifest
    {
        $complete = [];
        $incomplete = 0;
        $unreadable = 0;

        foreach ($reader->ids() as $id) {
            try {
                $manifest = $reader->manifest($id);
            } catch (Throwable) {
                $unreadable++;

                continue;
            }

            if ($manifest->isComplete()) {
                $complete[$id] = $manifest;

                continue;
            }

            $incomplete++;
        }

        if ($incomplete > 0) {
            $this->line("<comment>{$incomplete} incomplete artifact(s) not offered.</comment>");
        }

        if ($unreadable > 0) {
            $this->line("<comment>{$unreadable} unreadable artifact(s) not offered.</comment>");
        }

        if ($complete === []) {
            return $this->noArtifact($disk, $path);
        }

        if (count($complete) === 1) {
            $only = reset($complete);
            $this->line("Using artifact [{$only->id}].");

            return $only;
        }

        $options = array_map(fn (Manifest $manifest): string => $this->describeArtifact($manifest), $complete);
        $label = 'Which artifact should be loaded?';
        $ids = array_keys($options);

        $chosen = count($options) <= self::ARTIFACT_CHOICE_LIMIT
            ? (string) select(label: $label, options: $options, default: (string) $ids[0])
            : (string) search(label: $label, options: fn (string $value): array => $this->matchingArtifacts($options, $value));

        if (! isset($complete[$chosen])) {
            $this->error("Artifact [{$chosen}] is no longer available.");

            return null;
        }

        return $complete[$chosen];
    }

    /**
     * @param  array<array-key, string>  $options  label keyed by artifact id
     * @return array<array-key, string>
     */
    private function matchingArtifacts(array $options, string $value): array
    {
        if ($value === '') {
            return $options;
        }

        $matching = array_filter(
            $options,
            fn (string $label, int|string $id): bool => str_contains(strtolower("{$id} {$label}"), strtolower($value)),
            ARRAY_FILTER_USE_BOTH,
        );

        return $matching === [] ? $options : $matching;
    }

    private function describeArtifact(Manifest $manifest): string
    {
        $created = DateTimeImmutable::createFromFormat(DateTimeInterface::ATOM, $manifest->createdAt);
        $when = $created === false ? $manifest->createdAt : $created->format('Y-m-d H:i');

        return $this->describeRoot($manifest->root)." · {$when} · {$manifest->totalRows()} rows · ".count($manifest->tables).' tables';
    }

    private function describeRoot(string $spec): string
    {
        try {
            return Root::parse($spec)->describe();
        } catch (InvalidArgumentException) {
            return $spec;
        }
    }

    private function resolveTarget(): ?string
    {
        $configured = array_map('strval', array_keys((array) config('database.connections')));

        $named = $this->option('connection');

        if (is_string($named) && $named !== '') {
            if (! in_array($named, $configured, true)) {
                $this->error("Unknown database connection [{$named}].");

                return null;
            }

            return $named;
        }

        $default = (string) config('database.default');

        if (! $this->input->isInteractive()) {
            if (! in_array($default, $configured, true)) {
                $this->error("Unknown database connection [{$default}].");

                return null;
            }

            return $default;
        }

        $options = [];

        foreach ($configured as $candidate) {
            $options[$candidate] = "{$candidate} ({$this->describeConnection($candidate)})";
        }

        return (string) select(
            label: 'Which connection should receive the data?',
            options: $options,
            default: in_array($default, $configured, true) ? $default : null,
        );
    }

    private function warnWhenTargetIsSource(Manifest $manifest, string $target): void
    {
        if (array_key_exists($target, $manifest->connections)) {
            $this->warn('This is the connection the artifact was dumped from; its rows will be replaced by their redacted copies.');
        }
    }

    private function targetSharesSourceDatabaseName(Manifest $manifest, string $target): bool
    {
        $source = $manifest->connections[$target]['database'] ?? null;

        if ($source === null) {
            return false;
        }

        $connection = DB::connection($target);
        $database = $connection->getDatabaseName();

        // Match the basename stored for SQLite sources in the manifest.
        return $source === ($connection->getDriverName() === 'sqlite' ? basename($database) : $database);
    }

    private function describeTarget(string $name): string
    {
        $connection = DB::connection($name);
        $driver = $connection->getDriverName();
        $database = $connection->getDatabaseName();

        return $database === '' ? $driver : "{$driver}: {$database}";
    }

    private function describeConnection(string $name): string
    {
        $driver = $this->connectionValue($name, 'driver');
        $database = $this->connectionValue($name, 'database');

        return $database === '' ? $driver : "{$driver}: {$database}";
    }

    private function connectionValue(string $name, string $key): string
    {
        $value = config("database.connections.{$name}.{$key}");

        return is_scalar($value) ? (string) $value : '';
    }
}

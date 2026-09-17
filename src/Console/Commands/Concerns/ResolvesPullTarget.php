<?php

declare(strict_types=1);

namespace DeadDrop\DeadDrop\Console\Commands\Concerns;

use DateTimeImmutable;
use DateTimeInterface;
use DeadDrop\DeadDrop\Artifacts\ArtifactReader;
use DeadDrop\DeadDrop\Artifacts\Manifest;
use DeadDrop\DeadDrop\Planning\Root;
use InvalidArgumentException;
use Throwable;

use function Laravel\Prompts\search;
use function Laravel\Prompts\select;

/**
 * Turns `dead-drop:pull`'s artifact argument and `--connection` option into
 * the two things a load needs — the artifact and the connection to replace —
 * asking for whatever the operator left out.
 *
 * The same two rules as the dump side hold here: anything given on the command
 * line is never asked for, and nothing at all is asked for when the command is
 * not interactive, where a missing piece fails with the argument to pass
 * instead. Any configured connection can be the target, including one the
 * artifact was dumped from: dumping on production over `mysql` and loading
 * into a laptop's `mysql` is the workflow this command exists for, and a
 * connection name says nothing about which database is behind it. What keeps
 * the load off the database it came from is the environment guard and the
 * confirmation, not the name.
 */
trait ResolvesPullTarget
{
    /**
     * Above this many artifacts a list is worse than a search box.
     */
    private const int ARTIFACT_CHOICE_LIMIT = 15;

    /**
     * The artifact to load, or `null` once the reason there is none has been
     * printed.
     *
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

    /**
     * An artifact still marked `writing` is a dump that died halfway, so
     * loading it would replace whole tables with part of a slice.
     */
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

    /**
     * The artifact an operator picks from the disk, newest first. Only
     * complete ones are offered — an incomplete artifact is not a choice
     * anyone should be able to make — but they are counted, so a disk whose
     * newest dump crashed does not look like a disk that lost it.
     */
    private function chooseArtifact(ArtifactReader $reader, string $disk, string $path): ?Manifest
    {
        $complete = [];
        $incomplete = 0;
        $unreadable = 0;

        foreach ($reader->ids() as $id) {
            try {
                $manifest = $reader->manifest($id);
            } catch (Throwable) {
                // One unreadable manifest is a fact about that artifact, not
                // a reason to stop offering the rest — and not the same fact
                // as a dump that died halfway, so it is counted apart.
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

        // One artifact is not a question. It is still named, because a run
        // that was not asked which artifact it loads should still say.
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
            // Nothing should be able to answer with an id that was not
            // offered, but a load that cannot say which artifact it would
            // read must say so rather than fail silently.
            $this->error("Artifact [{$chosen}] is no longer available.");

            return null;
        }

        return $complete[$chosen];
    }

    /**
     * The offered artifacts matching what has been typed so far. The id is
     * searched along with the label: the root, the date and the size are what
     * an operator remembers, and the id is what `dead-drop:dumps` printed for
     * them to paste.
     *
     * A search that matches nothing falls back to the whole list, because the
     * fallback prompt a non-TTY run gets is a Symfony choice question, and one
     * with no choices left is an exception rather than an empty list.
     *
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

    /**
     * An artifact the way an operator would recognise it: what was dumped,
     * when, and how much of it — the id alone is a timestamp and six random
     * characters.
     */
    private function describeArtifact(Manifest $manifest): string
    {
        $created = DateTimeImmutable::createFromFormat(DateTimeInterface::ATOM, $manifest->createdAt);
        $when = $created === false ? $manifest->createdAt : $created->format('Y-m-d H:i');

        return $this->describeRoot($manifest->root)." · {$when} · {$manifest->totalRows()} rows · ".count($manifest->tables).' tables';
    }

    /**
     * The manifest stores the root as the spec the planner reads; a listing
     * shows it the way `dead-drop:dump` is typed. A spec no longer in that
     * shape is still shown as it stands rather than dropping the artifact.
     */
    private function describeRoot(string $spec): string
    {
        try {
            return Root::parse($spec)->describe();
        } catch (InvalidArgumentException) {
            return $spec;
        }
    }

    /**
     * The connection to load into, or `null` once the reason there is none has
     * been printed. Every configured connection is a candidate — the artifact
     * names the connections it came from, but a name is not a database, and
     * the local copy of a production app is usually configured under the same
     * one.
     */
    private function resolveTarget(Manifest $manifest): ?string
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

    /**
     * Says so, once the target is known, when the artifact was dumped from
     * that same connection. It is not a refusal — it is the normal way to
     * refresh a laptop — but the rows about to be deleted are the rows the
     * artifact was taken from, now redacted, and that is worth reading before
     * the confirmation.
     */
    private function warnWhenTargetIsSource(Manifest $manifest, string $target): void
    {
        if (array_key_exists($target, $manifest->connections)) {
            $this->warn('This is the connection the artifact was dumped from; its rows will be replaced by their redacted copies.');
        }
    }

    /**
     * Whether the target is a source connection of the artifact pointing at a
     * database of the same name as the dump read from. A name match on both
     * the connection and the database is as close as an artifact can get to
     * saying "this may be the very database you dumped", so the confirmation
     * says it — and still only asks.
     */
    private function targetSharesSourceDatabaseName(Manifest $manifest, string $target): bool
    {
        $database = $manifest->connections[$target]['database'] ?? null;

        return $database !== null && $database === $this->connectionValue($target, 'database');
    }

    /**
     * What a connection actually points at, so a name like `local_copy` is not
     * the only thing standing between an operator and the wrong database.
     */
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

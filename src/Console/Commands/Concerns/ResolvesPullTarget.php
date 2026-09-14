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
 * the two things a load needs — the artifact and a connection that is not one
 * of its sources — asking for whatever the operator left out.
 *
 * The same two rules as the dump side hold here: anything given on the command
 * line is never asked for, and nothing at all is asked for when the command is
 * not interactive, where a missing piece fails with the argument to pass
 * instead. Every refusal names what to do next, because the commonest way to
 * meet this command is a single-connection app whose only connection is the
 * one the artifact came from.
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
     * been printed. Loading a slice back into the database it was taken from
     * would replace whole tables with the part of themselves the dump carried,
     * and no environment guard can catch that — the source connections are
     * named in the artifact, so they are read from there and left out.
     */
    private function resolveTarget(Manifest $manifest): ?string
    {
        $configured = array_map('strval', array_keys((array) config('database.connections')));
        $candidates = array_values(array_filter(
            $configured,
            fn (string $name): bool => ! array_key_exists($name, $manifest->connections),
        ));

        $named = $this->option('connection');

        if (is_string($named) && $named !== '') {
            return $this->namedTarget($named, $manifest, $configured, $candidates);
        }

        if ($candidates === []) {
            $this->nowhereSafe($manifest, $configured);

            return null;
        }

        $default = (string) config('database.default');

        if (! $this->input->isInteractive()) {
            if (in_array($default, $candidates, true)) {
                return $default;
            }

            $this->error("The default connection [{$default}] is a source of this artifact; pass --connection=<name>. Candidates: ".implode(', ', $candidates).'.');

            return null;
        }

        $options = [];

        foreach ($candidates as $candidate) {
            $options[$candidate] = "{$candidate} ({$this->describeConnection($candidate)})";
        }

        return (string) select(
            label: 'Which connection should receive the data?',
            options: $options,
            default: in_array($default, $candidates, true) ? $default : null,
        );
    }

    /**
     * A target the operator named. Both refusals carry the way out: the
     * connections this artifact can be loaded into, or — when there are none —
     * how to make one.
     *
     * @param  list<string>  $configured
     * @param  list<string>  $candidates
     */
    private function namedTarget(string $named, Manifest $manifest, array $configured, array $candidates): ?string
    {
        if (! in_array($named, $configured, true)) {
            $this->error("Unknown database connection [{$named}].");

            return null;
        }

        if (! array_key_exists($named, $manifest->connections)) {
            return $named;
        }

        $refusal = "Refusing to load into [{$named}]: it is a source connection of this artifact.";

        if ($candidates === []) {
            $this->error($refusal);
            $this->nowhereSafe($manifest, $configured, refused: true);

            return null;
        }

        $this->error($refusal.' Target one of: '.implode(', ', $candidates).'.');

        return null;
    }

    /**
     * The whole reason an operator hits this command in a single-connection
     * app: there is no target, and none can be chosen — one has to be added.
     * So the message is the config block to paste, in the driver they already
     * run, rather than a refusal to work out for themselves.
     *
     * @param  list<string>  $configured
     * @param  bool  $refused  whether a refusal has already been printed above this
     */
    private function nowhereSafe(Manifest $manifest, array $configured, bool $refused = false): void
    {
        // The whole block is written as one stream: half a paste-ready config
        // on stdout and its reason on stderr is two halves of one message.
        if (! $refused) {
            $this->line('<error>Every configured connection (['.implode(', ', $configured).']) is a source of this artifact, so there is nowhere safe to load it.</error>');
        }

        $this->line('Add a target connection to config/database.php, for example:');
        $this->line('');

        foreach ($this->exampleConnection($manifest) as $line) {
            $this->line($line);
        }

        $this->line('');
        $this->line('then create its schema (php artisan migrate --database=local_copy) and run: php artisan dead-drop:pull --connection=local_copy');
    }

    /**
     * A `local_copy` connection in the shape of the artifact's first source:
     * a file next to the app for SQLite, a second database on the same server
     * otherwise, with the credentials left to the environment.
     *
     * @return list<string>
     */
    private function exampleConnection(Manifest $manifest): array
    {
        $source = (string) array_key_first($manifest->connections);
        $driver = $manifest->connections[$source]['driver'] ?? 'sqlite';

        if ($driver === 'sqlite') {
            return [
                "    'local_copy' => [",
                "        'driver' => 'sqlite',",
                "        'database' => database_path('local_copy.sqlite'),",
                "        'prefix' => '',",
                "        'foreign_key_constraints' => true,",
                '    ],',
            ];
        }

        $database = $this->connectionValue($source, 'database');
        $port = match ($driver) {
            'pgsql' => '5432',
            'sqlsrv' => '1433',
            default => '3306',
        };

        return [
            "    'local_copy' => [",
            "        'driver' => '{$driver}',",
            "        'host' => env('DB_LOCAL_COPY_HOST', '127.0.0.1'),",
            "        'port' => env('DB_LOCAL_COPY_PORT', '{$port}'),",
            "        'database' => '".($database === '' ? $source : $database)."_local_copy',",
            "        'username' => env('DB_LOCAL_COPY_USERNAME', 'forge'),",
            "        'password' => env('DB_LOCAL_COPY_PASSWORD', ''),",
            '    ],',
        ];
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

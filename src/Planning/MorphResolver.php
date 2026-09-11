<?php

declare(strict_types=1);

namespace DeadDrop\DeadDrop\Planning;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use ReflectionClass;

/**
 * Turns the type string stored in a polymorphic column into the table it
 * names, the way Eloquent would: through the application's morph map first,
 * then by treating the string as a model class name.
 *
 * Old rows routinely carry types no model answers to any more, so an
 * unresolvable type is a null rather than an exception — the traversal reports
 * it and carries on.
 */
final class MorphResolver
{
    public function tableFor(string $type): ?string
    {
        $class = Relation::morphMap()[$type] ?? $type;

        if (! class_exists($class) || ! is_subclass_of($class, Model::class)) {
            return null;
        }

        // A morph type naming an abstract base model resolves to a real class
        // that cannot be constructed, and the table name is only reachable
        // through an instance.
        if (! (new ReflectionClass($class))->isInstantiable()) {
            return null;
        }

        return (new $class)->getTable();
    }
}

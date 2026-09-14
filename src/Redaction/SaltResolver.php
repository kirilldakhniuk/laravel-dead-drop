<?php

declare(strict_types=1);

namespace DeadDrop\DeadDrop\Redaction;

/**
 * The single rule for turning configuration into a redaction salt: an
 * explicit `DEAD_DROP_REDACTION_SALT` always wins; otherwise the package
 * derives one from `APP_KEY` so a dump works without any redaction
 * configuration at all. The derived salt stays stable for one app (and
 * unguessable without its key) and changes whenever `APP_KEY` is rotated.
 */
final class SaltResolver
{
    public static function resolve(?string $configured, ?string $appKey): ?string
    {
        if ($configured !== null && $configured !== '') {
            return $configured;
        }

        if ($appKey !== null && $appKey !== '') {
            return hash('sha256', 'dead-drop|'.$appKey);
        }

        return null;
    }
}

<?php

declare(strict_types=1);

namespace DeadDrop\DeadDrop\Console\Commands\Concerns;

/**
 * Renders a byte count the way an operator reads it, for the size columns the
 * dump and dumps commands print.
 */
trait FormatsBytes
{
    private function size(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $value = (float) $bytes;
        $unit = 0;

        while ($value >= 1024 && $unit < count($units) - 1) {
            $value /= 1024;
            $unit++;
        }

        return $unit === 0 ? "{$bytes} B" : sprintf('%.1f %s', $value, $units[$unit]);
    }
}

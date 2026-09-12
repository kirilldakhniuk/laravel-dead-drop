<?php

declare(strict_types=1);

namespace DeadDrop\DeadDrop\Tests\Fixtures;

use DeadDrop\DeadDrop\Loading\PullReport;

/**
 * A `pull.after` hook that keeps the report it was handed, so a test can see
 * what the command passed to a configured class-string hook.
 */
final class RecordingAfterHook
{
    public static ?PullReport $report = null;

    public function __invoke(PullReport $report): void
    {
        self::$report = $report;
    }
}

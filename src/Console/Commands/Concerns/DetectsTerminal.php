<?php

declare(strict_types=1);

namespace DeadDrop\DeadDrop\Console\Commands\Concerns;

/**
 * Whether this run may put a question to a human.
 *
 * `--no-interaction` is not the only way to be scripted: a cron job, a
 * container entrypoint, a CI step or a managed host runs a command with no
 * terminal at all, and Symfony's interactive flag stays true throughout.
 * Laravel Prompts answers an unaskable question with its own default
 * (`Prompt::prompt()`), so a command that trusted that flag would read its
 * own default back as though a human had chosen it — and for a destructive
 * command, take silence for consent.
 */
trait DetectsTerminal
{
    private function canAsk(): bool
    {
        return $this->input->isInteractive() && ($this->hasTerminal() || $this->laravel->runningUnitTests());
    }

    private function hasTerminal(): bool
    {
        return defined('STDIN') && function_exists('stream_isatty') && stream_isatty(STDIN);
    }
}

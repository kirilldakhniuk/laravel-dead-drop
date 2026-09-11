<?php

declare(strict_types=1);

namespace DeadDrop\DeadDrop\Config;

use InvalidArgumentException;

/**
 * Renders a connection's config as PHP source a human reviews and edits.
 *
 * This class only formats: what is included and in what order is decided by
 * `ConnectionConfig::toArray()`, so the rendered file and the array form can
 * never disagree. The output is deterministic — tables, references and
 * redactions are sorted and keys appear in a fixed order — so re-running
 * `init` produces an empty diff when nothing changed. Nothing is written as
 * a comment: every rendered value is data the merger needs to read back.
 */
final class ConfigRenderer
{
    private const string INDENT = '    ';

    /**
     * Table keys whose value renders as an indented block rather than on one
     * line, because a human edits their entries row by row.
     *
     * @var list<string>
     */
    private const array BLOCK_KEYS = ['references', 'redact'];

    public function render(ConnectionConfig $config): string
    {
        $lines = ['<?php', '', 'declare(strict_types=1);', '', 'return ['];

        foreach ($config->toArray() as $name => $table) {
            foreach ($this->table($name, $table) as $line) {
                $lines[] = $line;
            }
        }

        $lines[] = '];';

        return implode("\n", $lines)."\n";
    }

    /**
     * @param  array<string, mixed>  $table
     * @return list<string>
     */
    private function table(string $name, array $table): array
    {
        $lines = [$this->indent(1).$this->string($name).' => ['];

        foreach ($table as $key => $value) {
            if (is_array($value) && in_array($key, self::BLOCK_KEYS, true)) {
                foreach ($this->block($key, $value) as $line) {
                    $lines[] = $line;
                }

                continue;
            }

            $lines[] = $this->indent(2).$this->string($key).' => '.$this->value($value).',';
        }

        $lines[] = $this->indent(1).'],';

        return $lines;
    }

    /**
     * @param  array<array-key, mixed>  $entries
     * @return list<string>
     */
    private function block(string $key, array $entries): array
    {
        $lines = [$this->indent(2).$this->string($key).' => ['];

        foreach ($entries as $column => $entry) {
            $lines[] = $this->indent(3).$this->string((string) $column).' => '.$this->value($entry).',';
        }

        $lines[] = $this->indent(2).'],';

        return $lines;
    }

    private function value(mixed $value): string
    {
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if (is_string($value)) {
            return $this->string($value);
        }

        if (is_array($value)) {
            return $this->inline($value);
        }

        throw new InvalidArgumentException('A config value must be a string, a boolean or an array.');
    }

    /**
     * @param  array<array-key, mixed>  $values
     */
    private function inline(array $values): string
    {
        $parts = [];

        foreach ($values as $key => $value) {
            $parts[] = is_int($key)
                ? $this->value($value)
                : $this->string($key).' => '.$this->value($value);
        }

        return '['.implode(', ', $parts).']';
    }

    private function string(string $value): string
    {
        return "'".str_replace(['\\', "'"], ['\\\\', "\\'"], $value)."'";
    }

    private function indent(int $depth): string
    {
        return str_repeat(self::INDENT, $depth);
    }
}

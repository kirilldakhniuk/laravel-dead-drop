<?php

declare(strict_types=1);

namespace DeadDrop\DeadDrop\Config;

/**
 * Renders a connection's config as PHP source a human reviews and edits.
 *
 * The output is deterministic — tables, references and redactions are sorted
 * and every key appears in a fixed order — so re-running `init` produces an
 * empty diff when nothing changed. Nothing is written as a comment: every
 * rendered value is data the merger needs to read back.
 */
final class ConfigRenderer
{
    private const string INDENT = '    ';

    public function render(ConnectionConfig $config): string
    {
        $tables = $config->tables;
        ksort($tables);

        $lines = ['<?php', '', 'declare(strict_types=1);', '', 'return ['];

        foreach ($tables as $table) {
            foreach ($this->table($table) as $line) {
                $lines[] = $line;
            }
        }

        $lines[] = '];';

        return implode("\n", $lines)."\n";
    }

    /**
     * @return list<string>
     */
    private function table(TableConfig $table): array
    {
        $indent = self::INDENT.self::INDENT;

        $lines = [self::INDENT.$this->string($table->name).' => ['];
        $lines[] = $indent."'class' => ".$this->string($table->class->value).',';

        if ($table->removed) {
            $lines[] = $indent."'removed' => true,";
        }

        if ($table->window !== null) {
            $lines[] = $indent."'window' => ".$this->string($table->window).',';
        }

        if ($table->exclude !== null) {
            $lines[] = $indent."'exclude' => ".$this->string($table->exclude).',';
        }

        if ($table->morph !== null) {
            $lines[] = $indent."'morph' => ['type' => ".$this->string($table->morph['type']).", 'id' => ".$this->string($table->morph['id']).'],';
        }

        $lines[] = $indent."'columns' => ".$this->inlineList($table->columns).',';

        if ($table->class === TableClass::Skip) {
            $lines[] = self::INDENT.'],';

            return $lines;
        }

        foreach ($this->references($table->references) as $line) {
            $lines[] = $line;
        }

        foreach ($this->redact($table->redact) as $line) {
            $lines[] = $line;
        }

        $lines[] = self::INDENT.'],';

        return $lines;
    }

    /**
     * @param  array<string, Reference>  $references
     * @return list<string>
     */
    private function references(array $references): array
    {
        if ($references === []) {
            return [];
        }

        ksort($references);

        $lines = [self::INDENT.self::INDENT."'references' => ["];

        foreach ($references as $column => $reference) {
            $lines[] = self::INDENT.self::INDENT.self::INDENT.$this->string($column).' => '.$this->reference($reference).',';
        }

        $lines[] = self::INDENT.self::INDENT.'],';

        return $lines;
    }

    /**
     * @param  array<string, string>  $redact
     * @return list<string>
     */
    private function redact(array $redact): array
    {
        if ($redact === []) {
            return [];
        }

        ksort($redact);

        $lines = [self::INDENT.self::INDENT."'redact' => ["];

        foreach ($redact as $column => $transformer) {
            $lines[] = self::INDENT.self::INDENT.self::INDENT.$this->string($column).' => '.$this->string($transformer).',';
        }

        $lines[] = self::INDENT.self::INDENT.'],';

        return $lines;
    }

    private function reference(Reference $reference): string
    {
        $parts = [$this->string($reference->target())];

        if (! $reference->descend) {
            $parts[] = "'descend' => false";
        }

        $parts[] = "'source' => ".$this->string($reference->source->value);

        return '['.implode(', ', $parts).']';
    }

    /**
     * @param  list<string>  $values
     */
    private function inlineList(array $values): string
    {
        return '['.implode(', ', array_map($this->string(...), $values)).']';
    }

    private function string(string $value): string
    {
        return "'".str_replace(['\\', "'"], ['\\\\', "\\'"], $value)."'";
    }
}

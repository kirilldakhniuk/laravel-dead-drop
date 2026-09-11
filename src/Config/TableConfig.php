<?php

declare(strict_types=1);

namespace DeadDrop\DeadDrop\Config;

use InvalidArgumentException;

/**
 * One table's reviewed plan: how it is treated, which columns it had at the
 * last init (so column drift is detectable), where it points, what is
 * redacted, and how it is scoped.
 */
final readonly class TableConfig
{
    /**
     * @param  list<string>  $columns  the table's column names in schema order
     * @param  array<string, Reference>  $references  keyed by the referencing column
     * @param  array<string, string>  $redact  transformer keyed by column
     * @param  array{type: string, id: string}|null  $morph
     */
    public function __construct(
        public string $name,
        public TableClass $class,
        public array $columns,
        public array $references,
        public array $redact,
        public ?string $window,
        public ?string $exclude,
        public ?array $morph,
        public bool $removed = false,
    ) {}

    /**
     * @param  array<array-key, mixed>  $raw
     */
    public static function fromArray(string $name, array $raw): self
    {
        return new self(
            name: $name,
            class: self::readClass($raw),
            columns: self::readColumns($raw),
            references: self::readReferences($name, $raw),
            redact: self::readRedact($raw),
            window: self::readString($raw, 'window'),
            exclude: self::readString($raw, 'exclude'),
            morph: self::readMorph($raw),
            removed: ($raw['removed'] ?? false) === true,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $table = ['class' => $this->class->value];

        if ($this->removed) {
            $table['removed'] = true;
        }

        foreach (['window' => $this->window, 'exclude' => $this->exclude, 'morph' => $this->morph] as $key => $value) {
            if ($value !== null) {
                $table[$key] = $value;
            }
        }

        $table['columns'] = $this->columns;

        $references = [];

        foreach ($this->references as $column => $reference) {
            $references[$column] = $reference->toArray();
        }

        if ($references !== []) {
            $table['references'] = $references;
        }

        if ($this->redact !== []) {
            $table['redact'] = $this->redact;
        }

        return $table;
    }

    /**
     * @param  array<array-key, mixed>  $raw
     */
    private static function readClass(array $raw): TableClass
    {
        $class = $raw['class'] ?? null;

        return is_string($class) ? TableClass::from($class) : TableClass::Data;
    }

    /**
     * @param  array<array-key, mixed>  $raw
     * @return list<string>
     */
    private static function readColumns(array $raw): array
    {
        $raw = $raw['columns'] ?? [];

        if (! is_array($raw)) {
            return [];
        }

        $columns = [];

        foreach ($raw as $column) {
            if (is_string($column)) {
                $columns[] = $column;
            }
        }

        return $columns;
    }

    /**
     * @param  array<array-key, mixed>  $raw
     * @return array<string, Reference>
     */
    private static function readReferences(string $name, array $raw): array
    {
        $raw = $raw['references'] ?? [];

        if (! is_array($raw)) {
            return [];
        }

        $references = [];

        foreach ($raw as $column => $reference) {
            if (! is_string($reference) && ! is_array($reference)) {
                throw new InvalidArgumentException("Reference [$name.$column] must be a string or an array.");
            }

            $references[(string) $column] = Reference::fromArray($reference);
        }

        return $references;
    }

    /**
     * @param  array<array-key, mixed>  $raw
     * @return array<string, string>
     */
    private static function readRedact(array $raw): array
    {
        $raw = $raw['redact'] ?? [];

        if (! is_array($raw)) {
            return [];
        }

        $redact = [];

        foreach ($raw as $column => $transformer) {
            if (is_string($transformer)) {
                $redact[(string) $column] = $transformer;
            }
        }

        return $redact;
    }

    /**
     * @param  array<array-key, mixed>  $raw
     */
    private static function readString(array $raw, string $key): ?string
    {
        $value = $raw[$key] ?? null;

        return is_string($value) ? $value : null;
    }

    /**
     * @param  array<array-key, mixed>  $raw
     * @return array{type: string, id: string}|null
     */
    private static function readMorph(array $raw): ?array
    {
        $morph = $raw['morph'] ?? null;

        if (! is_array($morph)) {
            return null;
        }

        $type = $morph['type'] ?? null;
        $id = $morph['id'] ?? null;

        if (! is_string($type) || ! is_string($id)) {
            throw new InvalidArgumentException('A morph must name both a [type] and an [id] column.');
        }

        return ['type' => $type, 'id' => $id];
    }
}

<?php

declare(strict_types=1);

namespace DeadDrop\DeadDrop\Config;

use DeadDrop\DeadDrop\Inference\EdgeSource;
use InvalidArgumentException;
use ValueError;

/**
 * A pointer from one column to the key of another table: what makes a dump
 * referentially complete. `descend` says whether the dump follows the edge
 * to pull the target row in; `source` records how the edge was found so a
 * re-run can upgrade a guess without discarding the human's decisions.
 */
final readonly class Reference
{
    public function __construct(
        public ?string $connection,
        public string $table,
        public string $column,
        public bool $descend,
        public EdgeSource $source,
    ) {}

    /**
     * Accepts the shorthand a human types (`'companies.id'`, or
     * `'dd_test.companies.id'` for a cross-connection target) as well as the
     * rendered array form.
     *
     * @param  string|array<array-key, mixed>  $raw
     * @param  string|null  $context  the `table.column` the reference sits on, for error messages
     */
    public static function fromArray(string|array $raw, ?string $context = null): self
    {
        $where = $context === null ? 'A reference' : "Reference [$context]";

        if (is_string($raw)) {
            return self::fromTarget($raw, true, EdgeSource::Manual, $where);
        }

        $target = $raw[0] ?? null;

        if (! is_string($target)) {
            throw new InvalidArgumentException("$where must name its target as [table.column].");
        }

        $descend = $raw['descend'] ?? true;

        return self::fromTarget(
            $target,
            is_bool($descend) ? $descend : true,
            self::readSource($raw['source'] ?? null, $where),
            $where,
        );
    }

    /**
     * A hand-edited `source` is named rather than left to surface a raw
     * `ValueError`; an absent one is a human's own entry, so it is `manual`.
     */
    private static function readSource(mixed $source, string $where): EdgeSource
    {
        if (! is_string($source)) {
            return EdgeSource::Manual;
        }

        try {
            return EdgeSource::from($source);
        } catch (ValueError $e) {
            $accepted = implode(', ', array_map(fn (EdgeSource $case): string => $case->value, EdgeSource::cases()));

            throw new InvalidArgumentException("$where has an unknown 'source' [$source]; expected one of $accepted.", previous: $e);
        }
    }

    private static function fromTarget(string $target, bool $descend, EdgeSource $source, string $where): self
    {
        $parts = explode('.', $target);

        return match (count($parts)) {
            2 => new self(null, $parts[0], $parts[1], $descend, $source),
            3 => new self($parts[0], $parts[1], $parts[2], $descend, $source),
            default => throw new InvalidArgumentException("$where names target [$target], which must be [table.column] or [connection.table.column]."),
        };
    }

    /**
     * The dotted target, connection-qualified when the target lives on
     * another connection.
     */
    public function target(): string
    {
        $target = $this->table.'.'.$this->column;

        return $this->connection !== null ? $this->connection.'.'.$target : $target;
    }

    /**
     * @return array<array-key, string|bool>
     */
    public function toArray(): array
    {
        $reference = [$this->target()];

        if (! $this->descend) {
            $reference['descend'] = false;
        }

        $reference['source'] = $this->source->value;

        return $reference;
    }
}

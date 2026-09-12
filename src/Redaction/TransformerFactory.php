<?php

declare(strict_types=1);

namespace DeadDrop\DeadDrop\Redaction;

use DeadDrop\DeadDrop\Redaction\Transformers\BcryptTransformer;
use DeadDrop\DeadDrop\Redaction\Transformers\FixedTransformer;
use DeadDrop\DeadDrop\Redaction\Transformers\HashTransformer;
use DeadDrop\DeadDrop\Redaction\Transformers\KeepTransformer;
use DeadDrop\DeadDrop\Redaction\Transformers\MaskTransformer;
use DeadDrop\DeadDrop\Redaction\Transformers\NullTransformer;
use DeadDrop\DeadDrop\Redaction\Transformers\ScrambleTransformer;
use DeadDrop\DeadDrop\Schema\Column;
use DeadDrop\DeadDrop\Schema\ColumnType;
use InvalidArgumentException;

final class TransformerFactory
{
    public function make(string $spec, Column $column, string $primaryKey, RedactionContext $context): Transformer
    {
        [$name, $argument] = array_pad(explode(':', $spec, 2), 2, null);

        return match ($name) {
            'hash' => new HashTransformer($context->salt, $column->type === ColumnType::String ? $this->declaredLength($column) : null, $this->isEmailColumn($column) ? $context->emailDomain : null),
            'mask' => new MaskTransformer,
            'null' => new NullTransformer,
            'scramble' => $this->isDateColumn($column)
                ? new ScrambleTransformer($context->salt, $primaryKey)
                : throw new InvalidArgumentException("'scramble' requires a date or datetime column; [{$column->name}] is {$column->nativeType}."),
            'bcrypt' => new BcryptTransformer($context, (string) $argument),
            'fixed' => new FixedTransformer((string) $argument),
            'keep' => new KeepTransformer,
            default => throw new InvalidArgumentException("Unknown redaction transformer [{$spec}] for column [{$column->name}]."),
        };
    }

    /**
     * The length declared in the native type (`varchar(32)` → 32), when the
     * database states one. `RedactionRules` asks the same question, so this
     * is the one answer both use.
     */
    public function declaredLength(Column $column): ?int
    {
        return preg_match('/\((\d+)/', $column->nativeType, $matches) === 1 ? (int) $matches[1] : null;
    }

    /**
     * Same three patterns as the `email` row in
     * `Inference\SensitiveColumnDetector::PATTERNS` (that constant is
     * private, so it is duplicated here rather than shared).
     */
    public function isEmailColumn(Column $column): bool
    {
        $lower = strtolower($column->name);

        return $lower === 'email' || $lower === 'email_address' || str_ends_with($lower, '_email');
    }

    /**
     * Whether `scramble` can shift this column: a date, datetime or
     * timestamp. `ColumnType::DateTime` also covers `time` and `year`, which
     * carry no date to move.
     */
    public function isDateColumn(Column $column): bool
    {
        if ($column->type !== ColumnType::DateTime) {
            return false;
        }

        $native = strtolower(trim($column->nativeType));

        return str_starts_with($native, 'date') || str_starts_with($native, 'timestamp');
    }
}

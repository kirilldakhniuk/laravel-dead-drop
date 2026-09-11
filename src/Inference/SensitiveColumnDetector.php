<?php

declare(strict_types=1);

namespace DeadDrop\DeadDrop\Inference;

use DeadDrop\DeadDrop\Schema\Column;
use DeadDrop\DeadDrop\Schema\ColumnType;
use DeadDrop\DeadDrop\Schema\Table;

/**
 * Suggests a redaction transformer for columns whose name looks like it
 * holds personal or secret data, and flags columns a human must decide
 * about instead: JSON blobs of unknown shape and the `type` half of a
 * polymorphic column pair.
 *
 * `hash` is the default suggestion for sensitive-but-unique columns because
 * it is collision-safe and expressible in plain SQL; `faker` is never
 * auto-suggested. `bcrypt:secret` is a deterministic placeholder — the
 * bcrypt hash of the literal string `secret` computed at extraction time —
 * so re-running `init` never churns the generated config.
 */
final class SensitiveColumnDetector
{
    /**
     * Ordered so the first matching row wins. Each row matches a lower-cased
     * column name by exact name, prefix, suffix, or substring; `requiresString`
     * additionally restricts the row to `ColumnType::String` columns.
     *
     * @var list<array{
     *     exact?: list<string>,
     *     prefix?: list<string>,
     *     suffix?: list<string>,
     *     contains?: list<string>,
     *     suggestion: string,
     *     requiresString?: bool,
     * }>
     */
    private const array PATTERNS = [
        ['exact' => ['email', 'email_address'], 'suffix' => ['_email'], 'suggestion' => 'hash'],
        ['exact' => ['phone', 'mobile', 'telephone', 'fax'], 'suffix' => ['_phone'], 'suggestion' => 'mask'],
        ['exact' => ['password', 'password_hash'], 'suggestion' => 'bcrypt:secret'],
        ['exact' => ['api_key'], 'suffix' => ['_key'], 'contains' => ['token', 'secret'], 'suggestion' => 'null'],
        ['exact' => ['ssn', 'tax_id', 'national_id'], 'suggestion' => 'hash'],
        ['exact' => ['iban', 'account_number', 'routing_number', 'card_number', 'cvv'], 'suggestion' => 'null'],
        ['exact' => ['date_of_birth', 'dob', 'birth_date'], 'suggestion' => 'scramble'],
        ['prefix' => ['stripe_'], 'suffix' => ['_customer_id'], 'suggestion' => 'fixed:redacted', 'requiresString' => true],
    ];

    public function __construct(
        private readonly MorphPairDetector $morphs = new MorphPairDetector,
    ) {}

    /**
     * @return array<string, string>
     */
    public function detect(Table $table): array
    {
        $suggestions = [];

        foreach ($table->columns as $column) {
            $suggestion = $this->suggestionFor($column);

            if ($suggestion !== null) {
                $suggestions[$column->name] = $suggestion;
            }
        }

        return $suggestions;
    }

    /**
     * @return list<string>
     */
    public function needsReview(Table $table): array
    {
        $columns = [];

        foreach ($table->columns as $column) {
            if ($column->type === ColumnType::Json) {
                $columns[] = $column->name;
            }
        }

        $morph = $this->morphs->detect($table);

        if ($morph !== null && ! in_array($morph['type'], $columns, true)) {
            $columns[] = $morph['type'];
        }

        return $columns;
    }

    private function suggestionFor(Column $column): ?string
    {
        $lower = strtolower($column->name);

        foreach (self::PATTERNS as $rule) {
            if (($rule['requiresString'] ?? false) && $column->type !== ColumnType::String) {
                continue;
            }

            if ($this->matches($rule, $lower)) {
                return $rule['suggestion'];
            }
        }

        return null;
    }

    /**
     * @param  array{exact?: list<string>, prefix?: list<string>, suffix?: list<string>, contains?: list<string>, suggestion: string, requiresString?: bool}  $rule
     */
    private function matches(array $rule, string $lower): bool
    {
        foreach ($rule['exact'] ?? [] as $exact) {
            if ($lower === $exact) {
                return true;
            }
        }

        foreach ($rule['prefix'] ?? [] as $prefix) {
            if (str_starts_with($lower, $prefix)) {
                return true;
            }
        }

        foreach ($rule['suffix'] ?? [] as $suffix) {
            if (str_ends_with($lower, $suffix)) {
                return true;
            }
        }

        foreach ($rule['contains'] ?? [] as $needle) {
            if (str_contains($lower, $needle)) {
                return true;
            }
        }

        return false;
    }
}

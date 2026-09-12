<?php

declare(strict_types=1);

namespace DeadDrop\DeadDrop\Redaction\Transformers;

use DateTimeImmutable;
use DeadDrop\DeadDrop\Redaction\Transformer;
use Exception;
use InvalidArgumentException;

final readonly class ScrambleTransformer implements Transformer
{
    public function __construct(
        private string $salt,
        private string $primaryKey,
    ) {}

    public function apply(mixed $value, array $row): mixed
    {
        if ($value === null) {
            return null;
        }

        $string = (string) $value;
        $keyMaterial = array_key_exists($this->primaryKey, $row) ? (string) $row[$this->primaryKey] : '';
        $days = (hexdec(substr(hash('sha256', $this->salt.$keyMaterial), 0, 8)) % 361) - 180;

        try {
            $date = new DateTimeImmutable($string);
        } catch (Exception) {
            throw new InvalidArgumentException("Cannot scramble unparseable date [{$value}].");
        }

        $scrambled = $date->modify("{$days} days");
        $format = mb_strlen(trim($string)) === 10 ? 'Y-m-d' : 'Y-m-d H:i:s';

        return $scrambled->format($format);
    }
}

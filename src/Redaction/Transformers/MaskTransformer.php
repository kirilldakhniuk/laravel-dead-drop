<?php

declare(strict_types=1);

namespace DeadDrop\DeadDrop\Redaction\Transformers;

use DeadDrop\DeadDrop\Redaction\Transformer;

final readonly class MaskTransformer implements Transformer
{
    public function apply(mixed $value, array $row): mixed
    {
        if ($value === null) {
            return null;
        }

        $string = (string) $value;
        $length = mb_strlen($string);

        if ($length <= 4) {
            return '****';
        }

        return str_repeat('*', $length - 4).mb_substr($string, -4);
    }
}

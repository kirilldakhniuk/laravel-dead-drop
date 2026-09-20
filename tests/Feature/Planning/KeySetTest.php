<?php

declare(strict_types=1);

use DeadDrop\DeadDrop\Planning\KeySetRepository;
use DeadDrop\DeadDrop\Schema\ColumnType;

it('reads every key when processed chunks are removed', function (ColumnType $type, array $values) {
    $repository = app(KeySetRepository::class);
    $keys = $repository->create('dd_test', 'records', $type);
    $keys->add($values);
    $collected = [];

    try {
        $keys->chunk(2, function (array $chunk) use ($keys, &$collected): void {
            array_push($collected, ...$chunk);
            $keys->query()->whereIn('k', $chunk)->delete();
        });

        expect($collected)->toBe($values)
            ->and($keys->count())->toBe(0);
    } finally {
        $repository->dropAll();
    }
})->with([
    'integer keys' => [ColumnType::Integer, [1, 3, 5, 7, 9]],
    'string keys' => [ColumnType::String, ['a', 'c', 'e', 'g', 'i']],
]);

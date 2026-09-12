<?php

declare(strict_types=1);

use DeadDrop\DeadDrop\Artifacts\ArtifactReader;
use DeadDrop\DeadDrop\Artifacts\ArtifactWriter;
use DeadDrop\DeadDrop\Artifacts\Manifest;
use DeadDrop\DeadDrop\Artifacts\RowCodec;
use DeadDrop\DeadDrop\Artifacts\TableManifest;
use DeadDrop\DeadDrop\Schema\ColumnType;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

beforeEach(fn () => fakeArtifactDisk());

function writeSampleArtifact(string $id, string $status = Manifest::STATUS_COMPLETE): Manifest
{
    $disk = Storage::disk('local');
    $writer = new ArtifactWriter($disk, 'dead-drops', $id);
    $types = ['id' => ColumnType::Integer, 'name' => ColumnType::String, 'flags' => ColumnType::Json, 'blob' => ColumnType::Binary, 'ok' => ColumnType::Boolean];

    $file = $writer->table('dd_test.things.ndjson.gz', $types);
    $file->append(['id' => 1, 'name' => 'héllo/wörld', 'flags' => '{"a":1}', 'blob' => "\x00\xff\x10", 'ok' => 1]);
    $file->append(['id' => 2, 'name' => null, 'flags' => null, 'blob' => null, 'ok' => 0]);
    $counts = $file->finish();

    $manifest = new Manifest($id, $status, '2026-09-12T14:15:00+00:00', 'dev', 'dd_test.things:1', null, 'php', ['dd_test' => ['driver' => 'sqlite']], [
        new TableManifest('dd_test', 'things', 'dd_test.things.ndjson.gz', 'ndjson', $counts['rows'], $counts['bytes'], 'id', [
            ['name' => 'id', 'type' => 'int'], ['name' => 'name', 'type' => 'string'], ['name' => 'flags', 'type' => 'json'], ['name' => 'blob', 'type' => 'binary'], ['name' => 'ok', 'type' => 'bool'],
        ], []),
    ], []);
    $writer->writeManifest($manifest);

    return $manifest;
}

it('writes a gzipped ndjson file and a manifest under the dump id', function () {
    writeSampleArtifact('20260912-141500-aaaaaa');

    expect(Storage::disk('local')->exists('dead-drops/20260912-141500-aaaaaa/manifest.json'))->toBeTrue()
        ->and(Storage::disk('local')->exists('dead-drops/20260912-141500-aaaaaa/dd_test.things.ndjson.gz'))->toBeTrue();
});

it('reads rows back with types restored', function () {
    $manifest = writeSampleArtifact('20260912-141500-aaaaaa');
    $reader = new ArtifactReader(Storage::disk('local'), 'dead-drops');

    $rows = iterator_to_array($reader->rows($manifest->id, $manifest->tables[0]), false);

    expect($rows)->toHaveCount(2)
        ->and($rows[0]['id'])->toBe(1)
        ->and($rows[0]['name'])->toBe('héllo/wörld')
        ->and($rows[0]['flags'])->toBe('{"a":1}')
        ->and($rows[0]['blob'])->toBe("\x00\xff\x10")
        ->and($rows[0]['ok'])->toBeTrue()
        ->and($rows[1]['name'])->toBeNull()
        ->and($rows[1]['blob'])->toBeNull()
        ->and($rows[1]['ok'])->toBeFalse()
        ->and($manifest->tables[0]->rows)->toBe(2);
});

it('lists ids newest first and finds the latest complete manifest', function () {
    writeSampleArtifact('20260912-141500-aaaaaa');
    writeSampleArtifact('20260912-150000-bbbbbb', Manifest::STATUS_WRITING);
    writeSampleArtifact('20260911-090000-cccccc');
    $reader = new ArtifactReader(Storage::disk('local'), 'dead-drops');

    expect($reader->ids())->toBe(['20260912-150000-bbbbbb', '20260912-141500-aaaaaa', '20260911-090000-cccccc'])
        ->and($reader->latestComplete()?->id)->toBe('20260912-141500-aaaaaa')
        ->and(fn () => $reader->manifest('nope'))->toThrow(InvalidArgumentException::class, 'nope');
});

it('returns null when no complete artifact exists', function () {
    expect((new ArtifactReader(Storage::disk('local'), 'dead-drops'))->latestComplete())->toBeNull();
});

it('removes its staging file when aborted before finish', function () {
    // Pinning the staging file's name names the one file this assertion is
    // about: globbing the shared temp dir also sees the files the other
    // parallel workers are writing at that moment.
    Str::createRandomStringsUsing(fn (): string => 'aborted-before-finish');
    $staging = sys_get_temp_dir().'/dead-drop-aborted-before-finish';

    try {
        $writer = new ArtifactWriter(Storage::disk('local'), 'dead-drops', '20260912-141500-dddddd');
        $file = $writer->table('dd_test.things.ndjson.gz', ['id' => ColumnType::Integer]);
        $file->append(['id' => 1]);

        expect(is_file($staging))->toBeTrue();

        $file->abort();
    } finally {
        Str::createRandomStringsNormally();
    }

    expect(is_file($staging))->toBeFalse()
        ->and(Storage::disk('local')->exists('dead-drops/20260912-141500-dddddd/dd_test.things.ndjson.gz'))->toBeFalse();
});

it('refuses to finish a table file that was aborted', function () {
    $writer = new ArtifactWriter(Storage::disk('local'), 'dead-drops', '20260912-141500-eeeeee');
    $file = $writer->table('dd_test.things.ndjson.gz', ['id' => ColumnType::Integer]);
    $file->abort();

    expect(fn () => $file->finish())->toThrow(RuntimeException::class, 'already finished or aborted');
});

it('throws when the copied bytes do not match the manifest', function () {
    $manifest = writeSampleArtifact('20260912-141500-aaaaaa');
    $reader = new ArtifactReader(Storage::disk('local'), 'dead-drops');
    $file = 'dead-drops/20260912-141500-aaaaaa/dd_test.things.ndjson.gz';
    $disk = Storage::disk('local');

    $disk->put($file, substr((string) $disk->get($file), 0, 10));

    expect(fn () => iterator_to_array($reader->rows($manifest->id, $manifest->tables[0])))
        ->toThrow(RuntimeException::class, 'bytes');
});

it('decodes postgres style boolean strings', function () {
    $codec = new RowCodec;
    $types = ['ok' => ColumnType::Boolean];

    $true = $codec->decode($codec->encode(['ok' => 't'], $types), $types);
    $false = $codec->decode($codec->encode(['ok' => 'f'], $types), $types);

    expect($true['ok'])->toBeTrue()
        ->and($false['ok'])->toBeFalse();
});

it('reads back a row far longer than the read buffer in one piece', function () {
    // `gzgets()` returns at most its buffer size, so a row wider than that
    // arrives in fragments; decoding a fragment is a JSON error at pull time.
    $long = str_repeat('x', 1_500_000);
    $disk = Storage::disk('local');
    $writer = new ArtifactWriter($disk, 'dead-drops', '20260912-141500-111111');
    $types = ['id' => ColumnType::Integer, 'name' => ColumnType::String];

    $file = $writer->table('dd_test.things.ndjson.gz', $types);
    $file->append(['id' => 1, 'name' => $long]);
    $file->append(['id' => 2, 'name' => 'short']);
    $counts = $file->finish();

    $table = new TableManifest('dd_test', 'things', 'dd_test.things.ndjson.gz', 'ndjson', $counts['rows'], $counts['bytes'], 'id', [
        ['name' => 'id', 'type' => 'int'], ['name' => 'name', 'type' => 'string'],
    ], []);

    $rows = iterator_to_array((new ArtifactReader($disk, 'dead-drops'))->rows('20260912-141500-111111', $table), false);

    expect($rows)->toHaveCount(2)
        ->and($rows[0]['name'])->toBe($long)
        ->and($rows[1]['name'])->toBe('short');
});

it('leaves a non boolean value on a boolean column alone', function () {
    // A MySQL `tinyint(4)` narrowed to a boolean by an older introspection
    // still has to arrive at the target as the number it was.
    $codec = new RowCodec;
    $types = ['ok' => ColumnType::Boolean];

    expect($codec->decode($codec->encode(['ok' => 5], $types), $types)['ok'])->toBe(5)
        ->and($codec->decode($codec->encode(['ok' => 1], $types), $types)['ok'])->toBeTrue()
        ->and($codec->decode($codec->encode(['ok' => 0], $types), $types)['ok'])->toBeFalse();
});

it('throws when the disk refuses a table file', function () {
    $disk = Mockery::mock(Filesystem::class);
    $disk->shouldReceive('put')->andReturn(false);

    $writer = new ArtifactWriter($disk, 'dead-drops', '20260912-141500-222222');
    $file = $writer->table('dd_test.things.ndjson.gz', ['id' => ColumnType::Integer]);
    $file->append(['id' => 1]);

    expect(fn () => $file->finish())
        ->toThrow(RuntimeException::class, 'Unable to write [dead-drops/20260912-141500-222222/dd_test.things.ndjson.gz] to the artifact disk.');
});

it('throws when the disk refuses the manifest or a put', function () {
    $disk = Mockery::mock(Filesystem::class);
    $disk->shouldReceive('put')->andReturn(false);

    $writer = new ArtifactWriter($disk, 'dead-drops', '20260912-141500-333333');
    $manifest = new Manifest('20260912-141500-333333', Manifest::STATUS_WRITING, '2026-09-12T14:15:00+00:00', 'dev', 'dd_test.things:1', null, 'php', [], [], []);

    expect(fn () => $writer->writeManifest($manifest))
        ->toThrow(RuntimeException::class, 'Unable to write [dead-drops/20260912-141500-333333/manifest.json] to the artifact disk.')
        ->and(fn () => $writer->put('dd_test.things.csv', 'id,name'))
        ->toThrow(RuntimeException::class, 'Unable to write [dead-drops/20260912-141500-333333/dd_test.things.csv] to the artifact disk.');
});

it('puts an executor written file into the artifact directory', function () {
    $writer = new ArtifactWriter(Storage::disk('local'), 'dead-drops', '20260912-141500-444444');

    $writer->put('dd_test.things.csv', "id,name\n1,acme\n");

    expect(Storage::disk('local')->get('dead-drops/20260912-141500-444444/dd_test.things.csv'))->toBe("id,name\n1,acme\n");
});

it('reports a corrupt manifest by artifact id', function () {
    writeSampleArtifact('20260912-141500-aaaaaa');
    Storage::disk('local')->put('dead-drops/20260912-141500-aaaaaa/manifest.json', '{not json');

    expect(fn () => (new ArtifactReader(Storage::disk('local'), 'dead-drops'))->manifest('20260912-141500-aaaaaa'))
        ->toThrow(InvalidArgumentException::class, 'Artifact [20260912-141500-aaaaaa] has a corrupt manifest.');
});

it('reads a binary value from a stream before encoding it', function () {
    $codec = new RowCodec;
    $types = ['blob' => ColumnType::Binary];
    $stream = fopen('php://memory', 'r+');
    fwrite($stream, "\x00\xff");
    rewind($stream);

    $decoded = $codec->decode($codec->encode(['blob' => $stream], $types), $types);

    expect($decoded['blob'])->toBe("\x00\xff");
});

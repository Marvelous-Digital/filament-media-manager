<?php

use Illuminate\Support\Facades\Queue;
use MarvelousDigital\FilamentMediaManager\Actions\DuplicateMediaFieldValue;
use MarvelousDigital\FilamentMediaManager\Enums\ConversionStatus;
use MarvelousDigital\FilamentMediaManager\Jobs\GenerateConversion;
use MarvelousDigital\FilamentMediaManager\Models\Media;
use MarvelousDigital\FilamentMediaManager\Models\MediaConversion;
use MarvelousDigital\FilamentMediaManager\Models\MediaFieldValue;

beforeEach(fn () => Queue::fake());

function duplicate(mixed $value, ?callable $mapAlt = null): mixed
{
    return app(DuplicateMediaFieldValue::class)->execute($value, $mapAlt);
}

function sourceValue(array $attributes = []): MediaFieldValue
{
    return MediaFieldValue::create([
        'media_id' => Media::factory()->create()->id,
        'alt_text' => 'Cat',
        ...$attributes,
    ]);
}

function addConversion(MediaFieldValue $value, string $variation, array $attributes = []): MediaConversion
{
    return $value->conversions()->create([
        'variation' => $variation,
        'width' => 100,
        'height' => 50,
        'focus_x' => 0.5,
        'focus_y' => 0.5,
        'zoom' => 1.0,
        'status' => ConversionStatus::Completed,
        'path' => "x/{$variation}.webp",
        ...$attributes,
    ]);
}

it('duplicates a single value into a new row', function () {
    $source = sourceValue();

    $id = duplicate($source->id);

    $copy = MediaFieldValue::find($id);
    expect($id)->toBeInt()->not->toBe($source->id)
        ->and($copy->media_id)->toBe($source->media_id)
        ->and($copy->alt_text)->toBe('Cat');
});

it('duplicates conversions and queues them', function () {
    $source = sourceValue();
    addConversion($source, 'desktop', ['width' => 1500, 'height' => 900, 'focus_x' => 0.2, 'focus_y' => 0.7, 'zoom' => 2.5]);
    addConversion($source, 'mobile');

    $copy = MediaFieldValue::with('conversions')->find(duplicate($source->id));

    expect($copy->conversions)->toHaveCount(2)
        ->and($copy->conversion('desktop')->width)->toBe(1500)
        ->and($copy->conversion('desktop')->height)->toBe(900)
        ->and($copy->conversion('desktop')->focus_x)->toBe(0.2)
        ->and($copy->conversion('desktop')->focus_y)->toBe(0.7)
        ->and($copy->conversion('desktop')->zoom)->toBe(2.5)
        ->and($copy->conversions->pluck('status')->unique()->all())->toBe([ConversionStatus::Pending])
        ->and($copy->conversions->pluck('path')->filter()->all())->toBe([]);
    Queue::assertPushed(GenerateConversion::class, 2);
});

it('copies override images and alt text', function () {
    $source = sourceValue();
    $override = Media::factory()->create();
    addConversion($source, 'mobile', ['media_id' => $override->id, 'alt_text' => 'Dog']);

    $copy = MediaFieldValue::with('conversions')->find(duplicate($source->id));

    expect($copy->conversion('mobile')->media_id)->toBe($override->id)
        ->and($copy->conversion('mobile')->alt_text)->toBe('Dog');
});

it('keeps the order of a list and drops missing ids', function () {
    $a = sourceValue(['alt_text' => 'A']);
    $b = sourceValue(['alt_text' => 'B']);

    $ids = duplicate([$b->id, 999999, $a->id]);

    expect($ids)->toHaveCount(2)
        ->and(MediaFieldValue::find($ids[0])->media_id)->toBe($b->media_id)
        ->and(MediaFieldValue::find($ids[0])->alt_text)->toBe('B')
        ->and(MediaFieldValue::find($ids[1])->media_id)->toBe($a->media_id)
        ->and(MediaFieldValue::find($ids[1])->alt_text)->toBe('A')
        ->and($ids)->not->toContain($a->id)
        ->and($ids)->not->toContain($b->id);
});

it('leaves the source rows untouched', function () {
    $source = sourceValue();
    addConversion($source, 'desktop');

    $id = duplicate($source->id);
    $copy = MediaFieldValue::with('conversions')->find($id);
    $copy->conversions->each->delete();
    $copy->delete();

    expect(MediaFieldValue::find($source->id))->not->toBeNull()
        ->and(MediaConversion::where('media_field_value_id', $source->id)->count())->toBe(1);
});

it('maps alt text through the callback', function () {
    $source = sourceValue();
    $nullAlt = sourceValue(['alt_text' => null]);
    addConversion($source, 'mobile', ['media_id' => Media::factory()->create()->id, 'alt_text' => 'Dog']);
    addConversion($source, 'desktop');

    $copy = MediaFieldValue::with('conversions')->find(duplicate($source->id, fn (string $alt) => strtoupper($alt)));
    $copyNull = MediaFieldValue::find(duplicate($nullAlt->id, fn (string $alt) => strtoupper($alt)));

    expect($copy->alt_text)->toBe('CAT')
        ->and($copy->conversion('mobile')->alt_text)->toBe('DOG')
        ->and($copy->conversion('desktop')->alt_text)->toBeNull()
        ->and($copyNull->alt_text)->toBeNull();
});

it('returns null and non-id values unchanged', function () {
    $state = ['id' => 1, 'media_id' => 2];

    expect(duplicate(null))->toBeNull()
        ->and(duplicate($state))->toBe($state)
        ->and(duplicate('abc'))->toBe('abc');
});

it('accepts numeric string ids', function () {
    $source = sourceValue();

    expect(duplicate((string) $source->id))->toBeInt()->not->toBe($source->id);
});

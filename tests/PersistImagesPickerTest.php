<?php

use Illuminate\Support\Facades\Queue;
use MarvelousDigital\FilamentMediaManager\Actions\PersistImagesPicker;
use MarvelousDigital\FilamentMediaManager\Models\Media;
use MarvelousDigital\FilamentMediaManager\Models\MediaConversion;
use MarvelousDigital\FilamentMediaManager\Models\MediaFieldValue;
use MarvelousDigital\FilamentMediaManager\Support\ImagesPickerState;

beforeEach(fn () => Queue::fake());

$variations = ['desktop' => [1500, 900], 'mobile' => [480, 640]];

$imagesPickerState = function (int $count) {
    $state = ['items' => [], 'removed' => []];
    foreach (range(1, $count) as $ignored) {
        $state = ImagesPickerState::withImage($state, Media::factory()->create());
    }

    return $state;
};

$imagesPickerPersist = fn (mixed $state, array $variations) => app(PersistImagesPicker::class)->execute($state, $variations);

it('creates one row per image in order', function () use ($variations, $imagesPickerState, $imagesPickerPersist) {
    $state = $imagesPickerState(3);

    $ids = $imagesPickerPersist($state, $variations);

    expect($ids)->toHaveCount(3)
        ->and(MediaFieldValue::query()->count())->toBe(3)
        ->and(array_map(fn ($id) => MediaFieldValue::find($id)->media_id, $ids))
        ->toBe(array_column($state['items'], 'media_id'));
});

it('deletes removed rows and their conversions', function () use ($variations, $imagesPickerState, $imagesPickerPersist) {
    $ids = $imagesPickerPersist($imagesPickerState(2), $variations);

    $state = ImagesPickerState::withoutImage(ImagesPickerState::fromStoredValue($ids), "v{$ids[0]}");
    $remaining = $imagesPickerPersist($state, $variations);

    expect($remaining)->toBe([$ids[1]])
        ->and(MediaFieldValue::query()->pluck('id')->all())->toBe([$ids[1]])
        ->and(MediaConversion::query()->count())->toBe(2);
});

it('reorders without recreating rows', function () use ($variations, $imagesPickerState, $imagesPickerPersist) {
    $ids = $imagesPickerPersist($imagesPickerState(3), $variations);

    $state = ImagesPickerState::reordered(ImagesPickerState::fromStoredValue($ids), ["v{$ids[2]}", "v{$ids[0]}", "v{$ids[1]}"]);

    expect($imagesPickerPersist($state, $variations))->toBe([$ids[2], $ids[0], $ids[1]])
        ->and(MediaFieldValue::query()->count())->toBe(3);
});

it('persists twice without duplicating rows', function () use ($variations, $imagesPickerState, $imagesPickerPersist) {
    $ids = $imagesPickerPersist($imagesPickerState(2), $variations);

    expect($imagesPickerPersist(ImagesPickerState::fromStoredValue($ids), $variations))->toBe($ids)
        ->and(MediaFieldValue::query()->count())->toBe(2)
        ->and(MediaConversion::query()->count())->toBe(4);
});

it('creates conversions for each variation', function () use ($variations, $imagesPickerState, $imagesPickerPersist) {
    $ids = $imagesPickerPersist($imagesPickerState(2), $variations);

    foreach ($ids as $id) {
        $value = MediaFieldValue::with('conversions')->find($id);
        expect($value->conversions)->toHaveCount(2)
            ->and($value->conversion('desktop')->width)->toBe(1500)
            ->and($value->conversion('mobile')->height)->toBe(640);
    }
});

it('passes a plain list of ids through', function () use ($variations, $imagesPickerPersist) {
    expect($imagesPickerPersist(['3', 5], $variations))->toBe([3, 5])
        ->and($imagesPickerPersist(null, $variations))->toBe([]);
});

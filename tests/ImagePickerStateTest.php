<?php

use MarvelousDigital\FilamentMediaManager\Models\Media;
use MarvelousDigital\FilamentMediaManager\Models\MediaConversion;
use MarvelousDigital\FilamentMediaManager\Models\MediaFieldValue;
use MarvelousDigital\FilamentMediaManager\Support\ImagePickerState;

$variations = ['desktop' => [1500, 900], 'mobile' => [480, 640]];

it('hydrates a saved value and syncs declared variations', function () use ($variations) {
    $override = Media::factory()->create();
    $value = MediaFieldValue::factory()->for(Media::factory(), 'source')->create(['alt_text' => 'Main']);
    MediaConversion::factory()->for($value, 'value')->create([
        'variation' => 'mobile', 'media_id' => $override->id, 'alt_text' => 'Own',
        'focus_x' => 0.2, 'focus_y' => 0.3, 'zoom' => 2.0,
    ]);
    MediaConversion::factory()->for($value, 'value')->create(['variation' => 'retired']);

    $state = ImagePickerState::fromStoredValue($value->id, $variations);

    expect($state)->toMatchArray(['id' => $value->id, 'media_id' => $value->media_id, 'alt' => 'Main', 'dirty' => false])
        ->and(array_keys($state['variants']))->toBe(['desktop', 'mobile'])
        ->and($state['variants']['desktop'])->toBe(ImagePickerState::defaultVariant())
        ->and($state['variants']['mobile'])->toBe([
            'media_id' => $override->id, 'alt' => 'Own', 'focus_x' => 0.2, 'focus_y' => 0.3, 'zoom' => 2.0,
        ]);
});

it('returns null for empty or missing stored values', function () use ($variations) {
    expect(ImagePickerState::fromStoredValue(null, $variations))->toBeNull()
        ->and(ImagePickerState::fromStoredValue(999999, $variations))->toBeNull()
        ->and(ImagePickerState::fromStoredValue('nonsense', $variations))->toBeNull();
});

it('keeps already-hydrated state and resyncs its variations', function () use ($variations) {
    $state = ['id' => 5, 'media_id' => 7, 'alt' => null, 'dirty' => true, 'variants' => [
        'desktop' => ['media_id' => null, 'alt' => null, 'focus_x' => 0.1, 'focus_y' => 0.1, 'zoom' => 1.0],
        'retired' => ImagePickerState::defaultVariant(),
    ]];

    $synced = ImagePickerState::fromStoredValue($state, $variations);

    expect($synced['dirty'])->toBeTrue()
        ->and(array_keys($synced['variants']))->toBe(['desktop', 'mobile'])
        ->and($synced['variants']['desktop']['focus_x'])->toBe(0.1);
});

it('picks a main image, copying alt and resetting non-overridden variants', function () use ($variations) {
    $override = Media::factory()->create();
    $newMain = Media::factory()->create(['alt_text' => 'New main']);
    $state = ['id' => 5, 'media_id' => 7, 'alt' => 'Old', 'dirty' => false, 'variants' => [
        'desktop' => ['media_id' => null, 'alt' => null, 'focus_x' => 0.1, 'focus_y' => 0.9, 'zoom' => 3.0],
        'mobile' => ['media_id' => $override->id, 'alt' => 'Own', 'focus_x' => 0.2, 'focus_y' => 0.2, 'zoom' => 2.0],
    ]];

    $next = ImagePickerState::withMainImage($state, $newMain, $variations);

    expect($next)->toMatchArray(['id' => 5, 'media_id' => $newMain->id, 'alt' => 'New main', 'dirty' => true])
        ->and($next['variants']['desktop'])->toBe(ImagePickerState::defaultVariant())
        ->and($next['variants']['mobile'])->toBe($state['variants']['mobile']);
});

it('picks a main image into an empty field', function () use ($variations) {
    $media = Media::factory()->create(['alt_text' => 'Cat']);

    $state = ImagePickerState::withMainImage(null, $media, $variations);

    expect($state)->toMatchArray(['id' => null, 'media_id' => $media->id, 'alt' => 'Cat', 'dirty' => true])
        ->and(array_keys($state['variants']))->toBe(['desktop', 'mobile']);
});

it('overrides and resets a single variant', function () use ($variations) {
    $main = Media::factory()->create();
    $override = Media::factory()->create(['alt_text' => 'Speaker']);
    $state = ImagePickerState::withMainImage(null, $main, $variations);

    $overridden = ImagePickerState::withVariantImage($state, 'mobile', $override);
    $reset = ImagePickerState::withoutVariantImage($overridden, 'mobile');

    expect($overridden['variants']['mobile'])->toMatchArray(['media_id' => $override->id, 'alt' => 'Speaker', 'zoom' => 1.0])
        ->and($overridden['variants']['desktop'])->toBe(ImagePickerState::defaultVariant())
        ->and($reset['variants']['mobile'])->toBe(ImagePickerState::defaultVariant())
        ->and($reset['dirty'])->toBeTrue();
});

it('ignores overrides for undeclared variants', function () use ($variations) {
    $state = ImagePickerState::withMainImage(null, Media::factory()->create(), $variations);

    expect(ImagePickerState::withVariantImage($state, 'nope', Media::factory()->create()))->toBe($state);
});

it('removes an unsaved image entirely and marks a saved one for deletion', function () {
    expect(ImagePickerState::withoutImage(null))->toBeNull()
        ->and(ImagePickerState::withoutImage(['id' => null, 'media_id' => 3]))->toBeNull()
        ->and(ImagePickerState::withoutImage(['id' => 5, 'media_id' => 3]))
        ->toBe(['id' => 5, 'media_id' => null, 'alt' => null, 'dirty' => true, 'variants' => []]);
});

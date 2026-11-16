<?php

use Illuminate\Support\Facades\Queue;
use MarvelousDigital\FilamentMediaManager\Actions\DuplicateMediaFieldValue;
use MarvelousDigital\FilamentMediaManager\Actions\PersistImagePicker;
use MarvelousDigital\FilamentMediaManager\Models\Media;
use MarvelousDigital\FilamentMediaManager\Models\MediaConversion;
use MarvelousDigital\FilamentMediaManager\Models\MediaFieldValue;
use MarvelousDigital\FilamentMediaManager\Support\ImagePickerState;

beforeEach(fn () => Queue::fake());

$variations = ['desktop' => [1500, 900], 'mobile' => [480, 640]];

it('starts an image as not decorative and hydrates the flag', function () use ($variations) {
    expect(ImagePickerState::withMainImage(null, Media::factory()->create(), $variations)['decorative'])->toBeFalse();

    $value = MediaFieldValue::factory()->create(['decorative' => true]);

    expect(ImagePickerState::fromStoredValue($value->id, $variations)['decorative'])->toBeTrue();
});

it('verified coerces the decorative flag', function () use ($variations) {
    $media = Media::factory()->create();

    expect(ImagePickerState::verified(['media_id' => $media->id, 'decorative' => 'true'], $variations)['decorative'])->toBeTrue()
        ->and(ImagePickerState::verified(['media_id' => $media->id, 'decorative' => 'nope'], $variations)['decorative'])->toBeFalse()
        ->and(ImagePickerState::verified(['media_id' => $media->id], $variations)['decorative'])->toBeFalse();
});

it('saves the decorative flag and keeps the typed alt texts', function () use ($variations) {
    $state = ImagePickerState::withMainImage(null, Media::factory()->create(['alt_text' => 'Cat']), $variations);
    $state['decorative'] = true;

    $value = MediaFieldValue::find(app(PersistImagePicker::class)->execute($state, $variations));

    expect($value->decorative)->toBeTrue()
        ->and($value->alt_text)->toBe('Cat');
});

it('gives a decorative image an empty alt text for every variation', function () {
    $value = MediaFieldValue::factory()->create(['alt_text' => 'Cat', 'decorative' => true]);
    $own = MediaConversion::factory()->for($value, 'value')->create(['variation' => 'mobile', 'media_id' => Media::factory()->create()->id, 'alt_text' => 'Own']);

    expect($value->altFor())->toBe('')
        ->and($value->altFor('mobile'))->toBe('')
        ->and($value->altFor('missing'))->toBe('')
        ->and($own->effectiveAltText())->toBe('');
});

it('copies the decorative flag when duplicating', function () {
    $value = MediaFieldValue::factory()->create(['decorative' => true]);

    $copy = MediaFieldValue::find(app(DuplicateMediaFieldValue::class)->execute($value->id));

    expect($copy->decorative)->toBeTrue();
});

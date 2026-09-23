<?php

use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use MarvelousDigital\FilamentMediaManager\Actions\PersistImagePicker;
use MarvelousDigital\FilamentMediaManager\Enums\ConversionStatus;
use MarvelousDigital\FilamentMediaManager\Jobs\GenerateConversion;
use MarvelousDigital\FilamentMediaManager\Models\Media;
use MarvelousDigital\FilamentMediaManager\Models\MediaConversion;
use MarvelousDigital\FilamentMediaManager\Models\MediaFieldValue;
use MarvelousDigital\FilamentMediaManager\Support\ImagePickerState;

beforeEach(fn () => Queue::fake());

$variations = ['desktop' => [1500, 900], 'mobile' => [480, 640]];

function persist(mixed $state, array $variations): ?int
{
    return app(PersistImagePicker::class)->execute($state, $variations);
}

it('creates a value with one pending conversion per variation', function () use ($variations) {
    $media = Media::factory()->create(['alt_text' => 'Cat']);

    $id = persist(ImagePickerState::withMainImage(null, $media, $variations), $variations);

    $value = MediaFieldValue::with('conversions')->find($id);
    expect($value->media_id)->toBe($media->id)
        ->and($value->alt_text)->toBe('Cat')
        ->and($value->conversions)->toHaveCount(2)
        ->and($value->conversion('desktop')->width)->toBe(1500)
        ->and($value->conversion('desktop')->status)->toBe(ConversionStatus::Pending);
    Queue::assertPushed(GenerateConversion::class, 2);
});

it('updates the existing row in place and only regenerates changed variants', function () use ($variations) {
    $media = Media::factory()->create();
    $id = persist(ImagePickerState::withMainImage(null, $media, $variations), $variations);
    MediaConversion::query()->update(['status' => ConversionStatus::Completed]);
    Queue::fake();

    $state = ImagePickerState::fromStoredValue($id, $variations);
    $state['alt'] = 'Edited';
    $state['variants']['mobile']['focus_x'] = 0.2;

    expect(persist($state, $variations))->toBe($id);

    $value = MediaFieldValue::with('conversions')->find($id);
    expect(MediaFieldValue::query()->count())->toBe(1)
        ->and($value->alt_text)->toBe('Edited')
        ->and($value->conversion('mobile')->status)->toBe(ConversionStatus::Pending)
        ->and($value->conversion('desktop')->status)->toBe(ConversionStatus::Completed);
    Queue::assertPushed(GenerateConversion::class, 1);
});

it('does not regenerate when only an override alt changes', function () use ($variations) {
    $override = Media::factory()->create();
    $state = ImagePickerState::withVariantImage(ImagePickerState::withMainImage(null, Media::factory()->create(), $variations), 'mobile', $override);
    $id = persist($state, $variations);
    Queue::fake();

    $state = ImagePickerState::fromStoredValue($id, $variations);
    $state['variants']['mobile']['alt'] = 'Speaker';
    persist($state, $variations);

    expect(MediaFieldValue::find($id)->conversions()->where('variation', 'mobile')->value('alt_text'))->toBe('Speaker');
    Queue::assertNothingPushed();
});

it('regenerates only non-overridden variants when the main image changes', function () use ($variations) {
    $override = Media::factory()->create();
    $state = ImagePickerState::withVariantImage(ImagePickerState::withMainImage(null, Media::factory()->create(), $variations), 'mobile', $override);
    $id = persist($state, $variations);
    Queue::fake();

    $next = ImagePickerState::withMainImage(ImagePickerState::fromStoredValue($id, $variations), Media::factory()->create(), $variations);
    persist($next, $variations);

    Queue::assertPushed(GenerateConversion::class, 1);
    Queue::assertPushed(GenerateConversion::class, fn (GenerateConversion $job) => $job->conversion->variation === 'desktop');
});

it('stores override image and alt, and drops alt without an override', function () use ($variations) {
    $override = Media::factory()->create(['alt_text' => 'Speaker']);
    $state = ImagePickerState::withVariantImage(ImagePickerState::withMainImage(null, Media::factory()->create(), $variations), 'mobile', $override);
    $state['variants']['desktop']['alt'] = 'Should be dropped';

    $value = MediaFieldValue::with('conversions')->find(persist($state, $variations));

    expect($value->conversion('mobile')->media_id)->toBe($override->id)
        ->and($value->conversion('mobile')->alt_text)->toBe('Speaker')
        ->and($value->conversion('desktop')->alt_text)->toBeNull();
});

it('clamps focus and zoom', function () use ($variations) {
    $state = ImagePickerState::withMainImage(null, Media::factory()->create(), $variations);
    $state['variants']['desktop'] = [...$state['variants']['desktop'], 'focus_x' => -1, 'focus_y' => 5, 'zoom' => 12];
    $state['variants']['mobile']['zoom'] = 0.2;

    $value = MediaFieldValue::with('conversions')->find(persist($state, $variations));

    expect($value->conversion('desktop')->focus_x)->toBe(0.0)
        ->and($value->conversion('desktop')->focus_y)->toBe(1.0)
        ->and($value->conversion('desktop')->zoom)->toBe(5.0)
        ->and($value->conversion('mobile')->zoom)->toBe(1.0);
});

it('deletes conversions for variations that are no longer declared', function () use ($variations) {
    Storage::fake('public');
    $id = persist(ImagePickerState::withMainImage(null, Media::factory()->create(), $variations), $variations);
    $retiredPath = MediaFieldValue::find($id)->directory() . '/mobile-old.webp';
    Storage::disk('public')->put($retiredPath, 'x');
    MediaConversion::query()->where('variation', 'mobile')->update(['path' => $retiredPath]);

    persist(ImagePickerState::fromStoredValue($id, ['desktop' => [1500, 900]]), ['desktop' => [1500, 900]]);

    expect(MediaConversion::query()->pluck('variation')->all())->toBe(['desktop']);
    Storage::disk('public')->assertMissing($retiredPath);
});

it('regenerates a variant whose declared size changed', function () use ($variations) {
    $id = persist(ImagePickerState::withMainImage(null, Media::factory()->create(), $variations), $variations);
    MediaConversion::query()->update(['status' => ConversionStatus::Completed]);
    Queue::fake();

    $resized = [...$variations, 'mobile' => [600, 800]];
    persist(ImagePickerState::fromStoredValue($id, $resized), $resized);

    expect(MediaConversion::query()->where('variation', 'mobile')->value('width'))->toBe(600);
    Queue::assertPushed(GenerateConversion::class, 1);
});

it('deletes the saved row when the image was removed', function () use ($variations) {
    $id = persist(ImagePickerState::withMainImage(null, Media::factory()->create(), $variations), $variations);

    $result = persist(ImagePickerState::withoutImage(ImagePickerState::fromStoredValue($id, $variations)), $variations);

    expect($result)->toBeNull()
        ->and(MediaFieldValue::query()->count())->toBe(0)
        ->and(MediaConversion::query()->count())->toBe(0);
});

it('persists nothing for an empty field', function () use ($variations) {
    expect(persist(null, $variations))->toBeNull()
        ->and(MediaFieldValue::query()->count())->toBe(0);
});

it('passes scalar ids through untouched', function () use ($variations) {
    expect(persist(42, $variations))->toBe(42)
        ->and(persist('42', $variations))->toBe(42)
        ->and(MediaFieldValue::query()->count())->toBe(0);
});

it('recreates the row when the saved id no longer exists', function () use ($variations) {
    $state = [...ImagePickerState::withMainImage(null, Media::factory()->create(), $variations), 'id' => 999999];

    $id = persist($state, $variations);

    expect($id)->not->toBe(999999)
        ->and(MediaFieldValue::query()->whereKey($id)->exists())->toBeTrue();
});

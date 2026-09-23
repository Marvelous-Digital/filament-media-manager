<?php

use Illuminate\Support\Facades\Storage;
use MarvelousDigital\FilamentMediaManager\Enums\ConversionStatus;
use MarvelousDigital\FilamentMediaManager\Models\Media;
use MarvelousDigital\FilamentMediaManager\Models\MediaConversion;
use MarvelousDigital\FilamentMediaManager\Models\MediaFieldValue;

it('casts focus and status', function () {
    $media = Media::factory()->create();
    $value = MediaFieldValue::factory()->for($media, 'source')->create();
    $conversion = MediaConversion::factory()->for($value, 'value')->create([
        'focus_x' => 0.25,
        'status' => ConversionStatus::Pending,
    ]);

    expect($conversion->focus_x)->toBe(0.25)
        ->and($conversion->status)->toBe(ConversionStatus::Pending)
        ->and($conversion->value->is($value))->toBeTrue();
});

it('resolves a url from the source disk when a path is set', function () {
    Storage::fake('public');
    Storage::disk('public')->put('media/01V/hero.webp', 'x');

    $media = Media::factory()->create(['disk' => 'public']);
    $value = MediaFieldValue::factory()->for($media, 'source')->create();
    $conversion = MediaConversion::factory()->for($value, 'value')->create([
        'path' => 'media/01V/hero.webp',
    ]);

    expect($conversion->url())->toContain('media/01V/hero.webp');
});

it('appends a cache-busting version tied to updated_at so regenerated files bust the browser cache', function () {
    Storage::fake('public');
    Storage::disk('public')->put('media/01V/hero.webp', 'x');

    $media = Media::factory()->create(['disk' => 'public']);
    $value = MediaFieldValue::factory()->for($media, 'source')->create();
    $conversion = MediaConversion::factory()->for($value, 'value')->create([
        'path' => 'media/01V/hero.webp',
    ]);

    expect($conversion->url())->toContain('v=' . $conversion->updated_at->timestamp);

    $before = $conversion->url();
    MediaConversion::whereKey($conversion->id)->update(['updated_at' => $conversion->updated_at->addMinute()]);

    expect($conversion->fresh()->url())->not->toBe($before);
});

it('returns null url when no path', function () {
    $media = Media::factory()->create();
    $value = MediaFieldValue::factory()->for($media, 'source')->create();
    $conversion = MediaConversion::factory()->for($value, 'value')->create(['path' => null]);

    expect($conversion->url())->toBeNull();
});

it('returns null max safe zoom when the source dimensions are unknown', function () {
    $media = Media::factory()->create(['width' => null, 'height' => null]);
    $value = MediaFieldValue::factory()->for($media, 'source')->create();
    $conversion = MediaConversion::factory()->for($value, 'value')->create(['width' => 400, 'height' => 400]);

    expect($conversion->maxSafeZoom())->toBeNull();
});

it('computes the max safe zoom from the base cover crop', function () {
    // 1000x500 source, 400x400 target → base cover crop is 500 wide; 500 / 400 = 1.25.
    $media = Media::factory()->create(['width' => 1000, 'height' => 500]);
    $value = MediaFieldValue::factory()->for($media, 'source')->create();
    $conversion = MediaConversion::factory()->for($value, 'value')->create(['width' => 400, 'height' => 400]);

    expect($conversion->maxSafeZoom())->toBe(1.25);
});

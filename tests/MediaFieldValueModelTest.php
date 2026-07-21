<?php

use Illuminate\Support\Facades\Storage;
use MarvelousDigital\FilamentMediaManager\Models\Media;
use MarvelousDigital\FilamentMediaManager\Models\MediaConversion;
use MarvelousDigital\FilamentMediaManager\Models\MediaFieldValue;

it('relates to source media and conversions', function () {
    $media = Media::factory()->create();
    $value = MediaFieldValue::factory()->for($media, 'source')->create();
    MediaConversion::factory()->for($value, 'value')->create(['variation' => 'hero']);
    MediaConversion::factory()->for($value, 'value')->create(['variation' => 'thumb']);

    expect($value->source->is($media))->toBeTrue()
        ->and($value->conversions)->toHaveCount(2)
        ->and($value->conversion('hero')?->variation)->toBe('hero')
        ->and($value->conversion('missing'))->toBeNull();
});

it('exposes the original url via source', function () {
    Storage::fake('public');
    Storage::disk('public')->put('media/01A/photo.jpg', 'x');
    $media = Media::factory()->create(['disk' => 'public', 'path' => 'media/01A/photo.jpg']);
    $value = MediaFieldValue::factory()->for($media, 'source')->create();

    expect($value->url())->toContain('media/01A/photo.jpg');
});

it('cleans up conversion files on delete but leaves the original', function () {
    Storage::fake('public');
    $media = Media::factory()->create(['disk' => 'public', 'path' => 'media/01A/photo.jpg']);
    Storage::disk('public')->put('media/01A/photo.jpg', 'original');
    $value = MediaFieldValue::factory()->for($media, 'source')->create();
    Storage::disk('public')->put($value->directory() . '/hero.webp', 'crop');

    $value->delete();

    Storage::disk('public')->assertMissing($value->directory() . '/hero.webp');
    Storage::disk('public')->assertExists('media/01A/photo.jpg');
});

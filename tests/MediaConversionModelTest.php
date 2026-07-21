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

it('returns null url when no path', function () {
    $media = Media::factory()->create();
    $value = MediaFieldValue::factory()->for($media, 'source')->create();
    $conversion = MediaConversion::factory()->for($value, 'value')->create(['path' => null]);

    expect($conversion->url())->toBeNull();
});

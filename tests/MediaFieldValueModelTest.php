<?php

use Illuminate\Support\Facades\Storage;
use MarvelousDigital\FilamentMediaManager\Enums\ConversionStatus;
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

it('keeps conversions in a directory independent of the main image', function () {
    config()->set('filament-media-manager.directory', 'media');
    $value = MediaFieldValue::factory()->for(Media::factory(), 'source')->create();

    expect($value->directory())->toBe("media/conversions/{$value->id}");
});

describe('render helpers', function () {
    beforeEach(function () {
        Storage::fake('public');
        Storage::disk('public')->put('media/01A/photo.jpg', 'x');
        Storage::disk('public')->put('media/01V/hero.webp', 'x');
        $this->value = MediaFieldValue::factory()
            ->for(Media::factory()->create(['disk' => 'public', 'path' => 'media/01A/photo.jpg']), 'source')
            ->create(['alt_text' => 'Value alt']);
    });

    it('returns the source url without a variation', function () {
        expect($this->value->urlFor())->toBe($this->value->url())
            ->and($this->value->urlFor(null))->toContain('media/01A/photo.jpg');
    });

    it('returns the conversion url for a generated variation', function () {
        MediaConversion::factory()->for($this->value, 'value')->create([
            'variation' => 'hero',
            'path' => 'media/01V/hero.webp',
            'status' => ConversionStatus::Completed,
        ]);
        $this->value->load('conversions');

        expect($this->value->urlFor('hero'))->toContain('media/01V/hero.webp');
    });

    it('falls back to the source url while the conversion is pending', function () {
        MediaConversion::factory()->for($this->value, 'value')->create([
            'variation' => 'hero',
            'path' => null,
            'status' => ConversionStatus::Pending,
        ]);
        $this->value->load('conversions');

        expect($this->value->urlFor('hero'))->toBe($this->value->url());
    });

    it('falls back to the source url for an unknown variation', function () {
        expect($this->value->urlFor('nope'))->toBe($this->value->url());
    });

    it('uses the override alt text of a variation', function () {
        MediaConversion::factory()->for($this->value, 'value')->create([
            'variation' => 'hero',
            'media_id' => Media::factory()->create()->id,
            'alt_text' => 'Hero alt',
        ]);
        $this->value->load('conversions');

        expect($this->value->altFor('hero'))->toBe('Hero alt')
            ->and($this->value->altFor('nope'))->toBe('Value alt');
    });

    it('uses the value alt text without a variation', function () {
        expect($this->value->altFor())->toBe('Value alt');
    });
});

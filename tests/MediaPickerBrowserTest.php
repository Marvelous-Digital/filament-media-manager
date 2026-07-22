<?php

use Livewire\Livewire;
use MarvelousDigital\FilamentMediaManager\Enums\MediaType;
use MarvelousDigital\FilamentMediaManager\Livewire\MediaPickerBrowser;
use MarvelousDigital\FilamentMediaManager\Models\Media;
use MarvelousDigital\FilamentMediaManager\Models\MediaFieldValue;

it('lists only images', function () {
    Media::factory()->create(['name' => 'pic', 'type' => MediaType::Image]);
    Media::factory()->create(['name' => 'clip', 'type' => MediaType::Video, 'mime_type' => 'video/mp4']);

    Livewire::test(MediaPickerBrowser::class, ['variations' => ['hero' => [1600, 600]], 'statePath' => 'data.hero_image_id'])
        ->assertSee('pic')
        ->assertDontSee('clip');
});

it('creates a usage record and dispatches selection on select', function () {
    $media = Media::factory()->create(['type' => MediaType::Image, 'alt_text' => 'Cat']);

    Livewire::test(MediaPickerBrowser::class, ['variations' => ['hero' => [1600, 600]], 'statePath' => 'data.hero_image_id'])
        ->call('select', $media->id)
        ->assertDispatched('image-picker:selected');

    $value = MediaFieldValue::query()->latest('id')->first();
    expect($value->media_id)->toBe($media->id)
        ->and($value->alt_text)->toBe('Cat')
        ->and($value->conversion('hero'))->not->toBeNull();
});

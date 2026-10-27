<?php

use Livewire\Livewire;
use MarvelousDigital\FilamentMediaManager\Enums\MediaType;
use MarvelousDigital\FilamentMediaManager\Livewire\MediaPickerBrowser;
use MarvelousDigital\FilamentMediaManager\Models\Media;
use MarvelousDigital\FilamentMediaManager\Models\MediaFieldValue;

it('lists only images', function () {
    Media::factory()->create(['name' => 'pic', 'type' => MediaType::Image]);
    Media::factory()->create(['name' => 'clip', 'type' => MediaType::Video, 'mime_type' => 'video/mp4']);

    Livewire::test(MediaPickerBrowser::class, ['statePath' => 'data.hero_image_id', 'target' => 'main'])
        ->assertSee('pic')
        ->assertDontSee('clip');
});

it('dispatches the selection with its target and persists nothing', function () {
    $media = Media::factory()->create(['type' => MediaType::Image]);

    Livewire::test(MediaPickerBrowser::class, ['statePath' => 'data.hero', 'target' => 'mobile'])
        ->call('select', $media->id)
        ->assertDispatched('image-picker:selected', statePath: 'data.hero', target: 'mobile', mediaId: $media->id)
        ->assertDispatched('image-picker:close');

    expect(MediaFieldValue::query()->count())->toBe(0);
});

it('keeps the browser open when appending', function () {
    $media = Media::factory()->create(['type' => MediaType::Image]);

    Livewire::test(MediaPickerBrowser::class, ['statePath' => 'data.gallery', 'target' => 'append'])
        ->call('select', $media->id)
        ->assertDispatched('image-picker:selected', statePath: 'data.gallery', target: 'append', mediaId: $media->id)
        ->assertNotDispatched('image-picker:close');

    Livewire::test(MediaPickerBrowser::class, ['statePath' => 'data.hero', 'target' => 'main'])
        ->call('select', $media->id)
        ->assertDispatched('image-picker:close');
});

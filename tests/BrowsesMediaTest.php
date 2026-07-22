<?php

use Livewire\Livewire;
use MarvelousDigital\FilamentMediaManager\Enums\MediaType;
use MarvelousDigital\FilamentMediaManager\Models\Media;
use MarvelousDigital\FilamentMediaManager\Pages\MediaLibrary;

beforeEach(function () {
    $this->actingAsUser();
});

it('media library still lists all media types (imagesOnly is off)', function () {
    Media::factory()->create(['name' => 'pic', 'type' => MediaType::Image]);
    Media::factory()->create(['name' => 'clip', 'type' => MediaType::Video, 'mime_type' => 'video/mp4']);

    Livewire::test(MediaLibrary::class)
        ->assertSee('pic')
        ->assertSee('clip');
});

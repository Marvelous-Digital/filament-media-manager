<?php

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
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
        ->assertDispatched('image-picker:close', statePath: 'data.hero');

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

it('lists only the given mime types', function () {
    Media::factory()->create(['name' => 'contract', 'type' => MediaType::Document, 'mime_type' => 'application/pdf']);
    Media::factory()->create(['name' => 'sunset-photo', 'type' => MediaType::Image]);
    Media::factory()->create(['name' => 'quarterly-docx', 'type' => MediaType::Document, 'mime_type' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document']);

    Livewire::test(MediaPickerBrowser::class, ['statePath' => 'data.file', 'target' => 'main', 'mimeTypes' => ['application/pdf']])
        ->assertSee('contract')
        ->assertDontSee('sunset-photo')
        ->assertDontSee('quarterly-docx');
});

it('refuses an upload outside the mime types', function () {
    Storage::fake(config('filament-media-manager.disk'));

    Livewire::test(MediaPickerBrowser::class, ['statePath' => 'data.file', 'target' => 'main', 'mimeTypes' => ['application/pdf']])
        ->set('uploads', [UploadedFile::fake()->image('photo.png')])
        ->assertHasErrors('uploads');

    expect(Media::query()->count())->toBe(0);
});

it('refuses to select a media outside the mime types', function () {
    $image = Media::factory()->create();

    Livewire::test(MediaPickerBrowser::class, ['statePath' => 'data.file', 'target' => 'main', 'mimeTypes' => ['application/pdf']])
        ->call('select', $image->id)
        ->assertNotDispatched('image-picker:selected');
});

it('refuses to select a non-image when only images are allowed', function () {
    $pdf = Media::factory()->create(['type' => MediaType::Document, 'mime_type' => 'application/pdf']);

    Livewire::test(MediaPickerBrowser::class, ['statePath' => 'data.hero', 'target' => 'main'])
        ->call('select', $pdf->id)
        ->assertNotDispatched('image-picker:selected');
});

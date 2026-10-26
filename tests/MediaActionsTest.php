<?php

use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use MarvelousDigital\FilamentMediaManager\Enums\ThumbnailStatus;
use MarvelousDigital\FilamentMediaManager\Jobs\GenerateThumbnail;
use MarvelousDigital\FilamentMediaManager\Models\Media;
use MarvelousDigital\FilamentMediaManager\Models\MediaFolder;
use MarvelousDigital\FilamentMediaManager\Pages\MediaLibrary;

beforeEach(function () {
    Storage::fake('public');
    $this->actingAsUser();
});

it('edits name, alt text, and folder', function () {
    $media = Media::factory()->create();
    $folder = MediaFolder::create(['name' => 'Target']);

    Livewire::test(MediaLibrary::class)
        ->callAction('editMedia', [
            'name' => 'Renamed',
            'alt_text' => 'A description',
            'folder_id' => $folder->id,
        ], arguments: ['media' => $media->id]);

    $media->refresh();

    expect($media->name)->toBe('Renamed')
        ->and($media->alt_text)->toBe('A description')
        ->and($media->folder_id)->toBe($folder->id);
});

it('prefills the edit form', function () {
    $media = Media::factory()->create(['name' => 'Original', 'alt_text' => 'alt']);

    Livewire::test(MediaLibrary::class)
        ->mountAction('editMedia', arguments: ['media' => $media->id])
        ->assertSchemaStateSet(['name' => 'Original', 'alt_text' => 'alt']);
});

it('does not throw when mounting editMedia for a nonexistent media id', function () {
    Livewire::test(MediaLibrary::class)
        ->mountAction('editMedia', arguments: ['media' => 999999])
        ->assertOk();
});

it('deletes media and its files', function () {
    Storage::disk('public')->put('media/01DEL/x.jpg', 'bytes');
    $media = Media::factory()->create(['disk' => 'public', 'path' => 'media/01DEL/x.jpg']);

    Livewire::test(MediaLibrary::class)
        ->callAction('deleteMedia', arguments: ['media' => $media->id]);

    expect(Media::query()->count())->toBe(0);
    Storage::disk('public')->assertMissing('media/01DEL/x.jpg');
});

it('downloads the original file', function () {
    Storage::disk('public')->put('media/01DL/x.jpg', 'bytes');
    $media = Media::factory()->create(['disk' => 'public', 'path' => 'media/01DL/x.jpg', 'file_name' => 'x.jpg']);

    Livewire::test(MediaLibrary::class)
        ->callAction('downloadMedia', arguments: ['media' => $media->id])
        ->assertFileDownloaded('x.jpg');
});

it('retries a failed thumbnail', function () {
    Queue::fake();
    $media = Media::factory()->create(['thumbnail_status' => ThumbnailStatus::Failed]);

    Livewire::test(MediaLibrary::class)
        ->callAction('retryThumbnail', arguments: ['media' => $media->id]);

    expect($media->refresh()->thumbnail_status)->toBe(ThumbnailStatus::Pending);
    Queue::assertPushed(GenerateThumbnail::class);
});

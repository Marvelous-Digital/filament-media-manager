<?php

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use MarvelousDigital\FilamentMediaManager\Enums\ThumbnailStatus;
use MarvelousDigital\FilamentMediaManager\Jobs\GenerateThumbnail;
use MarvelousDigital\FilamentMediaManager\Models\Media;

function createStoredImage(int $width = 1200, int $height = 800): Media
{
    Storage::fake('public');

    $file = UploadedFile::fake()->image('photo.jpg', $width, $height);
    $path = $file->storeAs('media/01JOB', 'photo.jpg', ['disk' => 'public']);

    return Media::factory()->create([
        'disk' => 'public',
        'path' => $path,
        'mime_type' => 'image/jpeg',
    ]);
}

it('generates a webp thumbnail and records dimensions', function () {
    $media = createStoredImage(1200, 800);

    (new GenerateThumbnail($media))->handle();

    $media->refresh();

    expect($media->thumbnail_status)->toBe(ThumbnailStatus::Completed)
        ->and($media->thumbnail_path)->toBe('media/01JOB/thumb.webp')
        ->and($media->width)->toBe(1200)
        ->and($media->height)->toBe(800);

    Storage::disk('public')->assertExists('media/01JOB/thumb.webp');
});

it('marks the media failed when the source is not a readable image', function () {
    Storage::fake('public');
    Storage::disk('public')->put('media/01BAD/broken.jpg', 'not-an-image');

    $media = Media::factory()->create([
        'disk' => 'public',
        'path' => 'media/01BAD/broken.jpg',
        'mime_type' => 'image/jpeg',
    ]);

    (new GenerateThumbnail($media))->handle();

    expect($media->refresh()->thumbnail_status)->toBe(ThumbnailStatus::Failed);
});

it('dispatches queued when configured', function () {
    Queue::fake();
    config()->set('filament-media-manager.thumbnail.queue', true);

    $media = createStoredImage();
    GenerateThumbnail::dispatchFor($media);

    Queue::assertPushed(GenerateThumbnail::class);
});

it('runs synchronously when queueing is disabled', function () {
    config()->set('filament-media-manager.thumbnail.queue', false);

    $media = createStoredImage();
    GenerateThumbnail::dispatchFor($media);

    expect($media->refresh()->thumbnail_status)->toBe(ThumbnailStatus::Completed);
});

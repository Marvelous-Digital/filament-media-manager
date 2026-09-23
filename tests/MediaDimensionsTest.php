<?php

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use MarvelousDigital\FilamentMediaManager\Enums\MediaType;
use MarvelousDigital\FilamentMediaManager\Models\Media;

it('measures and stores missing dimensions', function () {
    Storage::fake('public');
    $path = UploadedFile::fake()->image('p.jpg', 640, 480)->storeAs('media/01D', 'p.jpg', ['disk' => 'public']);
    $media = Media::factory()->create(['disk' => 'public', 'path' => $path, 'width' => null, 'height' => null]);

    $media->ensureDimensions();

    expect($media->refresh()->width)->toBe(640)->and($media->height)->toBe(480);
});

it('leaves known dimensions untouched', function () {
    $media = Media::factory()->create(['width' => 10, 'height' => 20]);

    $media->ensureDimensions();

    expect($media->refresh()->width)->toBe(10)->and($media->height)->toBe(20);
});

it('ignores unreadable or non-image files', function () {
    Storage::fake('public');
    Storage::disk('public')->put('media/01X/broken.jpg', 'not-an-image');
    $broken = Media::factory()->create(['disk' => 'public', 'path' => 'media/01X/broken.jpg']);
    $video = Media::factory()->create(['type' => MediaType::Video, 'mime_type' => 'video/mp4']);

    $broken->ensureDimensions();
    $video->ensureDimensions();

    expect($broken->refresh()->width)->toBeNull()->and($video->refresh()->width)->toBeNull();
});

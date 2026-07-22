<?php

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use MarvelousDigital\FilamentMediaManager\Enums\ConversionStatus;
use MarvelousDigital\FilamentMediaManager\Jobs\GenerateConversion;
use MarvelousDigital\FilamentMediaManager\Models\Media;
use MarvelousDigital\FilamentMediaManager\Models\MediaConversion;
use MarvelousDigital\FilamentMediaManager\Models\MediaFieldValue;

function makeConversion(int $w = 1200, int $h = 800, int $tw = 400, int $th = 400): MediaConversion
{
    Storage::fake('public');
    $file = UploadedFile::fake()->image('photo.jpg', $w, $h);
    $path = $file->storeAs('media/01CONV', 'photo.jpg', ['disk' => 'public']);

    $media = Media::factory()->create(['disk' => 'public', 'path' => $path, 'mime_type' => 'image/jpeg']);
    $value = MediaFieldValue::factory()->for($media, 'source')->create();

    return MediaConversion::factory()->for($value, 'value')->create([
        'variation' => 'thumb', 'width' => $tw, 'height' => $th,
        'focus_x' => 0.5, 'focus_y' => 0.5, 'status' => ConversionStatus::Pending,
    ]);
}

it('generates a cropped conversion at the target dimensions', function () {
    $conversion = makeConversion(1200, 800, 400, 400);

    (new GenerateConversion($conversion))->handle();

    $conversion->refresh();

    expect($conversion->status)->toBe(ConversionStatus::Completed)
        ->and($conversion->path)->toBe($conversion->value->directory() . '/thumb.webp');

    Storage::disk('public')->assertExists($conversion->path);

    [$w, $h] = getimagesize(Storage::disk('public')->path($conversion->path));
    expect($w)->toBe(400)->and($h)->toBe(400);
});

it('marks the conversion failed when the source is not a readable image', function () {
    Storage::fake('public');
    Storage::disk('public')->put('media/01BAD/broken.jpg', 'not-an-image');
    $media = Media::factory()->create(['disk' => 'public', 'path' => 'media/01BAD/broken.jpg']);
    $value = MediaFieldValue::factory()->for($media, 'source')->create();
    $conversion = MediaConversion::factory()->for($value, 'value')->create(['status' => ConversionStatus::Pending]);

    (new GenerateConversion($conversion))->handle();

    expect($conversion->refresh()->status)->toBe(ConversionStatus::Failed);
});

it('dispatches queued when configured', function () {
    Queue::fake();
    config()->set('filament-media-manager.thumbnail.queue', true);
    $conversion = makeConversion();

    GenerateConversion::dispatchFor($conversion);

    Queue::assertPushed(GenerateConversion::class);
});

it('runs synchronously when queueing is disabled', function () {
    config()->set('filament-media-manager.thumbnail.queue', false);
    $conversion = makeConversion();

    GenerateConversion::dispatchFor($conversion);

    expect($conversion->refresh()->status)->toBe(ConversionStatus::Completed);
});

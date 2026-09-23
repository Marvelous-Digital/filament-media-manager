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
        ->and($conversion->path)->toBe($conversion->value->directory() . '/thumb-' . $conversion->fingerprint() . '.webp');

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

it('crops from the override image when one is set', function () {
    $conversion = makeConversion(1200, 800, 400, 400);
    $overridePath = UploadedFile::fake()->image('tall.jpg', 300, 900)->storeAs('media/01OVR', 'tall.jpg', ['disk' => 'public']);
    $override = Media::factory()->create(['disk' => 'public', 'path' => $overridePath, 'mime_type' => 'image/jpeg']);
    $conversion->update(['media_id' => $override->id, 'width' => 300, 'height' => 900]);

    (new GenerateConversion($conversion))->handle();

    [$w, $h] = getimagesize(Storage::disk('public')->path($conversion->refresh()->path));
    expect($conversion->status)->toBe(ConversionStatus::Completed)
        ->and($conversion->path)->toContain('thumb-' . $conversion->fingerprint())
        ->and([$w, $h])->toBe([300, 900]);
});

it('replaces the previous file only after the new one is stored', function () {
    $conversion = makeConversion();
    (new GenerateConversion($conversion))->handle();
    $firstPath = $conversion->refresh()->path;

    $conversion->update(['focus_x' => 0.1]);
    (new GenerateConversion($conversion))->handle();

    $conversion->refresh();
    expect($conversion->path)->not->toBe($firstPath);
    Storage::disk('public')->assertExists($conversion->path);
    Storage::disk('public')->assertMissing($firstPath);
});

it('keeps the previous file when regeneration fails', function () {
    $conversion = makeConversion();
    (new GenerateConversion($conversion))->handle();
    $livePath = $conversion->refresh()->path;

    Storage::disk('public')->put($conversion->value->source->path, 'corrupted');
    $conversion->update(['focus_x' => 0.1]);
    (new GenerateConversion($conversion))->handle();

    expect($conversion->refresh()->status)->toBe(ConversionStatus::Failed)
        ->and($conversion->path)->toBe($livePath);
    Storage::disk('public')->assertExists($livePath);
});

it('does nothing when the conversion was deleted before the job ran', function () {
    $conversion = makeConversion();
    $job = new GenerateConversion($conversion);
    MediaConversion::query()->whereKey($conversion->id)->delete();

    $job->handle();

    expect(MediaConversion::query()->count())->toBe(0);
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

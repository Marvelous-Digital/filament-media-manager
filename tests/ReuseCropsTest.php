<?php

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use MarvelousDigital\FilamentMediaManager\Actions\PersistImagePicker;
use MarvelousDigital\FilamentMediaManager\Enums\ConversionStatus;
use MarvelousDigital\FilamentMediaManager\Jobs\GenerateConversion;
use MarvelousDigital\FilamentMediaManager\Models\Media;
use MarvelousDigital\FilamentMediaManager\Models\MediaFieldValue;
use MarvelousDigital\FilamentMediaManager\Support\ImagePickerState;

$variations = ['desktop' => [600, 300], 'mobile' => [200, 300]];

function reuseImage(string $name = 'photo.jpg'): Media
{
    $path = UploadedFile::fake()->image($name, 1200, 800)->storeAs('media/' . uniqid(), $name, ['disk' => 'public']);

    return Media::factory()->create(['disk' => 'public', 'path' => $path, 'mime_type' => 'image/jpeg', 'width' => 1200, 'height' => 800]);
}

/** A stored row whose crops are generated, and the picker state of it. */
function storedWithCrops(array $variations): array
{
    config()->set('filament-media-manager.thumbnail.queue', false);
    $id = app(PersistImagePicker::class)->execute(ImagePickerState::withMainImage(null, reuseImage(), $variations), $variations);
    config()->set('filament-media-manager.thumbnail.queue', true);

    return [MediaFieldValue::with('conversions')->find($id), ImagePickerState::fromStoredValue($id, $variations)];
}

beforeEach(fn () => Storage::fake('public'));

it('copies the file of an unchanged crop into the row that replaces it', function () use ($variations) {
    [$stored, $state] = storedWithCrops($variations);
    Queue::fake();

    $id = app(PersistImagePicker::class)->execute([...$state, 'id' => null, 'token' => null, 'alt' => 'New alt'], $variations, replaces: $stored->id);

    $copy = MediaFieldValue::with('conversions')->find($id);
    $desktop = $copy->conversion('desktop');

    expect($id)->not->toBe($stored->id)
        ->and($desktop->status)->toBe(ConversionStatus::Completed)
        ->and($desktop->path)->toStartWith($copy->directory() . '/')
        ->and(basename($desktop->path))->toBe(basename($stored->conversion('desktop')->path));
    Storage::disk('public')->assertExists($desktop->path);
    Storage::disk('public')->assertExists($stored->conversion('desktop')->path);
    Queue::assertNothingPushed();
});

it('regenerates only the crops that changed', function () use ($variations) {
    [$stored, $state] = storedWithCrops($variations);
    Queue::fake();
    $state['variants']['mobile'] = [...$state['variants']['mobile'], 'custom' => true, 'focus_x' => 0.9];

    $id = app(PersistImagePicker::class)->execute([...$state, 'id' => null, 'token' => null], $variations, replaces: $stored->id);

    $copy = MediaFieldValue::with('conversions')->find($id);
    expect($copy->conversion('desktop')->status)->toBe(ConversionStatus::Completed)
        ->and($copy->conversion('mobile')->status)->toBe(ConversionStatus::Pending)
        ->and($copy->conversion('mobile')->path)->toBeNull();
    Queue::assertPushed(GenerateConversion::class, 1);
});

it('regenerates every crop when the main image changed', function () use ($variations) {
    [$stored, $state] = storedWithCrops($variations);
    Queue::fake();

    $id = app(PersistImagePicker::class)->execute([...$state, 'id' => null, 'token' => null, 'media_id' => reuseImage('other.jpg')->id], $variations, replaces: $stored->id);

    expect(MediaFieldValue::with('conversions')->find($id)->conversions->pluck('status')->unique()->all())->toBe([ConversionStatus::Pending]);
    Queue::assertPushed(GenerateConversion::class, 2);
});

it('generates the changed crops during the save when asked to', function () use ($variations) {
    [$stored, $state] = storedWithCrops($variations);
    Queue::fake();
    $state['variants']['mobile'] = [...$state['variants']['mobile'], 'custom' => true, 'focus_x' => 0.9];

    $id = app(PersistImagePicker::class)->execute([...$state, 'id' => null, 'token' => null], $variations, replaces: $stored->id, generateNow: true);

    $mobile = MediaFieldValue::with('conversions')->find($id)->conversion('mobile');
    expect($mobile->status)->toBe(ConversionStatus::Completed);
    Storage::disk('public')->assertExists($mobile->path);
    Queue::assertNothingPushed();
});

it('generates a crop when the file to copy is missing', function () use ($variations) {
    [$stored, $state] = storedWithCrops($variations);
    Storage::disk('public')->delete($stored->conversion('desktop')->path);
    Queue::fake();

    $id = app(PersistImagePicker::class)->execute([...$state, 'id' => null, 'token' => null], $variations, replaces: $stored->id);

    expect(MediaFieldValue::with('conversions')->find($id)->conversion('desktop')->status)->toBe(ConversionStatus::Pending);
    Queue::assertPushed(GenerateConversion::class, 1);
});

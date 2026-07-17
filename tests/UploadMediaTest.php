<?php

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use MarvelousDigital\FilamentMediaManager\Actions\UploadMedia;
use MarvelousDigital\FilamentMediaManager\Enums\MediaType;
use MarvelousDigital\FilamentMediaManager\Enums\ThumbnailStatus;
use MarvelousDigital\FilamentMediaManager\Jobs\GenerateThumbnail;
use MarvelousDigital\FilamentMediaManager\Models\MediaFolder;

beforeEach(function () {
    Storage::fake('public');
    Queue::fake();
});

it('stores the original untouched and creates a media row', function () {
    $file = UploadedFile::fake()->image('My Photo.jpg', 800, 600);
    $originalBytes = file_get_contents($file->getRealPath());

    $folder = MediaFolder::create(['name' => 'Summer']);

    $media = app(UploadMedia::class)->execute($file, $folder);

    expect($media->folder_id)->toBe($folder->id)
        ->and($media->name)->toBe('My Photo')
        ->and($media->file_name)->toBe('My Photo.jpg')
        ->and($media->type)->toBe(MediaType::Image)
        ->and($media->thumbnail_status)->toBe(ThumbnailStatus::Pending)
        ->and($media->size)->toBeGreaterThan(0)
        ->and($media->path)->toEndWith('/My Photo.jpg');

    Storage::disk('public')->assertExists($media->path);
    expect(Storage::disk('public')->get($media->path))->toBe($originalBytes);

    Queue::assertPushed(GenerateThumbnail::class);
});

it('marks non-image uploads as thumbnail skipped and does not dispatch the job', function () {
    $file = UploadedFile::fake()->create('report.pdf', 100, 'application/pdf');

    $media = app(UploadMedia::class)->execute($file);

    expect($media->type)->toBe(MediaType::Document)
        ->and($media->thumbnail_status)->toBe(ThumbnailStatus::Skipped);

    Queue::assertNothingPushed();
});

it('rejects disallowed mime types', function () {
    config()->set('filament-media-manager.accepted_mime_types', ['image/jpeg']);

    $file = UploadedFile::fake()->create('script.exe', 10, 'application/x-msdownload');

    app(UploadMedia::class)->execute($file);
})->throws(ValidationException::class);

it('rejects files over the max size', function () {
    config()->set('filament-media-manager.max_file_size', 1); // 1 KB

    $file = UploadedFile::fake()->create('big.bin', 10, 'application/octet-stream'); // 10 KB

    app(UploadMedia::class)->execute($file);
})->throws(ValidationException::class);

it('sanitizes path-hostile filenames', function () {
    $file = UploadedFile::fake()->image('../../evil.jpg');

    $media = app(UploadMedia::class)->execute($file);

    expect($media->file_name)->not->toContain('/')
        ->and($media->file_name)->not->toContain('..');
});

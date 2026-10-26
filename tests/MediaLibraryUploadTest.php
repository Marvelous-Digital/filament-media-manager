<?php

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use MarvelousDigital\FilamentMediaManager\Jobs\GenerateThumbnail;
use MarvelousDigital\FilamentMediaManager\Models\Media;
use MarvelousDigital\FilamentMediaManager\Models\MediaFolder;
use MarvelousDigital\FilamentMediaManager\Pages\MediaLibrary;

beforeEach(function () {
    Storage::fake('public');
    Queue::fake();
    $this->actingAsUser();
});

it('persists uploaded files into the current folder', function () {
    $folder = MediaFolder::create(['name' => 'Summer']);

    Livewire::test(MediaLibrary::class)
        ->call('openFolder', $folder->id)
        ->set('uploads', [
            UploadedFile::fake()->image('one.jpg'),
            UploadedFile::fake()->image('two.jpg'),
        ]);

    expect(Media::query()->count())->toBe(2)
        ->and(Media::query()->pluck('folder_id')->unique()->all())->toBe([$folder->id]);

    Queue::assertPushed(GenerateThumbnail::class, 2);
});

it('continues the batch when one file fails validation', function () {
    config()->set('filament-media-manager.accepted_mime_types', ['image/jpeg']);

    Livewire::test(MediaLibrary::class)
        ->set('uploads', [
            UploadedFile::fake()->image('good.jpg'),
            UploadedFile::fake()->create('bad.pdf', 10, 'application/pdf'),
        ])
        ->assertNotified();

    expect(Media::query()->count())->toBe(1)
        ->and(Media::query()->first()->file_name)->toBe('good.jpg');
});

it('clears the pending uploads after processing', function () {
    Livewire::test(MediaLibrary::class)
        ->set('uploads', [UploadedFile::fake()->image('one.jpg')])
        ->assertSet('uploads', []);
});

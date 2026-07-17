<?php

use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use MarvelousDigital\FilamentMediaManager\Models\Media;
use MarvelousDigital\FilamentMediaManager\Models\MediaFolder;
use MarvelousDigital\FilamentMediaManager\Pages\MediaLibrary;

beforeEach(function () {
    Storage::fake('public');
    $this->actingAsUser();
});

it('bulk deletes selected media', function () {
    $keep = Media::factory()->create();
    $a = Media::factory()->create();
    $b = Media::factory()->create();

    Livewire::test(MediaLibrary::class)
        ->set('selected', [$a->id, $b->id])
        ->callAction('bulkDelete')
        ->assertSet('selected', []);

    expect(Media::query()->pluck('id')->all())->toBe([$keep->id]);
});

it('bulk moves selected media to a folder', function () {
    $folder = MediaFolder::create(['name' => 'Target']);
    $a = Media::factory()->create();
    $b = Media::factory()->create();

    Livewire::test(MediaLibrary::class)
        ->set('selected', [$a->id, $b->id])
        ->callAction('bulkMove', ['folder_id' => $folder->id])
        ->assertSet('selected', []);

    expect($a->refresh()->folder_id)->toBe($folder->id)
        ->and($b->refresh()->folder_id)->toBe($folder->id);
});

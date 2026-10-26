<?php

use Livewire\Livewire;
use MarvelousDigital\FilamentMediaManager\Models\Media;
use MarvelousDigital\FilamentMediaManager\Models\MediaFolder;
use MarvelousDigital\FilamentMediaManager\Pages\MediaLibrary;

beforeEach(function () {
    $this->actingAsUser();
});

it('creates a folder inside the current folder', function () {
    $parent = MediaFolder::create(['name' => 'Campaigns']);

    Livewire::test(MediaLibrary::class)
        ->call('openFolder', $parent->id)
        ->callAction('createFolder', ['name' => 'Summer']);

    expect(MediaFolder::query()->where('name', 'Summer')->first()->parent_id)->toBe($parent->id);
});

it('rejects duplicate folder names at the same level', function () {
    MediaFolder::create(['name' => 'Summer']);

    Livewire::test(MediaLibrary::class)
        ->callAction('createFolder', ['name' => 'Summer'])
        ->assertHasActionErrors(['name']);
});

it('rejects duplicate folder names at the root level', function () {
    MediaFolder::create(['name' => 'Summer']);

    Livewire::test(MediaLibrary::class)
        ->callAction('createFolder', ['name' => 'Summer'])
        ->assertHasActionErrors(['name']);

    expect(MediaFolder::query()->where('name', 'Summer')->count())->toBe(1);
});

it('renames a folder', function () {
    $folder = MediaFolder::create(['name' => 'Old']);

    Livewire::test(MediaLibrary::class)
        ->callAction('renameFolder', ['name' => 'New'], arguments: ['folder' => $folder->id]);

    expect($folder->refresh()->name)->toBe('New');
});

it('rejects renaming a folder to a sibling name', function () {
    MediaFolder::create(['name' => 'Existing']);
    $folder = MediaFolder::create(['name' => 'Old']);

    Livewire::test(MediaLibrary::class)
        ->callAction('renameFolder', ['name' => 'Existing'], arguments: ['folder' => $folder->id])
        ->assertHasActionErrors(['name']);

    expect($folder->refresh()->name)->toBe('Old');
});

it('allows renaming a folder to its own current name', function () {
    $folder = MediaFolder::create(['name' => 'Same']);

    Livewire::test(MediaLibrary::class)
        ->callAction('renameFolder', ['name' => 'Same'], arguments: ['folder' => $folder->id])
        ->assertHasNoActionErrors();

    expect($folder->refresh()->name)->toBe('Same');
});

it('deletes an empty folder', function () {
    $folder = MediaFolder::create(['name' => 'Empty']);

    Livewire::test(MediaLibrary::class)
        ->callAction('deleteFolder', arguments: ['folder' => $folder->id]);

    expect(MediaFolder::query()->count())->toBe(0);
});

it('refuses to delete a non-empty folder', function () {
    $folder = MediaFolder::create(['name' => 'Full']);
    Media::factory()->create(['folder_id' => $folder->id]);

    Livewire::test(MediaLibrary::class)
        ->callAction('deleteFolder', arguments: ['folder' => $folder->id])
        ->assertNotified();

    expect(MediaFolder::query()->count())->toBe(1);
});

<?php

use Livewire\Livewire;
use MarvelousDigital\FilamentMediaManager\Models\Media;
use MarvelousDigital\FilamentMediaManager\Models\MediaFolder;
use MarvelousDigital\FilamentMediaManager\Pages\MediaLibrary;

beforeEach(function () {
    $this->actingAsUser();
});

it('renders folders and media at the root', function () {
    $folder = MediaFolder::create(['name' => 'Campaigns']);
    Media::factory()->create(['name' => 'root-photo']);
    Media::factory()->create(['name' => 'nested-photo', 'folder_id' => $folder->id]);

    Livewire::test(MediaLibrary::class)
        ->assertSee('Campaigns')
        ->assertSee('root-photo')
        ->assertDontSee('nested-photo');
});

it('navigates into a folder and shows breadcrumbs', function () {
    $parent = MediaFolder::create(['name' => 'Campaigns']);
    $child = MediaFolder::create(['name' => 'Summer', 'parent_id' => $parent->id]);
    Media::factory()->create(['name' => 'inside-summer', 'folder_id' => $child->id]);

    Livewire::test(MediaLibrary::class)
        ->call('openFolder', $child->id)
        ->assertSet('folderId', $child->id)
        ->assertSee('inside-summer')
        ->assertSee('Campaigns'); // breadcrumb
});

it('scopes search to the current folder by name and alt text', function () {
    Media::factory()->create(['name' => 'sunset-beach']);
    Media::factory()->create(['name' => 'x', 'alt_text' => 'a red sunset']);
    Media::factory()->create(['name' => 'mountain']);

    Livewire::test(MediaLibrary::class)
        ->set('search', 'sunset')
        ->assertSee('sunset-beach')
        ->assertSee('a red sunset')
        ->assertDontSee('mountain');
});

it('paginates with load more', function () {
    config()->set('filament-media-manager.per_page', 2);
    Media::factory()->count(5)->sequence(fn ($seq) => ['name' => 'file-' . $seq->index])->create();

    $component = Livewire::test(MediaLibrary::class);
    expect($component->viewData('mediaItems'))->toHaveCount(2)
        ->and($component->viewData('hasMore'))->toBeTrue();

    $component->call('loadMore');
    expect($component->viewData('mediaItems'))->toHaveCount(4);
});

it('resets pagination when the search changes', function () {
    config()->set('filament-media-manager.per_page', 2);
    Media::factory()->count(5)->create();

    Livewire::test(MediaLibrary::class)
        ->call('loadMore')
        ->set('search', 'anything')
        ->assertSet('loadedPages', 1);
});

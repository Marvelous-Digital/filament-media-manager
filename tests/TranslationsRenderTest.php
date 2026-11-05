<?php

use Livewire\Livewire;
use MarvelousDigital\FilamentMediaManager\Livewire\MediaPickerBrowser;
use MarvelousDigital\FilamentMediaManager\Pages\MediaLibrary;

beforeEach(function () {
    $this->actingAsUser();
});

afterEach(function () {
    app()->setLocale('en');
});

it('renders the media library in English', function () {
    app()->setLocale('en');

    Livewire::test(MediaLibrary::class)
        ->assertSee('New folder')
        ->assertSee('Search this folder…')
        ->assertDontSee('Nouveau dossier')
        ->assertDontSee('Rechercher dans ce dossier…');
});

it('renders the media library in French', function () {
    app()->setLocale('fr');

    Livewire::test(MediaLibrary::class)
        ->assertSee('Nouveau dossier')
        ->assertSee('Rechercher dans ce dossier…')
        ->assertDontSee('New folder')
        ->assertDontSee('Search this folder…');
});

it('renders the picker browser in English', function () {
    app()->setLocale('en');

    Livewire::test(MediaPickerBrowser::class, ['statePath' => 'data.hero', 'target' => 'main'])
        ->assertSee('Search this folder…')
        ->assertSee('Upload')
        ->assertDontSee('Rechercher dans ce dossier…');
});

it('renders the picker browser in French', function () {
    app()->setLocale('fr');

    Livewire::test(MediaPickerBrowser::class, ['statePath' => 'data.hero', 'target' => 'main'])
        ->assertSee('Rechercher dans ce dossier…')
        ->assertSee('Téléverser')
        ->assertDontSee('Search this folder…');
});

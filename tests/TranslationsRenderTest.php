<?php

use Livewire\Livewire;
use MarvelousDigital\FilamentMediaManager\Models\Media;
use MarvelousDigital\FilamentMediaManager\Models\MediaFolder;
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

/** The label of the "name" field of the schema of the action the page has mounted. */
function mountedNameLabel(\Livewire\Features\SupportTesting\Testable $page): string
{
    $schema = (fn () => $this->getMountedActionSchema())->call($page->instance());

    return (string) $schema->getComponent('name')->getLabel();
}

it('labels the name field of the folder and media actions in the interface language', function (string $locale, string $name, string $other) {
    app()->setLocale($locale);
    $folder = MediaFolder::create(['name' => 'Summer']);
    $media = Media::create(['name' => 'Photo', 'file_name' => 'photo.jpg', 'disk' => 'public', 'path' => 'photo.jpg', 'mime_type' => 'image/jpeg', 'type' => 'image', 'size' => 1]);

    $page = Livewire::test(MediaLibrary::class);

    foreach ([['createFolder', []], ['renameFolder', ['folder' => $folder->id]], ['editMedia', ['media' => $media->id]]] as [$action, $arguments]) {
        $page->mountAction($action, $arguments);

        expect(mountedNameLabel($page))->toBe($name, "{$action} labels its name field");

        $page->unmountAction();
    }
})->with([
    'en' => ['en', 'Name', 'Nom'],
    'fr' => ['fr', 'Nom', 'Name'],
]);

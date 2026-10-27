<?php

use Filament\Actions\Concerns\InteractsWithActions;
use Filament\Actions\Contracts\HasActions;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Livewire\Component;
use Livewire\Livewire;
use MarvelousDigital\FilamentMediaManager\Forms\Components\ImagesPicker;
use MarvelousDigital\FilamentMediaManager\Models\Media;
use MarvelousDigital\FilamentMediaManager\Models\MediaFieldValue;
use MarvelousDigital\FilamentMediaManager\Support\FieldValueToken;

class ImagesPickerRenderHarness extends Component implements HasActions, HasForms
{
    use InteractsWithActions;
    use InteractsWithForms;

    public array $data = [];

    /** @var list<int> */
    public array $preset = [];

    public function mount(): void
    {
        $this->form->fill(['gallery' => $this->preset]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            ImagesPicker::make('gallery'),
        ])->statePath('data');
    }

    public function render(): string
    {
        return '<div>{{ $this->form }}</div>';
    }
}

class ImagesPickerTranslatorHarness extends ImagesPickerRenderHarness
{
    /** @var array<string, mixed> */
    public array $saved = [];

    /**
     * What a translator does when switching locale: `$set` a stored id list on the field.
     *
     * @param  list<int>  $ids
     */
    public function switchTo(array $ids): void
    {
        $field = collect($this->form->getFlatFields(withHidden: true))->first(fn ($field): bool => $field instanceof ImagesPicker);

        (new Set($field))('gallery', $ids);
    }

    public function save(): void
    {
        $this->saved = $this->form->getState();
    }
}

it('renders a thumbnail per image and an add button', function () {
    $first = MediaFieldValue::factory()->for(Media::factory()->state(['thumbnail_path' => 'thumbs/first.webp']), 'source')->create(['alt_text' => 'First alt']);
    $second = MediaFieldValue::factory()->for(Media::factory()->state(['thumbnail_path' => 'thumbs/second.webp']), 'source')->create(['alt_text' => 'Second alt']);

    Livewire::test(ImagesPickerRenderHarness::class, ['preset' => [$first->id, $second->id]])
        ->assertOk()
        ->assertSee('Add images')
        ->assertSee('thumbs/first.webp')
        ->assertSee('thumbs/second.webp')
        ->assertSee('x-sortable', escape: false)
        ->assertSee("v{$first->id}")
        ->assertSee("v{$second->id}")
        ->assertSee('Remove')
        ->assertDontSee('No images yet');
});

it('shows an empty state when no images are picked', function () {
    Livewire::test(ImagesPickerRenderHarness::class)
        ->assertOk()
        ->assertSee('Add images')
        ->assertSee('No images yet');
});

it('hydrates a stored id list set on the field by server code so alt edits are kept', function () {
    [$english, $german] = MediaFieldValue::factory()->count(2)->for(Media::factory(), 'source')->create()->all();

    $livewire = Livewire::test(ImagesPickerTranslatorHarness::class, ['preset' => [$english->id]])
        ->call('switchTo', [$german->id])
        ->assertSet('data.gallery.items.0.id', $german->id)
        ->assertSet('data.gallery.items.0.token', FieldValueToken::for($german->id))
        ->set('data.gallery.items.0.alt', 'Bild')
        ->call('save')
        ->assertSet('saved.gallery', [$german->id]);

    expect($german->refresh()->alt_text)->toBe('Bild')
        ->and(MediaFieldValue::query()->count())->toBe(2);
});

it('hydrates a plain id list the browser set before rendering so alt edits are kept', function () {
    $value = MediaFieldValue::factory()->for(Media::factory()->state(['thumbnail_path' => 'thumbs/plain.webp']), 'source')->create(['alt_text' => 'Before']);

    $livewire = Livewire::test(ImagesPickerTranslatorHarness::class)
        ->set('data.gallery', [$value->id])
        ->assertSee('thumbs/plain.webp')
        ->assertSet('data.gallery.items.0.media_id', $value->media_id)
        ->assertSet('data.gallery.items.0.alt', 'Before')
        ->set('data.gallery.items.0.alt', 'After')
        ->call('save');

    $saved = $livewire->get('saved.gallery');

    expect($saved)->toHaveCount(1)
        ->and(MediaFieldValue::find($saved[0])->alt_text)->toBe('After')
        // The browser is not trusted with ids: the edit lands on a new row, not on the one it named.
        ->and($saved[0])->not->toBe($value->id)
        ->and($value->refresh()->alt_text)->toBe('Before');
});

it('listens for library picks with a window listener Alpine cleans up', function () {
    Livewire::test(ImagesPickerRenderHarness::class)
        ->assertSee('x-on:image-picker:selected.window="selected($event.detail)"', escape: false)
        ->assertDontSee('addEventListener', escape: false);
});

it('shows a missing badge instead of an empty image for media deleted from the library', function () {
    $kept = MediaFieldValue::factory()->for(Media::factory()->state(['thumbnail_path' => 'thumbs/kept.webp']), 'source')->create();
    $gone = MediaFieldValue::factory()->for(Media::factory()->state(['thumbnail_path' => 'thumbs/gone.webp']), 'source')->create();
    $gone->source->delete();

    Livewire::test(ImagesPickerRenderHarness::class, ['preset' => [$kept->id, $gone->id]])
        ->assertOk()
        ->assertSee('thumbs/kept.webp')
        ->assertDontSee('thumbs/gone.webp')
        ->assertSee('Missing from the library')
        ->assertDontSee('src=""', escape: false);
});

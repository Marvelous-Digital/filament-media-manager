<?php

use Filament\Actions\Concerns\InteractsWithActions;
use Filament\Actions\Contracts\HasActions;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Schemas\Schema;
use Livewire\Component;
use Livewire\Livewire;
use MarvelousDigital\FilamentMediaManager\Forms\Components\ImagesPicker;
use MarvelousDigital\FilamentMediaManager\Models\Media;
use MarvelousDigital\FilamentMediaManager\Models\MediaFieldValue;

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

<?php

use Filament\Actions\Concerns\InteractsWithActions;
use Filament\Actions\Contracts\HasActions;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Schemas\Schema;
use Livewire\Component;
use Livewire\Livewire;
use MarvelousDigital\FilamentMediaManager\Enums\MediaType;
use MarvelousDigital\FilamentMediaManager\Forms\Components\DocumentPicker;
use MarvelousDigital\FilamentMediaManager\Models\Media;

class DocumentPickerHarness extends Component implements HasActions, HasForms
{
    use InteractsWithActions;
    use InteractsWithForms;

    public array $data = [];

    public ?int $preset = null;

    public function mount(): void
    {
        $this->form->fill(['file' => $this->preset]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            DocumentPicker::make('file')->mimeTypes(['application/pdf']),
        ])->statePath('data');
    }

    public function render(): string
    {
        return '<div>{{ $this->form }}</div>';
    }
}

it('shows the picked file name, format and size', function () {
    $media = Media::factory()->create([
        'file_name' => 'annual-report.pdf',
        'mime_type' => 'application/pdf',
        'type' => MediaType::Document,
        'size' => 2048,
    ]);

    Livewire::test(DocumentPickerHarness::class, ['preset' => $media->id])
        ->assertSee('annual-report.pdf')
        ->assertSee('PDF')
        ->assertSee('2 KB')
        ->assertSee('Remove');
});

it('offers the library before a file is picked', function () {
    Livewire::test(DocumentPickerHarness::class)
        ->assertSee('Pick from library')
        ->assertDontSee('Remove');
});

it('shows a notice for a file that was deleted from the library', function () {
    $media = Media::factory()->create(['mime_type' => 'application/pdf']);
    $livewire = Livewire::test(DocumentPickerHarness::class, ['preset' => $media->id]);
    $media->delete();

    $livewire->call('$refresh')->assertSee('This file is no longer in the library.');
});

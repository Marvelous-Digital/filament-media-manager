<?php

use Filament\Actions\Concerns\InteractsWithActions;
use Filament\Actions\Contracts\HasActions;
use Filament\Actions\Testing\TestAction;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Schemas\Schema;
use Livewire\Component;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use MarvelousDigital\FilamentMediaManager\Enums\MediaType;
use MarvelousDigital\FilamentMediaManager\Forms\Components\DocumentPicker;
use MarvelousDigital\FilamentMediaManager\Models\Media;
use MarvelousDigital\FilamentMediaManager\Models\MediaFieldValue;

class DocumentPickerFormHarness extends Component implements HasActions, HasForms
{
    use InteractsWithActions;
    use InteractsWithForms;

    /** @var array<string, mixed> */
    public array $data = [];

    /** @var array<string, mixed> */
    public array $saved = [];

    public ?int $preset = null;

    public bool $required = false;

    public function mount(): void
    {
        $this->form->fill(['file' => $this->preset]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                DocumentPicker::make('file')->mimeTypes(['application/pdf'])->required($this->required),
            ])
            ->statePath('data');
    }

    public function save(): void
    {
        $this->saved = $this->form->getState();
    }

    public function render(): string
    {
        return '<div>{{ $this->form }}</div>';
    }
}

function pdf(array $attributes = []): Media
{
    return Media::factory()->create([
        'name' => 'contract',
        'file_name' => 'contract.pdf',
        'mime_type' => 'application/pdf',
        'type' => MediaType::Document,
        'size' => 2048,
        ...$attributes,
    ]);
}

function documentKey(Testable $livewire, bool $relative = false): string
{
    return collect($livewire->instance()->form->getFlatFields(withHidden: true))
        ->first(fn ($field): bool => $field instanceof DocumentPicker)
        ->getKey(! $relative);
}

it('stores the picked media id', function () {
    $media = pdf();
    $livewire = Livewire::test(DocumentPickerFormHarness::class);

    $livewire->call('callSchemaComponentMethod', documentKey($livewire), 'selectDocument', ['mediaId' => $media->id])
        ->assertSet('data.file', $media->id)
        ->call('save')
        ->assertHasNoErrors()
        ->assertSet('saved.file', $media->id);

    expect(MediaFieldValue::query()->count())->toBe(0);
});

it('does not pick a media outside its mime types', function () {
    $image = Media::factory()->create();
    $livewire = Livewire::test(DocumentPickerFormHarness::class);

    $livewire->call('callSchemaComponentMethod', documentKey($livewire), 'selectDocument', ['mediaId' => $image->id])
        ->assertSet('data.file', null);
});

it('refuses a media outside its mime types', function () {
    $image = Media::factory()->create();

    Livewire::test(DocumentPickerFormHarness::class, ['preset' => $image->id])
        ->call('save')
        ->assertHasErrors(['data.file']);
});

it('refuses a deleted media', function () {
    $media = pdf();
    $livewire = Livewire::test(DocumentPickerFormHarness::class, ['preset' => $media->id]);
    $media->delete();

    $livewire->call('save')->assertHasErrors(['data.file']);
});

it('requires a file when the field is required', function () {
    Livewire::test(DocumentPickerFormHarness::class, ['required' => true])
        ->call('save')
        ->assertHasErrors(['data.file']);

    Livewire::test(DocumentPickerFormHarness::class)
        ->call('save')
        ->assertHasNoErrors();
});

it('clears the picked media', function () {
    $livewire = Livewire::test(DocumentPickerFormHarness::class, ['preset' => pdf()->id]);

    $livewire->call('callSchemaComponentMethod', documentKey($livewire), 'removeDocument')
        ->assertSet('data.file', null);
});

it('opens the browser with its mime types', function () {
    $livewire = Livewire::test(DocumentPickerFormHarness::class);

    $livewire->mountAction(TestAction::make('select')->schemaComponent(documentKey($livewire, relative: true)));

    $modal = (string) $livewire->instance()->getMountedAction()->getModalContent();

    expect($modal)
        ->toContain('media-picker-browser')
        ->toContain('application\/pdf');
});

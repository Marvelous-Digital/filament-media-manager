<?php

use Filament\Actions\Concerns\InteractsWithActions;
use Filament\Actions\Contracts\HasActions;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Schemas\Schema;
use Livewire\Component;
use Livewire\Livewire;
use MarvelousDigital\FilamentMediaManager\Enums\ConversionStatus;
use MarvelousDigital\FilamentMediaManager\Forms\Components\ImagePicker;
use MarvelousDigital\FilamentMediaManager\Models\Media;
use MarvelousDigital\FilamentMediaManager\Models\MediaConversion;
use MarvelousDigital\FilamentMediaManager\Models\MediaFieldValue;

class RenderHarness extends Component implements HasActions, HasForms
{
    use InteractsWithActions;
    use InteractsWithForms;

    public array $data = [];

    public ?int $preset = null;

    public function mount(): void
    {
        $this->form->fill(['hero_image_id' => $this->preset]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components([ImagePicker::make('hero_image_id')->variations(['hero' => [1600, 600]])])->statePath('data');
    }

    public function render(): string
    {
        return '<div>{{ $this->form }}</div>';
    }
}

it('renders the empty state blade without error', function () {
    Livewire::test(RenderHarness::class)->assertOk()->assertSee('Pick image');
});

it('renders the filled state blade with focus editor without error', function () {
    $value = MediaFieldValue::factory()->for(Media::factory(), 'source')->create(['alt_text' => 'hi']);
    MediaConversion::factory()->for($value, 'value')->create(['status' => ConversionStatus::Failed]);

    Livewire::test(RenderHarness::class, ['preset' => $value->id])
        ->assertOk()
        ->assertSee('Alt text')
        ->assertSee('Retry');
});

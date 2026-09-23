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
        return $schema->components([
            ImagePicker::make('hero_image_id')->variations(['desktop' => [1500, 900], 'mobile' => [480, 640]]),
        ])->statePath('data');
    }

    public function render(): string
    {
        return '<div>{{ $this->form }}</div>';
    }
}

it('shows the required formats as ratios before anything is picked', function () {
    Livewire::test(RenderHarness::class)
        ->assertOk()
        ->assertSee('Upload')
        ->assertSee('Pick from library')
        ->assertSee('Required formats')
        ->assertSee('desktop · 5:3')
        ->assertSee('mobile · 3:4')
        ->assertSee('1500×900');
});

it('renders a card per variant with reframe and override actions', function () {
    $value = MediaFieldValue::factory()->for(Media::factory()->state(['width' => 2000, 'height' => 1000]), 'source')->create(['alt_text' => 'hi']);
    MediaConversion::factory()->for($value, 'value')->create(['variation' => 'desktop', 'status' => ConversionStatus::Failed]);

    Livewire::test(RenderHarness::class, ['preset' => $value->id])
        ->assertOk()
        ->assertSee('Alt text')
        ->assertSee('Reframe')
        ->assertSee('Use different image')
        ->assertSee('Zoom')
        ->assertSee('Retry');
});

it('shows own-image controls only for overridden variants', function () {
    $value = MediaFieldValue::factory()->for(Media::factory(), 'source')->create();
    MediaConversion::factory()->for($value, 'value')->create(['variation' => 'mobile', 'media_id' => Media::factory()->create()->id]);

    Livewire::test(RenderHarness::class, ['preset' => $value->id])
        ->assertSee('Own image')
        ->assertSee('Use main image');
});

it('renders variant cards when the source has no dimensions', function () {
    $value = MediaFieldValue::factory()->for(Media::factory()->state(['width' => null, 'height' => null]), 'source')->create();

    Livewire::test(RenderHarness::class, ['preset' => $value->id])
        ->assertOk()
        ->assertSee('Reframe');
});

it('polls while a conversion is still generating', function () {
    $value = MediaFieldValue::factory()->for(Media::factory(), 'source')->create();
    MediaConversion::factory()->for($value, 'value')->create(['variation' => 'desktop', 'status' => ConversionStatus::Pending]);

    Livewire::test(RenderHarness::class, ['preset' => $value->id])
        ->assertSee('Generating')
        ->assertSee('wire:poll', escape: false);
});

it('stops polling once every conversion is completed', function () {
    $value = MediaFieldValue::factory()->for(Media::factory(), 'source')->create();
    MediaConversion::factory()->for($value, 'value')->create(['variation' => 'desktop', 'status' => ConversionStatus::Completed]);
    MediaConversion::factory()->for($value, 'value')->create(['variation' => 'mobile', 'status' => ConversionStatus::Completed]);

    Livewire::test(RenderHarness::class, ['preset' => $value->id])
        ->assertDontSee('Generating')
        ->assertDontSee('wire:poll', escape: false);
});

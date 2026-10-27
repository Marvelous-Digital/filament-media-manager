<?php

use Filament\Actions\Concerns\InteractsWithActions;
use Filament\Actions\Contracts\HasActions;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Livewire\Component;
use Livewire\Livewire;
use MarvelousDigital\FilamentMediaManager\Enums\ConversionStatus;
use MarvelousDigital\FilamentMediaManager\Forms\Components\ImagePicker;
use MarvelousDigital\FilamentMediaManager\Models\Media;
use MarvelousDigital\FilamentMediaManager\Models\MediaConversion;
use MarvelousDigital\FilamentMediaManager\Models\MediaFieldValue;
use MarvelousDigital\FilamentMediaManager\Support\FieldValueToken;

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

class NoVariationsRenderHarness extends RenderHarness
{
    public function form(Schema $schema): Schema
    {
        return $schema->components([
            ImagePicker::make('hero_image_id'),
        ])->statePath('data');
    }
}

class ImagePickerTranslatorHarness extends RenderHarness
{
    /** @var array<string, mixed> */
    public array $saved = [];

    /**
     * What a translator does when switching locale: `$set` a stored id on the field.
     */
    public function switchTo(int $id): void
    {
        $field = collect($this->form->getFlatFields(withHidden: true))->first(fn ($field): bool => $field instanceof ImagePicker);

        (new Set($field))('hero_image_id', $id);
    }

    public function save(): void
    {
        $this->saved = $this->form->getState();
    }
}

it('renders without variant cards when no variations are configured', function () {
    $value = MediaFieldValue::factory()->for(Media::factory()->state(['width' => 2000, 'height' => 1000]), 'source')->create(['alt_text' => 'hi']);

    Livewire::test(NoVariationsRenderHarness::class, ['preset' => $value->id])
        ->assertOk()
        ->assertSee('Alt text')
        ->assertDontSee('Reframe')
        ->assertDontSee('Use different image')
        ->assertDontSee('Required formats');
});

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

it('hydrates a stored id set on the field by server code so edits are kept', function () {
    [$english, $german] = MediaFieldValue::factory()->count(2)->for(Media::factory(), 'source')->create()->all();

    Livewire::test(ImagePickerTranslatorHarness::class, ['preset' => $english->id])
        ->call('switchTo', $german->id)
        ->assertSet('data.hero_image_id.id', $german->id)
        ->assertSet('data.hero_image_id.token', FieldValueToken::for($german->id))
        ->assertSee('Reframe')
        ->set('data.hero_image_id.alt', 'Bild')
        ->call('save')
        ->assertSet('saved.hero_image_id', $german->id);

    expect($german->refresh()->alt_text)->toBe('Bild')
        ->and(MediaFieldValue::query()->count())->toBe(2);
});

it('renders a plain id the browser set instead of an empty field', function () {
    $value = MediaFieldValue::factory()->for(Media::factory(), 'source')->create(['alt_text' => 'Before']);

    $livewire = Livewire::test(ImagePickerTranslatorHarness::class)
        ->set('data.hero_image_id', $value->id)
        ->assertSee('Reframe')
        ->assertDontSee('Required formats')
        ->assertSet('data.hero_image_id.media_id', $value->media_id)
        ->set('data.hero_image_id.alt', 'After')
        ->call('save');

    $saved = $livewire->get('saved.hero_image_id');

    expect(MediaFieldValue::find($saved)->alt_text)->toBe('After')
        // The browser is not trusted with ids: the edit lands on a new row, not on the one it named.
        ->and($saved)->not->toBe($value->id)
        ->and($value->refresh()->alt_text)->toBe('Before');
});

it('listens for library picks and closes with window listeners Alpine cleans up', function () {
    Livewire::test(RenderHarness::class)
        ->assertSee('x-on:image-picker:selected.window="selected($event.detail)"', escape: false)
        ->assertSee('x-on:image-picker:close.window="closed($event.detail)"', escape: false)
        ->assertDontSee('addEventListener', escape: false);
});

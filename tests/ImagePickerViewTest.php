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

class TwoPickersRenderHarness extends RenderHarness
{
    public ?int $second = null;

    public function mount(): void
    {
        $this->form->fill(['hero_image_id' => $this->preset, 'card_image_id' => $this->second]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            ImagePicker::make('hero_image_id')->variations(['desktop' => [1500, 900]]),
            ImagePicker::make('card_image_id')->variations(['desktop' => [1500, 900]]),
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

it('shows the base image with its menu and no strip when no variations are configured', function () {
    $value = MediaFieldValue::factory()->for(Media::factory()->state(['width' => 2000, 'height' => 1000]), 'source')->create(['alt_text' => 'hi']);

    Livewire::test(NoVariationsRenderHarness::class, ['preset' => $value->id])
        ->assertOk()
        ->assertSee('Change base image')
        ->assertSee('Upload new base image')
        ->assertSee('Remove')
        ->assertSee('fmm-stage', escape: false)
        ->assertSee('Alt text')
        ->assertDontSee('fmm-strip', escape: false)
        ->assertDontSee('fmm-crop-toolbar', escape: false)
        ->assertDontSee('Required formats');
});

it('puts the base image actions in the header menu', function () {
    $value = MediaFieldValue::factory()->for(Media::factory(), 'source')->create();

    Livewire::test(RenderHarness::class, ['preset' => $value->id])
        ->assertSee('More actions')
        ->assertSee('Change base image')
        ->assertSee('Upload new base image')
        ->assertDontSee('Upload new<', escape: false);
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

it('renders a toolbar per variation with zoom, reset, another image, upload and retry', function () {
    $value = MediaFieldValue::factory()->for(Media::factory()->state(['width' => 2000, 'height' => 1000]), 'source')->create(['alt_text' => 'hi']);
    MediaConversion::factory()->for($value, 'value')->create(['variation' => 'desktop', 'status' => ConversionStatus::Failed]);

    Livewire::test(RenderHarness::class, ['preset' => $value->id])
        ->assertOk()
        ->assertSee('data-fmm-toolbar="desktop"', escape: false)
        ->assertSee('data-fmm-toolbar="mobile"', escape: false)
        ->assertSee('desktop · 5:3 · 1500×900')
        ->assertSee('Zoom')
        ->assertSee('Use base focus point')
        ->assertSee('Use another image for desktop')
        ->assertSee('Upload for mobile')
        ->assertSee('Retry')
        ->assertSee('Alt text')
        ->assertDontSee('Reframe')
        ->assertDontSee('fmm-variant', escape: false);
});

it('shows back to base and its own alt only for a variation with its own image', function () {
    $value = MediaFieldValue::factory()->for(Media::factory(), 'source')->create();
    MediaConversion::factory()->for($value, 'value')->create(['variation' => 'mobile', 'media_id' => Media::factory()->create()->id]);

    Livewire::test(RenderHarness::class, ['preset' => $value->id])
        ->assertSee('Back to base image')
        ->assertSee('Alt text for mobile')
        ->assertDontSee('Alt text for desktop')
        ->assertDontSee('Retry');
});

it('renders the strip and stage when the source has no dimensions', function () {
    $value = MediaFieldValue::factory()->for(Media::factory()->state(['width' => null, 'height' => null]), 'source')->create();

    Livewire::test(RenderHarness::class, ['preset' => $value->id])
        ->assertOk()
        ->assertSee('data-fmm-thumb="desktop"', escape: false)
        ->assertSee('fmm-stage', escape: false);
});

it('renders a strip with the base image first and a thumbnail per variation', function () {
    $value = MediaFieldValue::factory()->for(Media::factory()->state(['width' => 2000, 'height' => 1000]), 'source')->create();

    $html = Livewire::test(RenderHarness::class, ['preset' => $value->id])->html();

    expect($html)->toContain('data-fmm-thumb-base')
        ->and(strpos($html, 'data-fmm-thumb-base'))->toBeLessThan(strpos($html, 'data-fmm-thumb="desktop"'))
        ->and(strpos($html, 'data-fmm-thumb="desktop"'))->toBeLessThan(strpos($html, 'data-fmm-thumb="mobile"'))
        ->and($html)->toContain('aspect-ratio: 1500 / 900')
        ->toContain('Base')
        ->toContain("active: 'desktop'")
        ->toContain('fmm-stage-frame');
});

it('marks a failed variation with a dot and an overridden one with a badge, and no ready text', function () {
    $value = MediaFieldValue::factory()->for(Media::factory(), 'source')->create();
    MediaConversion::factory()->for($value, 'value')->create(['variation' => 'desktop', 'status' => ConversionStatus::Failed]);
    MediaConversion::factory()->for($value, 'value')->create(['variation' => 'mobile', 'status' => ConversionStatus::Completed, 'media_id' => Media::factory()->state(['width' => 600, 'height' => 800])->create()->id]);

    Livewire::test(RenderHarness::class, ['preset' => $value->id])
        ->assertSee('title="Generating this format failed"', escape: false)
        ->assertSee('title="Own image"', escape: false)
        ->assertDontSee('Ready');
});

it('shows a spinner on a variation that is still generating', function () {
    $value = MediaFieldValue::factory()->for(Media::factory(), 'source')->create();
    MediaConversion::factory()->for($value, 'value')->create(['variation' => 'desktop', 'status' => ConversionStatus::Pending]);

    Livewire::test(RenderHarness::class, ['preset' => $value->id])
        ->assertSee('fmm-strip-spinner', escape: false)
        ->assertSee('wire:poll', escape: false);
});

it('keeps two pickers on one form apart', function () {
    $a = MediaFieldValue::factory()->for(Media::factory(), 'source')->create();
    $b = MediaFieldValue::factory()->for(Media::factory(), 'source')->create();

    Livewire::test(TwoPickersRenderHarness::class, ['preset' => $a->id, 'second' => $b->id])
        ->assertSee('fmm-thumb-data.hero_image_id-desktop', escape: false)
        ->assertSee('fmm-thumb-data.card_image_id-desktop', escape: false);
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
        ->assertSee('data-fmm-toolbar="desktop"', escape: false)
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
        ->assertSee('data-fmm-toolbar="desktop"', escape: false)
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

it('keeps the library pick handler as the only selected member of the component', function () {
    $value = MediaFieldValue::factory()->for(Media::factory(), 'source')->create();

    $html = Livewire::test(RenderHarness::class, ['preset' => $value->id])->html();

    expect(substr_count($html, 'selected('))->toBe(2) // the handler and the window listener calling it
        ->and($html)->not->toContain('selected: ')
        ->and($html)->not->toContain('selected ===')
        ->and($html)->not->toContain('selected = ');
});

it('keeps the server data that changes out of the x-data attribute, so a re-render keeps the selection', function () {
    $value = MediaFieldValue::factory()->for(Media::factory()->state(['path' => 'unique-name-xyz.jpg']), 'source')->create();

    $html = Livewire::test(RenderHarness::class, ['preset' => $value->id])->html();

    preg_match('/x-data="(\{.*?\})"\s*>/s', $html, $match);

    expect($match[1] ?? '')->not->toBe('')
        ->and($match[1])->not->toContain('unique-name-xyz')
        ->and($html)->toContain('data-fmm-media');
});

it('draws the stage frame only for a variation with known dimensions', function () {
    $value = MediaFieldValue::factory()->for(Media::factory(), 'source')->create();

    Livewire::test(RenderHarness::class, ['preset' => $value->id])
        ->assertSee('x-show="active !== null && hasSize(active)"', escape: false);
});

it('fits the stage to the image so the frame matches the picture, and rings the selected thumbnail', function () {
    $css = file_get_contents(dirname(__DIR__) . '/resources/dist/filament-media-manager.css');

    preg_match('/\.fmm-stage img \{([^}]*)\}/', $css, $stageImg);

    expect($stageImg[1] ?? null)->not->toBeNull()
        ->and($stageImg[1])->not->toContain('object-fit')
        ->and($stageImg[1])->toContain('width: auto')
        ->and($css)->not->toContain('rgb(var(--primary-500))')
        ->and($css)->toContain('outline: 2px solid var(--primary-500)');
});

it('compiles every Alpine handler, including those passed to Blade components', function () {
    $value = MediaFieldValue::factory()->for(Media::factory(), 'source')->create();
    MediaConversion::factory()->for($value, 'value')->create(['variation' => 'desktop', 'status' => ConversionStatus::Failed]);
    MediaConversion::factory()->for($value, 'value')->create(['variation' => 'mobile', 'media_id' => Media::factory()->create()->id]);

    // Blade does not compile @js() inside a component's attributes: it would reach the browser as is.
    Livewire::test(RenderHarness::class, ['preset' => $value->id])
        ->assertDontSee('@js(', escape: false)
        ->assertSee('x-on:click="pick(&#039;mobile&#039;)"', escape: false);
});

it('does not restyle the media library toolbar', function () {
    $css = file_get_contents(dirname(__DIR__) . '/resources/dist/filament-media-manager.css');

    expect(substr_count($css, '.fmm-toolbar {'))->toBe(1)
        ->and(substr_count($css, '.fmm-toolbar-actions {'))->toBe(1);
});

it('lets the base image set the focus point that following variations take', function () {
    $value = MediaFieldValue::factory()->for(Media::factory()->state(['width' => 2000, 'height' => 1000]), 'source')->create();

    $html = Livewire::test(RenderHarness::class, ['preset' => $value->id])->html();

    expect($html)->toContain('Drag on the image to set the focus point every format follows')
        ->toContain('Follows the base focus point')
        ->toContain('title="Own focus point"')
        ->toContain('useBaseFocus(')
        // The stage drags the base focus too, not only a variation's.
        ->not->toContain('if (active === null) return;');
});

it('offers the base focus point only to variations using the base image, and reset to an own image', function () {
    $value = MediaFieldValue::factory()->for(Media::factory(), 'source')->create();
    MediaConversion::factory()->for($value, 'value')->create(['variation' => 'mobile', 'media_id' => Media::factory()->create()->id]);

    $html = Livewire::test(RenderHarness::class, ['preset' => $value->id])->html();
    $toolbar = fn (string $name): string => str($html)->after('data-fmm-toolbar="' . $name . '"')->before('data-fmm-toolbar=')->toString();

    expect($toolbar('desktop'))->toContain('Use base focus point')->not->toContain('>Reset')
        ->and($toolbar('mobile'))->toContain('Reset')->not->toContain('Use base focus point');
});

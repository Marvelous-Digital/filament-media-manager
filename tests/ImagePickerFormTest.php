<?php

use Filament\Actions\Concerns\InteractsWithActions;
use Filament\Actions\Contracts\HasActions;
use Filament\Actions\Testing\TestAction;
use Filament\Forms\Components\Builder;
use Filament\Forms\Components\Builder\Block;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Schemas\Schema;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Livewire\Component;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use MarvelousDigital\FilamentMediaManager\Enums\ConversionStatus;
use MarvelousDigital\FilamentMediaManager\Forms\Components\ImagePicker;
use MarvelousDigital\FilamentMediaManager\Jobs\GenerateConversion;
use MarvelousDigital\FilamentMediaManager\Models\Media;
use MarvelousDigital\FilamentMediaManager\Models\MediaConversion;
use MarvelousDigital\FilamentMediaManager\Models\MediaFieldValue;
use MarvelousDigital\FilamentMediaManager\Support\ImagePickerState;

class ImagePickerFormHarness extends Component implements HasActions, HasForms
{
    use InteractsWithActions;
    use InteractsWithForms;

    /** @var array<string, mixed> */
    public array $data = [];

    /** @var array<string, mixed> */
    public array $saved = [];

    public ?int $preset = null;

    public bool $withRequiredTitle = false;

    public bool $requireHero = false;

    public function mount(): void
    {
        $this->form->fill(['hero' => $this->preset]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('title')->required($this->withRequiredTitle),
                ImagePicker::make('hero')->variations(['desktop' => [1500, 900], 'mobile' => [480, 640]])->required($this->requireHero),
            ])
            ->statePath('data');
    }

    public function save(): void
    {
        $this->saved = $this->form->getState();
    }

    public function render(): string
    {
        return '<div></div>';
    }
}

class BuilderImagePickerFormHarness extends Component implements HasActions, HasForms
{
    use InteractsWithActions;
    use InteractsWithForms;

    /** @var array<string, mixed> */
    public array $data = [];

    /** @var array<string, mixed> */
    public array $saved = [];

    public function mount(): void
    {
        $this->form->fill(['blocks' => [['type' => 'image', 'data' => ['image' => null]]]]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Builder::make('blocks')->blocks([
                    Block::make('image')->schema([
                        ImagePicker::make('image')->variations(['hero' => [1600, 600]]),
                    ]),
                ]),
            ])
            ->statePath('data');
    }

    public function save(): void
    {
        $this->saved = $this->form->getState();
    }

    public function render(): string
    {
        return '<div></div>';
    }
}

function heroKey(Testable $livewire): string
{
    return collect($livewire->instance()->form->getFlatFields(withHidden: true))
        ->first(fn ($field): bool => $field instanceof ImagePicker)
        ->getKey();
}

function callPicker(Testable $livewire, string $method, array $arguments = []): Testable
{
    return $livewire->call('callSchemaComponentMethod', heroKey($livewire), $method, $arguments);
}

/**
 * TestAction::schemaComponent() prepends the default testing schema name ("form") itself, so
 * it needs the key relative to that schema — unlike callSchemaComponentMethod(), which needs
 * the fully-qualified key heroKey() returns. See vendor/filament/actions/src/Testing/TestAction.php
 * toArray() and vendor/filament/schemas/src/Concerns/HasComponents.php:251 getAction().
 */
function heroRelativeKey(Testable $livewire): string
{
    return collect($livewire->instance()->form->getFlatFields(withHidden: true))
        ->first(fn ($field): bool => $field instanceof ImagePicker)
        ->getKey(false);
}

beforeEach(fn () => Queue::fake());

it('hydrates a saved id into structured state', function () {
    $value = MediaFieldValue::factory()->for(Media::factory(), 'source')->create(['alt_text' => 'Main']);

    Livewire::test(ImagePickerFormHarness::class, ['preset' => $value->id])
        ->assertSet('data.hero.id', $value->id)
        ->assertSet('data.hero.alt', 'Main')
        ->assertSet('data.hero.dirty', false)
        ->assertSet('data.hero.variants.mobile.zoom', 1.0);
});

it('selects a library image into state without touching the database', function () {
    $media = Media::factory()->create(['alt_text' => 'Cat']);

    $livewire = Livewire::test(ImagePickerFormHarness::class);
    callPicker($livewire, 'selectImage', ['target' => 'main', 'mediaId' => $media->id]);

    $livewire->assertSet('data.hero.media_id', $media->id)
        ->assertSet('data.hero.alt', 'Cat')
        ->assertSet('data.hero.dirty', true);
    expect(MediaFieldValue::query()->count())->toBe(0);
});

it('overrides and resets a single variant', function () {
    $main = Media::factory()->create();
    $override = Media::factory()->create(['alt_text' => 'Speaker']);

    $livewire = Livewire::test(ImagePickerFormHarness::class);
    callPicker($livewire, 'selectImage', ['target' => 'main', 'mediaId' => $main->id]);
    callPicker($livewire, 'selectImage', ['target' => 'mobile', 'mediaId' => $override->id]);

    $livewire->assertSet('data.hero.variants.mobile.media_id', $override->id)
        ->assertSet('data.hero.variants.mobile.alt', 'Speaker')
        ->assertSet('data.hero.variants.desktop.media_id', null);

    callPicker($livewire, 'useMainImage', ['variation' => 'mobile']);

    $livewire->assertSet('data.hero.variants.mobile.media_id', null);
});

it('uploads straight into the field and selects the new image', function () {
    Storage::fake('public');

    $livewire = Livewire::test(ImagePickerFormHarness::class)
        ->set('componentFileAttachments.data.hero', UploadedFile::fake()->image('new.jpg', 800, 600));
    callPicker($livewire, 'storeUploadedImage', ['target' => 'main']);

    $media = Media::query()->sole();
    $livewire->assertSet('data.hero.media_id', $media->id)
        ->assertSet('componentFileAttachments.data.hero', null);
    expect($media->width)->toBe(800);
});

it('persists on save and writes the new id back into state', function () {
    $media = Media::factory()->create();

    $livewire = Livewire::test(ImagePickerFormHarness::class);
    callPicker($livewire, 'selectImage', ['target' => 'main', 'mediaId' => $media->id]);
    $livewire->set('data.hero.variants.desktop.focus_x', 0.25)->call('save');

    $value = MediaFieldValue::with('conversions')->sole();
    $livewire->assertSet('saved.hero', $value->id)
        ->assertSet('data.hero.id', $value->id)
        ->assertSet('data.hero.dirty', false);
    expect($value->conversion('desktop')->focus_x)->toBe(0.25);
});

it('updates the same row on a second save', function () {
    $media = Media::factory()->create();

    $livewire = Livewire::test(ImagePickerFormHarness::class);
    callPicker($livewire, 'selectImage', ['target' => 'main', 'mediaId' => $media->id]);
    $livewire->call('save')
        ->set('data.hero.alt', 'Second pass')
        ->call('save');

    expect(MediaFieldValue::query()->count())->toBe(1)
        ->and(MediaFieldValue::query()->sole()->alt_text)->toBe('Second pass');
});

it('persists nothing when another field fails validation', function () {
    $media = Media::factory()->create();

    $livewire = Livewire::test(ImagePickerFormHarness::class, ['withRequiredTitle' => true]);
    callPicker($livewire, 'selectImage', ['target' => 'main', 'mediaId' => $media->id]);
    $livewire->call('save')->assertHasErrors(['data.title']);

    expect(MediaFieldValue::query()->count())->toBe(0);
    Queue::assertNothingPushed();
});

it('fails validation when a picked image was deleted before saving', function () {
    $media = Media::factory()->create();

    $livewire = Livewire::test(ImagePickerFormHarness::class);
    callPicker($livewire, 'selectImage', ['target' => 'main', 'mediaId' => $media->id]);
    $media->delete();

    $livewire->call('save')->assertHasErrors(['data.hero']);
    expect(MediaFieldValue::query()->count())->toBe(0);
});

it('removes a saved image on save', function () {
    $value = MediaFieldValue::factory()->for(Media::factory(), 'source')->create();

    $livewire = Livewire::test(ImagePickerFormHarness::class, ['preset' => $value->id]);
    callPicker($livewire, 'removeImage');
    $livewire->call('save')->assertSet('saved.hero', null);

    expect(MediaFieldValue::query()->count())->toBe(0);
});

it('fails validation on a required field with no image and persists nothing', function () {
    $livewire = Livewire::test(ImagePickerFormHarness::class, ['requireHero' => true]);

    $livewire->call('save')->assertHasErrors(['data.hero']);

    expect(MediaFieldValue::query()->count())->toBe(0);
    Queue::assertNothingPushed();
});

it('fails validation on a required field whose saved image was removed', function () {
    $value = MediaFieldValue::factory()->for(Media::factory(), 'source')->create();

    $livewire = Livewire::test(ImagePickerFormHarness::class, ['preset' => $value->id, 'requireHero' => true]);
    callPicker($livewire, 'removeImage');

    $livewire->call('save')->assertHasErrors(['data.hero']);

    expect(MediaFieldValue::query()->count())->toBe(1);
    expect(MediaFieldValue::query()->find($value->id))->not->toBeNull();
});

it('persists when nested inside a builder block', function () {
    $media = Media::factory()->create();

    $livewire = Livewire::test(BuilderImagePickerFormHarness::class);
    $itemKey = array_key_first($livewire->get('data.blocks'));
    $livewire->set("data.blocks.{$itemKey}.data.image", ImagePickerState::withMainImage(null, $media, ['hero' => [1600, 600]]))
        ->call('save');

    $value = MediaFieldValue::query()->sole();
    expect(data_get($livewire->get('saved'), 'blocks.0.data.image'))->toBe($value->id);
});

it('re-dispatches generation via retryConversion for its own conversion only', function () {
    $own = MediaFieldValue::factory()->for(Media::factory(), 'source')->create();
    $ownConversion = MediaConversion::factory()->for($own, 'value')->create(['variation' => 'desktop', 'status' => ConversionStatus::Failed]);
    $foreign = MediaConversion::factory()->for(MediaFieldValue::factory()->for(Media::factory(), 'source'), 'value')->create(['status' => ConversionStatus::Failed]);

    $livewire = Livewire::test(ImagePickerFormHarness::class, ['preset' => $own->id]);
    $livewire->callAction(TestAction::make('retryConversion')->schemaComponent(heroRelativeKey($livewire)), arguments: ['conversion' => $foreign->id]);
    $livewire->callAction(TestAction::make('retryConversion')->schemaComponent(heroRelativeKey($livewire)), arguments: ['conversion' => $ownConversion->id]);

    expect($ownConversion->refresh()->status)->toBe(ConversionStatus::Pending)
        ->and($foreign->refresh()->status)->toBe(ConversionStatus::Failed);
    Queue::assertPushed(GenerateConversion::class, 1);
});

function foreignHeroValue(): MediaFieldValue
{
    $value = MediaFieldValue::factory()->for(Media::factory(), 'source')->create(['alt_text' => 'Not yours']);
    MediaConversion::factory()->for($value, 'value')->create(['variation' => 'hero', 'status' => ConversionStatus::Failed]);

    return $value;
}

it('saves an image whose id the browser rewrote as a new row', function () {
    $foreign = foreignHeroValue();
    $media = Media::factory()->create();

    $livewire = Livewire::test(ImagePickerFormHarness::class);
    callPicker($livewire, 'selectImage', ['target' => 'main', 'mediaId' => $media->id]);
    $livewire->set('data.hero.id', $foreign->id)
        ->set('data.hero.alt', 'Hijacked')
        ->call('save')
        ->assertHasNoErrors();

    $saved = $livewire->get('saved.hero');
    $foreign->refresh();

    expect($saved)->not->toBe($foreign->id)
        ->and(MediaFieldValue::find($saved)->alt_text)->toBe('Hijacked')
        ->and($foreign->media_id)->not->toBe($media->id)
        ->and($foreign->alt_text)->toBe('Not yours')
        ->and($foreign->conversions()->pluck('variation')->all())->toBe(['hero']);
});

it('ignores a removal of an id the browser made up', function () {
    $foreign = foreignHeroValue();

    Livewire::test(ImagePickerFormHarness::class)
        ->set('data.hero', ['id' => $foreign->id, 'token' => 'forged', 'media_id' => null, 'dirty' => true, 'variants' => []])
        ->call('save')
        ->assertHasNoErrors()
        ->assertSet('saved.hero', null);

    expect(MediaFieldValue::query()->find($foreign->id))->not->toBeNull()
        ->and(MediaConversion::query()->where('media_field_value_id', $foreign->id)->count())->toBe(1);
});

it('saves an id the browser set as a plain value as a new row', function () {
    $foreign = foreignHeroValue();

    $livewire = Livewire::test(ImagePickerFormHarness::class)
        ->set('data.hero', $foreign->id)
        ->call('save')
        ->assertHasNoErrors();

    $saved = $livewire->get('saved.hero');

    expect($saved)->not->toBe($foreign->id)
        ->and(MediaFieldValue::find($saved)->media_id)->toBe($foreign->media_id)
        ->and($foreign->conversions()->pluck('variation')->all())->toBe(['hero']);
});

it('coerces malformed state instead of failing', function () {
    $media = Media::factory()->create();

    $livewire = Livewire::test(ImagePickerFormHarness::class);
    callPicker($livewire, 'selectImage', ['target' => 'main', 'mediaId' => $media->id]);
    $livewire->set('data.hero.variants', 'junk')
        ->set('data.hero.alt', ['x'])
        ->call('save')
        ->assertHasNoErrors();

    $value = MediaFieldValue::with('conversions')->find($livewire->get('saved.hero'));

    expect($value->media_id)->toBe($media->id)
        ->and($value->conversions->pluck('variation')->sort()->values()->all())->toBe(['desktop', 'mobile']);
});

it('does not retry a conversion of a row the browser pointed it at', function () {
    $foreign = foreignHeroValue();
    $conversion = $foreign->conversions()->sole();

    $livewire = Livewire::test(ImagePickerFormHarness::class)
        ->set('data.hero', ['id' => $foreign->id, 'media_id' => $foreign->media_id, 'variants' => []]);
    $livewire->callAction(TestAction::make('retryConversion')->schemaComponent(heroRelativeKey($livewire)), arguments: ['conversion' => $conversion->id]);

    expect($conversion->refresh()->status)->toBe(ConversionStatus::Failed);
    Queue::assertNothingPushed();
});

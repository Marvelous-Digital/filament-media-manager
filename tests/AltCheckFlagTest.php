<?php

use Filament\Actions\Concerns\InteractsWithActions;
use Filament\Actions\Contracts\HasActions;
use Filament\Actions\Testing\TestAction;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Livewire\Component;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use MarvelousDigital\FilamentMediaManager\Actions\DuplicateMediaFieldValue;
use MarvelousDigital\FilamentMediaManager\Actions\PersistImagePicker;
use MarvelousDigital\FilamentMediaManager\Events\AltCheckConfirmed;
use MarvelousDigital\FilamentMediaManager\Forms\Components\ImagePicker;
use MarvelousDigital\FilamentMediaManager\Forms\Components\ImagesPicker;
use MarvelousDigital\FilamentMediaManager\Models\Media;
use MarvelousDigital\FilamentMediaManager\Models\MediaFieldValue;
use MarvelousDigital\FilamentMediaManager\Support\ImagePickerState;

class AltCheckHarness extends Component implements HasActions, HasForms
{
    use InteractsWithActions;
    use InteractsWithForms;

    public array $data = [];

    public ?int $preset = null;

    public bool $disabled = false;

    public function mount(): void
    {
        $this->form->fill(['hero_image_id' => $this->preset]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components([ImagePicker::make('hero_image_id')->variations(['desktop' => [1500, 900]])->disabled($this->disabled)])->statePath('data');
    }

    public function render(): string
    {
        return '<div>{{ $this->form }}</div>';
    }
}

class AltCheckGalleryHarness extends Component implements HasActions, HasForms
{
    use InteractsWithActions;
    use InteractsWithForms;

    public array $data = [];

    public array $preset = [];

    public bool $disabled = false;

    public function mount(): void
    {
        $this->form->fill(['gallery' => $this->preset]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components([ImagesPicker::make('gallery')->disabled($this->disabled)])->statePath('data');
    }

    public function render(): string
    {
        return '<div>{{ $this->form }}</div>';
    }
}

/** TestAction::schemaComponent() prepends the "form" schema name itself, so it needs the relative key. */
function pickerKey(Testable $livewire): string
{
    return collect($livewire->instance()->form->getFlatFields(withHidden: true))
        ->first(fn ($field): bool => $field instanceof ImagePicker || $field instanceof ImagesPicker)
        ->getKey(false);
}

beforeEach(fn () => Queue::fake());

$variations = ['desktop' => [1500, 900], 'mobile' => [480, 640]];

/** A flagged stored row plus a copy-on-write save of its state (id null) that applies $change. */
function flaggedCopy(array $variations, Closure $change, array $stored = []): MediaFieldValue
{
    $value = MediaFieldValue::factory()->for(Media::factory(), 'source')->create([
        'alt_text' => 'Cat',
        'alt_check_required' => true,
        ...$stored,
    ]);

    $state = ImagePickerState::fromStoredValue($value->id, $variations);
    $state = $change([...$state, 'id' => null, 'token' => null], $value);

    $id = app(PersistImagePicker::class)->execute($state, $variations, replaces: $value->id);

    return MediaFieldValue::findOrFail($id);
}

it('a focus change keeps the flag', function () use ($variations) {
    $copy = flaggedCopy($variations, fn (array $s) => [...$s, 'focus_x' => 0.2, 'focus_y' => 0.8]);

    expect($copy->alt_check_required)->toBeTrue();
});

it('a zoom change keeps the flag', function () use ($variations) {
    $copy = flaggedCopy($variations, function (array $s) {
        $s['variants']['desktop']['zoom'] = 2.0;
        $s['variants']['desktop']['custom'] = true;

        return $s;
    });

    expect($copy->alt_check_required)->toBeTrue();
});

it('an alt change clears the flag', function () use ($variations) {
    $copy = flaggedCopy($variations, fn (array $s) => [...$s, 'alt' => 'A cat on a sofa']);

    expect($copy->alt_check_required)->toBeFalse();
});

it('an override alt change clears the flag', function () use ($variations) {
    $override = Media::factory()->create();
    $value = MediaFieldValue::factory()->for(Media::factory(), 'source')->create(['alt_text' => 'Cat', 'alt_check_required' => true]);
    $value->conversions()->create(['variation' => 'mobile', 'media_id' => $override->id, 'alt_text' => 'Own', 'width' => 480, 'height' => 640, 'focus_x' => 0.5, 'focus_y' => 0.5, 'custom_focus' => true, 'zoom' => 1]);

    $unchanged = ImagePickerState::fromStoredValue($value->id, $variations);
    $persist = fn (array $state) => MediaFieldValue::findOrFail(app(PersistImagePicker::class)->execute([...$state, 'id' => null, 'token' => null], $variations, replaces: $value->id));

    expect($persist($unchanged)->alt_check_required)->toBeTrue();

    $edited = $unchanged;
    $edited['variants']['mobile']['alt'] = 'Own, edited';

    expect($persist($edited)->alt_check_required)->toBeFalse();
});

it('switching to decorative clears the flag', function () use ($variations) {
    $copy = flaggedCopy($variations, fn (array $s) => [...$s, 'decorative' => true]);

    expect($copy->alt_check_required)->toBeFalse();
});

it('a different main image clears the flag', function () use ($variations) {
    $other = Media::factory()->create();
    $copy = flaggedCopy($variations, fn (array $s) => [...$s, 'media_id' => $other->id]);

    expect($copy->alt_check_required)->toBeFalse();
});

it('does not flag a row that was not flagged', function () use ($variations) {
    $copy = flaggedCopy($variations, fn (array $s) => $s, ['alt_check_required' => false]);

    expect($copy->alt_check_required)->toBeFalse();
});

it('a plain locale copy keeps the flag', function () {
    $value = MediaFieldValue::factory()->for(Media::factory(), 'source')->create(['alt_text' => 'Cat', 'alt_check_required' => true]);

    $copy = MediaFieldValue::find(app(DuplicateMediaFieldValue::class)->execute($value->id));

    expect($copy->alt_check_required)->toBeTrue();
});

it('a translated copy clears the flag', function () {
    $value = MediaFieldValue::factory()->for(Media::factory(), 'source')->create(['alt_text' => 'Cat', 'alt_check_required' => true]);

    $copy = MediaFieldValue::find(app(DuplicateMediaFieldValue::class)->execute($value->id, fn (string $alt) => "fr: {$alt}"));

    expect($copy->alt_check_required)->toBeFalse();
});

it('confirmAlt clears the flag in place', function () {
    $value = MediaFieldValue::factory()->for(Media::factory(), 'source')->create(['alt_check_required' => true]);

    $value->confirmAlt();

    expect($value->refresh()->alt_check_required)->toBeFalse();
});

it('the picker shows the replaced notice for a flagged value', function () {
    $flagged = MediaFieldValue::factory()->for(Media::factory(), 'source')->create(['alt_check_required' => true]);
    $plain = MediaFieldValue::factory()->for(Media::factory(), 'source')->create();

    Livewire::test(AltCheckHarness::class, ['preset' => $flagged->id])
        ->assertSee(__('filament-media-manager::media.alt_check.notice'))
        ->assertSee(__('filament-media-manager::media.alt_check.confirm'));

    Livewire::test(AltCheckHarness::class, ['preset' => $plain->id])
        ->assertDontSee(__('filament-media-manager::media.alt_check.notice'))
        ->assertDontSee(__('filament-media-manager::media.alt_check.confirm'));
});

it('has en and fr strings for the notice', function () {
    expect(__('filament-media-manager::media.alt_check.notice', [], 'en'))->toBe('Image replaced: check the alt text')
        ->and(__('filament-media-manager::media.alt_check.confirm', [], 'en'))->toBe('Alt is fine')
        ->and(__('filament-media-manager::media.alt_check.notice', [], 'fr'))->toBe('Image remplacée : vérifiez le texte alternatif')
        ->and(__('filament-media-manager::media.alt_check.confirm', [], 'fr'))->toBe('Le texte alternatif est correct');
});

it('confirming the alt clears the flag in place', function () {
    $value = MediaFieldValue::factory()->for(Media::factory(), 'source')->create(['alt_check_required' => true]);

    $livewire = Livewire::test(AltCheckHarness::class, ['preset' => $value->id]);
    $livewire
        ->callAction(TestAction::make('confirmAlt')->schemaComponent(pickerKey($livewire)))
        ->assertDontSee(__('filament-media-manager::media.alt_check.notice'));

    expect($value->refresh()->alt_check_required)->toBeFalse()
        ->and(MediaFieldValue::query()->count())->toBe(1);
});

it('confirming never clears a row the picker did not load', function () {
    $loaded = MediaFieldValue::factory()->for(Media::factory(), 'source')->create(['alt_check_required' => true]);
    $other = MediaFieldValue::factory()->for(Media::factory(), 'source')->create(['alt_check_required' => true]);

    $livewire = Livewire::test(AltCheckHarness::class, ['preset' => $loaded->id]);
    $livewire
        ->set('data.hero_image_id.id', $other->id)
        ->call('$refresh')
        ->callAction(TestAction::make('confirmAlt')->schemaComponent(pickerKey($livewire)), arguments: ['id' => $other->id]);

    expect($other->refresh()->alt_check_required)->toBeTrue();
});

it('the gallery picker shows the notice per flagged item and confirms by key', function () {
    $flagged = MediaFieldValue::factory()->for(Media::factory(), 'source')->create(['alt_check_required' => true]);
    $plain = MediaFieldValue::factory()->for(Media::factory(), 'source')->create();
    $other = MediaFieldValue::factory()->for(Media::factory(), 'source')->create(['alt_check_required' => true]);

    $component = Livewire::test(AltCheckGalleryHarness::class, ['preset' => [$flagged->id, $plain->id]]);

    expect(substr_count($component->html(), __('filament-media-manager::media.alt_check.notice')))->toBe(1);

    // A key naming a row the picker never loaded does nothing.
    $component->callAction(TestAction::make('confirmAlt')->schemaComponent(pickerKey($component)), arguments: ['key' => "v{$other->id}"]);
    expect($other->refresh()->alt_check_required)->toBeTrue()
        ->and($flagged->refresh()->alt_check_required)->toBeTrue();

    $component->callAction(TestAction::make('confirmAlt')->schemaComponent(pickerKey($component)), arguments: ['key' => "v{$flagged->id}"]);

    $component->assertDontSee(__('filament-media-manager::media.alt_check.notice'));

    expect($flagged->refresh()->alt_check_required)->toBeFalse()
        ->and($other->refresh()->alt_check_required)->toBeTrue();
});

it('a disabled picker offers no alt confirmation and ignores one', function () {
    $value = MediaFieldValue::factory()->for(Media::factory(), 'source')->create(['alt_check_required' => true]);

    $livewire = Livewire::test(AltCheckHarness::class, ['preset' => $value->id, 'disabled' => true])
        ->assertDontSee(__('filament-media-manager::media.alt_check.confirm'));

    try {
        $livewire->callAction(TestAction::make('confirmAlt')->schemaComponent(pickerKey($livewire)));
    } catch (Throwable) {
    }

    expect($value->refresh()->alt_check_required)->toBeTrue();
});

it('a disabled gallery picker offers no alt confirmation and ignores one', function () {
    $value = MediaFieldValue::factory()->for(Media::factory(), 'source')->create(['alt_check_required' => true]);

    $livewire = Livewire::test(AltCheckGalleryHarness::class, ['preset' => [$value->id], 'disabled' => true])
        ->assertDontSee(__('filament-media-manager::media.alt_check.confirm'));

    try {
        $livewire->callAction(TestAction::make('confirmAlt')->schemaComponent(pickerKey($livewire)), arguments: ['key' => "v{$value->id}"]);
    } catch (Throwable) {
    }

    expect($value->refresh()->alt_check_required)->toBeTrue();
});

it('confirming the alt dispatches AltCheckConfirmed', function () {
    Event::fake([AltCheckConfirmed::class]);
    $value = MediaFieldValue::factory()->for(Media::factory(), 'source')->create(['alt_check_required' => true]);

    $value->confirmAlt();

    Event::assertDispatched(
        AltCheckConfirmed::class,
        fn ($event) => $event->value->is($value),
    );
});

it('a locale copy on a site without the alt check column still copies', function () {
    Illuminate\Support\Facades\Schema::table(config('filament-media-manager.table_names.field_values'), fn ($table) => $table->dropColumn('alt_check_required'));
    $value = MediaFieldValue::factory()->for(Media::factory(), 'source')->create(['alt_text' => 'Cat']);

    $copy = MediaFieldValue::find(app(DuplicateMediaFieldValue::class)->execute($value->id));

    expect($copy)->not->toBeNull()->and($copy->alt_text)->toBe('Cat');
});

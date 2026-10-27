<?php

use Filament\Actions\Concerns\InteractsWithActions;
use Filament\Actions\Contracts\HasActions;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Schemas\Schema;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Livewire\Component;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use MarvelousDigital\FilamentMediaManager\Enums\MediaType;
use MarvelousDigital\FilamentMediaManager\Forms\Components\ImagesPicker;
use MarvelousDigital\FilamentMediaManager\Models\Media;
use MarvelousDigital\FilamentMediaManager\Models\MediaConversion;
use MarvelousDigital\FilamentMediaManager\Models\MediaFieldValue;

class ImagesPickerFormHarness extends Component implements HasActions, HasForms
{
    use InteractsWithActions;
    use InteractsWithForms;

    /** @var array<string, mixed> */
    public array $data = [];

    /** @var array<string, mixed> */
    public array $saved = [];

    /** @var list<int> */
    public array $preset = [];

    public bool $requireGallery = false;

    public bool $acceptPdf = false;

    /** @var array<string, array{0: int, 1: int}> */
    public array $galleryVariations = ['tile' => [800, 450]];

    public function mount(): void
    {
        $this->form->fill(['gallery' => $this->preset]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                ImagesPicker::make('gallery')
                    ->variations($this->galleryVariations)
                    ->required($this->requireGallery)
                    ->when($this->acceptPdf, fn (ImagesPicker $field) => $field->fileAttachmentsAcceptedFileTypes(['image/jpeg', 'application/pdf'])),
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

$galleryPicker = function (Testable $livewire, string $method, array $arguments = []): Testable {
    $key = collect($livewire->instance()->form->getFlatFields(withHidden: true))
        ->first(fn ($field): bool => $field instanceof ImagesPicker)
        ->getKey();

    return $livewire->call('callSchemaComponentMethod', $key, $method, $arguments);
};

beforeEach(fn () => Queue::fake());

it('selects several images and saves them in order', function () use ($galleryPicker) {
    [$first, $second, $third] = Media::factory()->count(3)->create();

    $livewire = Livewire::test(ImagesPickerFormHarness::class);
    $galleryPicker($livewire, 'selectImage', ['target' => 'append', 'mediaId' => $first->id]);
    $galleryPicker($livewire, 'selectImage', ['target' => 'append', 'mediaId' => $second->id]);
    $galleryPicker($livewire, 'selectImage', ['target' => 'append', 'mediaId' => $third->id]);

    expect(MediaFieldValue::query()->count())->toBe(0);

    $livewire->call('save')->assertHasNoErrors();

    $ids = $livewire->get('saved.gallery');
    expect($ids)->toHaveCount(3)
        ->and(array_map(fn (int $id): int => MediaFieldValue::find($id)->media_id, $ids))->toBe([$first->id, $second->id, $third->id])
        ->and(array_column($livewire->get('data.gallery.items'), 'id'))->toBe($ids);

    $livewire->call('save');

    expect(MediaFieldValue::query()->count())->toBe(3)
        ->and($livewire->get('saved.gallery'))->toBe($ids);
});

it('uploads an image into the list', function () use ($galleryPicker) {
    Storage::fake('public');
    $existing = Media::factory()->create();

    $livewire = Livewire::test(ImagesPickerFormHarness::class);
    $galleryPicker($livewire, 'selectImage', ['target' => 'append', 'mediaId' => $existing->id]);
    $livewire->set('componentFileAttachments.data.gallery', UploadedFile::fake()->image('new.jpg', 800, 600));
    $galleryPicker($livewire, 'storeUploadedImage', ['target' => 'append']);

    $uploaded = Media::query()->whereKeyNot($existing->id)->sole();
    expect(array_column($livewire->get('data.gallery.items'), 'media_id'))->toBe([$existing->id, $uploaded->id]);
    $livewire->assertSet('componentFileAttachments.data.gallery', null);
});

it('removes an image and deletes its row on save', function () use ($galleryPicker) {
    $kept = MediaFieldValue::factory()->for(Media::factory(), 'source')->create();
    $dropped = MediaFieldValue::factory()->for(Media::factory(), 'source')->create();

    $livewire = Livewire::test(ImagesPickerFormHarness::class, ['preset' => [$kept->id, $dropped->id]]);
    $galleryPicker($livewire, 'removeImage', ['key' => "v{$dropped->id}"]);

    expect(array_column($livewire->get('data.gallery.items'), 'id'))->toBe([$kept->id]);

    $livewire->call('save')->assertSet('saved.gallery', [$kept->id]);

    expect(MediaFieldValue::query()->pluck('id')->all())->toBe([$kept->id]);
});

it('reorders images', function () use ($galleryPicker) {
    $values = MediaFieldValue::factory()->count(3)->for(Media::factory(), 'source')->create();
    [$a, $b, $c] = $values->pluck('id')->all();

    $livewire = Livewire::test(ImagesPickerFormHarness::class, ['preset' => [$a, $b, $c]]);
    $galleryPicker($livewire, 'reorderImages', ['keys' => ["v{$c}", "v{$a}", "v{$b}"]]);

    expect(array_column($livewire->get('data.gallery.items'), 'id'))->toBe([$c, $a, $b]);

    $livewire->call('save')->assertSet('saved.gallery', [$c, $a, $b]);
    expect(MediaFieldValue::query()->count())->toBe(3);
});

it('fails required with no images', function () {
    Livewire::test(ImagesPickerFormHarness::class, ['requireGallery' => true])
        ->call('save')
        ->assertHasErrors(['data.gallery']);

    expect(MediaFieldValue::query()->count())->toBe(0);
});

it('fails when an image was deleted from the library', function () use ($galleryPicker) {
    [$kept, $deleted] = Media::factory()->count(2)->create();

    $livewire = Livewire::test(ImagesPickerFormHarness::class);
    $galleryPicker($livewire, 'selectImage', ['target' => 'append', 'mediaId' => $kept->id]);
    $galleryPicker($livewire, 'selectImage', ['target' => 'append', 'mediaId' => $deleted->id]);
    $deleted->delete();

    $livewire->call('save')->assertHasErrors(['data.gallery']);

    expect($livewire->errors()->first('data.gallery'))->toBe('This image is no longer in the library.')
        ->and(MediaFieldValue::query()->count())->toBe(0);
});

it('saves alt text per image', function () use ($galleryPicker) {
    [$first, $second] = Media::factory()->count(2)->create(['alt_text' => null]);

    $livewire = Livewire::test(ImagesPickerFormHarness::class);
    $galleryPicker($livewire, 'selectImage', ['target' => 'append', 'mediaId' => $first->id]);
    $galleryPicker($livewire, 'selectImage', ['target' => 'append', 'mediaId' => $second->id]);
    $livewire->set('data.gallery.items.0.alt', 'First')
        ->set('data.gallery.items.1.alt', 'Second')
        ->call('save');

    $ids = $livewire->get('saved.gallery');
    expect(MediaFieldValue::find($ids[0])->alt_text)->toBe('First')
        ->and(MediaFieldValue::find($ids[1])->alt_text)->toBe('Second');
});

$foreignGalleryValue = fn (): MediaFieldValue => tap(
    MediaFieldValue::factory()->for(Media::factory(), 'source')->create(['alt_text' => 'Not yours']),
    fn (MediaFieldValue $value) => MediaConversion::factory()->for($value, 'value')->create(['variation' => 'hero']),
);

it('ignores a removed id the browser made up', function () use ($foreignGalleryValue) {
    $foreign = $foreignGalleryValue();
    $own = MediaFieldValue::factory()->for(Media::factory(), 'source')->create();

    Livewire::test(ImagesPickerFormHarness::class, ['preset' => [$own->id]])
        ->set('data.gallery.removed', [['id' => $foreign->id, 'token' => 'forged'], ['id' => $foreign->id], $foreign->id])
        ->call('save')
        ->assertHasNoErrors()
        ->assertSet('saved.gallery', [$own->id]);

    expect(MediaFieldValue::query()->find($foreign->id))->not->toBeNull()
        ->and(MediaConversion::query()->where('media_field_value_id', $foreign->id)->count())->toBe(1);
});

it('saves an item whose id the browser rewrote as a new row', function () use ($foreignGalleryValue) {
    $foreign = $foreignGalleryValue();
    $own = MediaFieldValue::factory()->for(Media::factory(), 'source')->create();

    $livewire = Livewire::test(ImagesPickerFormHarness::class, ['preset' => [$own->id]])
        ->set('data.gallery.items.0.id', $foreign->id)
        ->set('data.gallery.items.0.alt', 'Hijacked')
        ->call('save')
        ->assertHasNoErrors();

    $saved = $livewire->get('saved.gallery');
    $foreign->refresh();

    expect($saved)->toHaveCount(1)
        ->and($saved[0])->not->toBe($foreign->id)
        ->and(MediaFieldValue::find($saved[0])->alt_text)->toBe('Hijacked')
        ->and($foreign->alt_text)->toBe('Not yours')
        ->and($foreign->conversions()->pluck('variation')->all())->toBe(['hero']);
});

it('saves ids the browser set as a plain list as new rows', function () use ($foreignGalleryValue) {
    $foreign = $foreignGalleryValue();

    $livewire = Livewire::test(ImagesPickerFormHarness::class)
        ->set('data.gallery', [$foreign->id])
        ->call('save')
        ->assertHasNoErrors();

    $saved = $livewire->get('saved.gallery');

    expect($saved)->toHaveCount(1)
        ->and($saved[0])->not->toBe($foreign->id)
        ->and(MediaFieldValue::find($saved[0])->media_id)->toBe($foreign->media_id)
        ->and($foreign->conversions()->pluck('variation')->all())->toBe(['hero']);
});

it('skips malformed items instead of failing', function () use ($galleryPicker) {
    $media = Media::factory()->create();

    $livewire = Livewire::test(ImagesPickerFormHarness::class);
    $galleryPicker($livewire, 'selectImage', ['target' => 'append', 'mediaId' => $media->id]);
    $livewire->set('data.gallery.items.1', 'junk')
        ->set('data.gallery.items.2', ['id' => 'x', 'media_id' => ['x']])
        ->assertOk()
        ->call('save')
        ->assertHasNoErrors();

    $saved = $livewire->get('saved.gallery');

    expect($saved)->toHaveCount(1)
        ->and(MediaFieldValue::find($saved[0])->media_id)->toBe($media->id);
});

it('confirms each image added while the library stays open', function () use ($galleryPicker) {
    $media = Media::factory()->create();

    $livewire = Livewire::test(ImagesPickerFormHarness::class);
    $galleryPicker($livewire, 'selectImage', ['target' => 'append', 'mediaId' => $media->id]);

    $livewire->assertNotified('Image added');
});

it('does not append an uploaded file that is not an image', function () use ($galleryPicker) {
    // Filament only lets images through by default; a field may widen the accepted types.
    Storage::fake('public');

    $livewire = Livewire::test(ImagesPickerFormHarness::class, ['acceptPdf' => true])
        ->set('componentFileAttachments.data.gallery', UploadedFile::fake()->createWithContent('notes.pdf', "%PDF-1.4\n%%EOF"));
    $galleryPicker($livewire, 'storeUploadedImage', ['target' => 'append']);

    expect(Media::query()->sole()->type)->toBe(MediaType::Document);
    $livewire->assertSet('data.gallery.items', [])
        ->assertNotified('Upload failed')
        ->assertSet('componentFileAttachments.data.gallery', null);
});

it('creates no conversions without variations', function () use ($galleryPicker) {
    [$first, $second] = Media::factory()->count(2)->create();

    $livewire = Livewire::test(ImagesPickerFormHarness::class, ['galleryVariations' => []]);
    $galleryPicker($livewire, 'selectImage', ['target' => 'append', 'mediaId' => $first->id]);
    $galleryPicker($livewire, 'selectImage', ['target' => 'append', 'mediaId' => $second->id]);
    $livewire->call('save')->assertHasNoErrors();

    expect($livewire->get('saved.gallery'))->toHaveCount(2)
        ->and(MediaConversion::query()->count())->toBe(0);
    Queue::assertNothingPushed();
});

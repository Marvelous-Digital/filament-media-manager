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
use MarvelousDigital\FilamentMediaManager\Forms\Components\ImagesPicker;
use MarvelousDigital\FilamentMediaManager\Models\Media;
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

    public function mount(): void
    {
        $this->form->fill(['gallery' => $this->preset]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                ImagesPicker::make('gallery')->variations(['tile' => [800, 450]])->required($this->requireGallery),
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

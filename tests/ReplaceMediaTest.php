<?php

use Filament\Actions\Testing\TestAction;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use MarvelousDigital\FilamentMediaManager\Actions\ReplaceMedia;
use MarvelousDigital\FilamentMediaManager\Enums\ConversionStatus;
use MarvelousDigital\FilamentMediaManager\Enums\MediaType;
use MarvelousDigital\FilamentMediaManager\Jobs\GenerateConversion;
use MarvelousDigital\FilamentMediaManager\Models\Media;
use MarvelousDigital\FilamentMediaManager\Models\MediaConversion;
use MarvelousDigital\FilamentMediaManager\Models\MediaFieldValue;
use MarvelousDigital\FilamentMediaManager\Models\MediaFolder;
use MarvelousDigital\FilamentMediaManager\Models\MediaUsageRow;
use MarvelousDigital\FilamentMediaManager\Pages\MediaLibrary;
use MarvelousDigital\FilamentMediaManager\Usage\MediaUsage;
use MarvelousDigital\FilamentMediaManager\Usage\UsageSource;

beforeEach(function () {
    $this->actingAsUser();
    Queue::fake();
    MediaUsage::resetTracking();
});

afterEach(fn () => MediaUsage::resetTracking());

function placement(Media $media, array $attributes = []): MediaFieldValue
{
    return MediaFieldValue::factory()->for($media, 'source')->create($attributes);
}

it('replace repoints placements and flags them', function () {
    $old = Media::factory()->create();
    $new = Media::factory()->create();
    $plain = placement($old);
    $decorative = placement($old, ['decorative' => true]);
    $other = placement(Media::factory()->create());

    $count = app(ReplaceMedia::class)->image($old, $new);

    expect($count)->toBe(2)
        ->and($plain->fresh()->media_id)->toBe($new->id)
        ->and($plain->fresh()->alt_check_required)->toBeTrue()
        ->and($decorative->fresh()->media_id)->toBe($new->id)
        ->and($decorative->fresh()->alt_check_required)->toBeFalse()
        ->and($other->fresh()->alt_check_required)->toBeFalse();
});

it('replace repoints conversion overrides', function () {
    $old = Media::factory()->create();
    $new = Media::factory()->create();
    $value = placement(Media::factory()->create());
    $conversion = MediaConversion::factory()->for($value, 'value')->create(['media_id' => $old->id]);

    app(ReplaceMedia::class)->image($old, $new);

    expect($conversion->fresh()->media_id)->toBe($new->id)
        ->and(Media::find($old->id))->toBeNull();
});

it('replace queues every crop of the affected placements', function () {
    $old = Media::factory()->create();
    $new = Media::factory()->create();
    $a = placement($old);
    $b = placement($old);
    MediaConversion::factory()->for($a, 'value')->create(['variation' => 'hero', 'status' => ConversionStatus::Completed]);
    MediaConversion::factory()->for($a, 'value')->create(['variation' => 'card', 'status' => ConversionStatus::Completed]);
    MediaConversion::factory()->for($b, 'value')->create(['variation' => 'hero', 'status' => ConversionStatus::Completed]);

    app(ReplaceMedia::class)->image($old, $new);

    Queue::assertPushed(GenerateConversion::class, 3);
    expect(MediaConversion::query()->where('status', ConversionStatus::Pending)->count())->toBe(3);
});

it('replace moves usage rows to the new media and deletes the old one', function () {
    activateUsage();
    $old = Media::factory()->create();
    $new = Media::factory()->create();
    useMedia($old, UsageSource::Live);
    useMedia($old, UsageSource::History, revisionId: 3);

    app(ReplaceMedia::class)->image($old, $new);

    expect(MediaUsageRow::query()->where('media_id', $new->id)->count())->toBe(2)
        ->and(MediaUsageRow::query()->where('media_id', $old->id)->count())->toBe(0)
        ->and(Media::find($old->id))->toBeNull();
});

it('replacing with an existing library image keeps that image', function () {
    $old = Media::factory()->create();
    $new = Media::factory()->create(['name' => 'picked']);
    placement($old);

    app(ReplaceMedia::class)->image($old, $new);

    expect($new->fresh()->name)->toBe('picked');
});

it('replace refuses the same media and a document for an image', function () {
    $old = Media::factory()->create();
    $doc = Media::factory()->create(['type' => MediaType::Document, 'mime_type' => 'application/pdf']);

    expect(fn () => app(ReplaceMedia::class)->image($old, $old))->toThrow(InvalidArgumentException::class)
        ->and(fn () => app(ReplaceMedia::class)->image($old, $doc))->toThrow(InvalidArgumentException::class);
});

it('document replace keeps the id and swaps the file', function () {
    Storage::fake('public');
    $oldFile = UploadedFile::fake()->create('old.pdf', 10, 'application/pdf');
    $path = $oldFile->storeAs('media/01DOC', 'old.pdf', ['disk' => 'public']);
    $doc = Media::factory()->create([
        'name' => 'Brochure', 'file_name' => 'old.pdf', 'path' => $path, 'mime_type' => 'application/pdf',
        'type' => MediaType::Document, 'size' => 10 * 1024,
    ]);

    $result = app(ReplaceMedia::class)->document($doc, UploadedFile::fake()->create('new.pdf', 30, 'application/pdf'));

    expect($result->id)->toBe($doc->id)
        ->and($result->name)->toBe('Brochure')
        ->and($result->file_name)->toBe('new.pdf')
        ->and($result->size)->toBe(30 * 1024)
        ->and($result->mime_type)->toBe('application/pdf');
    Storage::disk('public')->assertMissing($path);
    Storage::disk('public')->assertExists($result->path);
    expect(dirname($result->path))->toBe('media/01DOC');
});

it('document replace refuses an image upload and a document replace refuses an image media', function () {
    Storage::fake('public');
    $doc = Media::factory()->create(['type' => MediaType::Document, 'mime_type' => 'application/pdf', 'path' => 'media/01DOC/a.pdf']);
    $image = Media::factory()->create();

    expect(fn () => app(ReplaceMedia::class)->document($doc, UploadedFile::fake()->image('x.jpg')))->toThrow(InvalidArgumentException::class)
        ->and(fn () => app(ReplaceMedia::class)->document($image, UploadedFile::fake()->create('x.pdf', 5, 'application/pdf')))->toThrow(InvalidArgumentException::class);
});

it('the replace action is hidden without the ability and when usage is inactive, visible with the ability', function () {
    $media = Media::factory()->create();
    $visible = fn () => (fn () => $this->replaceMediaAction()->arguments(['media' => $media->id])->isVisible())->call(app(MediaLibrary::class));

    expect($visible())->toBeFalse(); // inactive

    activateUsage();
    expect($visible())->toBeFalse(); // no ability

    Gate::define('replace-media', fn () => true);
    expect($visible())->toBeTrue();

    MediaUsage::resetTracking();
    expect($visible())->toBeFalse();
});

it('picking a library image replaces through the action', function () {
    activateUsage();
    Gate::define('replace-media', fn () => true);
    $old = Media::factory()->create(['name' => 'old']);
    $new = Media::factory()->create(['name' => 'fresh']);
    $value = placement($old);

    Livewire::test(MediaLibrary::class)
        ->callAction('replaceMedia', ['library_media_id' => $new->id], ['media' => $old->id])
        ->assertHasNoActionErrors();

    expect($value->fresh()->media_id)->toBe($new->id)
        ->and(Media::find($old->id))->toBeNull();
});

it('uploading a file for an image creates the media in the old folder with the old alt', function () {
    Storage::fake('public');
    config(['filament-media-manager.disk' => 'public']);
    activateUsage();
    Gate::define('replace-media', fn () => true);
    $folder = MediaFolder::create(['name' => 'Press']);
    $old = Media::factory()->create(['folder_id' => $folder->id, 'alt_text' => 'A cat']);
    $value = placement($old);

    Livewire::test(MediaLibrary::class)
        ->callAction('replaceMedia', ['file' => UploadedFile::fake()->image('dog.jpg')], ['media' => $old->id])
        ->assertHasNoActionErrors();

    $new = Media::query()->where('file_name', 'dog.jpg')->first();
    expect($new)->not->toBeNull()
        ->and($new->folder_id)->toBe($folder->id)
        ->and($new->alt_text)->toBe('A cat')
        ->and($value->fresh()->media_id)->toBe($new->id);
});

it('offers replace from the details slide-over only with the ability', function () {
    activateUsage();
    $media = Media::factory()->create();

    $footer = fn () => collect(Livewire::test(MediaLibrary::class)->mountAction('editMedia', arguments: ['media' => $media->id])->instance()->getMountedAction()->getModalFooterActions())->map->getName()->all();

    expect($footer())->not->toContain('replaceMedia');

    Gate::define('replace-media', fn () => true);
    expect($footer())->toContain('replaceMedia');
});

it('the details footer button replaces the media', function () {
    activateUsage();
    Gate::define('replace-media', fn () => true);
    $old = Media::factory()->create();
    $new = Media::factory()->create();
    $value = placement($old);

    Livewire::test(MediaLibrary::class)
        ->callAction(
            [TestAction::make('editMedia')->arguments(['media' => $old->id]), TestAction::make('replaceMedia')],
            ['library_media_id' => $new->id],
        )
        ->assertHasNoActionErrors();

    expect($value->fresh()->media_id)->toBe($new->id);
});

it('a document replace inside a rolled-back transaction keeps the old file', function () {
    Storage::fake('public');
    $path = UploadedFile::fake()->create('old.pdf', 10, 'application/pdf')->storeAs('media/01DOC', 'old.pdf', ['disk' => 'public']);
    $doc = Media::factory()->create(['file_name' => 'old.pdf', 'path' => $path, 'mime_type' => 'application/pdf', 'type' => MediaType::Document]);

    DB::beginTransaction();
    app(ReplaceMedia::class)->document($doc, UploadedFile::fake()->create('new.pdf', 30, 'application/pdf'));
    DB::rollBack();

    Storage::disk('public')->assertExists($path);
    expect($doc->fresh()->path)->toBe($path);
});

it('a failed library upload replace removes the media it created', function () {
    Storage::fake('public');
    config(['filament-media-manager.disk' => 'public']);
    activateUsage();
    Gate::define('replace-media', fn () => true);
    $old = Media::factory()->create();
    app()->bind(ReplaceMedia::class, fn () => new class extends ReplaceMedia
    {
        public function image(Media $old, Media $new): int
        {
            throw new RuntimeException('boom');
        }
    });

    try {
        Livewire::test(MediaLibrary::class)
            ->callAction('replaceMedia', ['file' => UploadedFile::fake()->image('dog.jpg')], ['media' => $old->id]);
    } catch (RuntimeException) {
    }

    expect(Media::query()->where('file_name', 'dog.jpg')->exists())->toBeFalse();
});

it('a failure after the replace committed leaves the new media and its moved rows intact', function () {
    Storage::fake('public');
    config(['filament-media-manager.disk' => 'public']);
    activateUsage();
    Gate::define('replace-media', fn () => true);
    $old = Media::factory()->create();
    $value = placement($old);
    useMedia($old, UsageSource::History, revisionId: 3);

    Media::deleting(function (Media $media) use ($old) {
        if ($media->id === $old->id) {
            throw new RuntimeException('delete failed');
        }
    });

    try {
        Livewire::test(MediaLibrary::class)
            ->callAction('replaceMedia', ['file' => UploadedFile::fake()->image('dog.jpg')], ['media' => $old->id]);
    } catch (RuntimeException) {
    }

    $new = Media::query()->where('file_name', 'dog.jpg')->first();
    expect($new)->not->toBeNull()
        ->and($value->fresh()->media_id)->toBe($new->id)
        ->and(MediaUsageRow::query()->where('media_id', $new->id)->count())->toBe(1);
});

function storedDocument(string $name = 'old.pdf', string $mime = 'application/pdf'): Media
{
    $path = UploadedFile::fake()->create($name, 10, $mime)->storeAs('media/01DOC', $name, ['disk' => 'public']);

    return Media::factory()->create([
        'name' => 'Brochure', 'file_name' => $name, 'path' => $path, 'mime_type' => $mime,
        'type' => MediaType::Document, 'size' => 10 * 1024, 'disk' => 'public',
    ]);
}

it('the document action replaces a pdf with a pdf in place', function () {
    Storage::fake('public');
    activateUsage();
    Gate::define('replace-media', fn () => true);
    $doc = storedDocument();
    $oldPath = $doc->path;

    Livewire::test(MediaLibrary::class)
        ->callAction('replaceMedia', ['file' => UploadedFile::fake()->create('new.pdf', 30, 'application/pdf')], ['media' => $doc->id])
        ->assertHasNoActionErrors()
        ->assertNotified(__('filament-media-manager::media.usage.replaced_document'));

    $fresh = $doc->fresh();
    expect(Media::query()->count())->toBe(1)
        ->and($fresh->id)->toBe($doc->id)
        ->and($fresh->file_name)->toBe('new.pdf')
        ->and($fresh->path)->not->toBe($oldPath);
    Storage::disk('public')->assertMissing($oldPath);
    Storage::disk('public')->assertExists($fresh->path);
});

it('document replace refuses a mime outside the configured document list', function () {
    Storage::fake('public');
    config(['filament-media-manager.usage.document_mime_types' => ['application/pdf']]);
    $doc = storedDocument();

    expect(fn () => app(ReplaceMedia::class)->document($doc, UploadedFile::fake()->create('notes.txt', 5, 'text/plain')))
        ->toThrow(InvalidArgumentException::class, __('filament-media-manager::media.usage.replace_document_type', ['types' => 'application/pdf']));
});

it('document replace accepts any non-image file without a configured list', function () {
    Storage::fake('public');
    $doc = storedDocument();

    $result = app(ReplaceMedia::class)->document($doc, UploadedFile::fake()->create('notes.txt', 5, 'text/plain'));

    expect($result->mime_type)->toBe('text/plain');
});

it('the document action refuses a disallowed mime with a form error and keeps the old file', function () {
    Storage::fake('public');
    config(['filament-media-manager.usage.document_mime_types' => ['application/pdf']]);
    activateUsage();
    Gate::define('replace-media', fn () => true);
    $doc = storedDocument();
    $oldPath = $doc->path;

    Livewire::test(MediaLibrary::class)
        ->callAction('replaceMedia', ['file' => UploadedFile::fake()->create('notes.txt', 5, 'text/plain')], ['media' => $doc->id])
        ->assertHasActionErrors(['file']);

    expect($doc->fresh()->path)->toBe($oldPath)->and($doc->fresh()->file_name)->toBe('old.pdf');
    Storage::disk('public')->assertExists($oldPath);
});

it('the document action turns a refused replace into a danger notification', function () {
    Storage::fake('public');
    activateUsage();
    Gate::define('replace-media', fn () => true);
    $doc = storedDocument();
    $oldPath = $doc->path;

    Livewire::test(MediaLibrary::class)
        ->callAction('replaceMedia', ['file' => UploadedFile::fake()->image('photo.jpg')], ['media' => $doc->id])
        ->assertNotified(__('filament-media-manager::media.usage.replace_refused'));

    expect($doc->fresh()->path)->toBe($oldPath);
});

it('picking the media being replaced is a form error', function () {
    activateUsage();
    Gate::define('replace-media', fn () => true);
    $old = Media::factory()->create();
    $value = placement($old);

    Livewire::test(MediaLibrary::class)
        ->callAction('replaceMedia', ['library_media_id' => $old->id], ['media' => $old->id])
        ->assertHasActionErrors(['library_media_id']);

    expect($value->fresh()->media_id)->toBe($old->id)->and(Media::find($old->id))->not->toBeNull();
});

it('giving both a file and a pick is a form error', function () {
    Storage::fake('public');
    config(['filament-media-manager.disk' => 'public']);
    activateUsage();
    Gate::define('replace-media', fn () => true);
    $old = Media::factory()->create();
    $new = Media::factory()->create();
    $value = placement($old);

    Livewire::test(MediaLibrary::class)
        ->callAction('replaceMedia', ['file' => UploadedFile::fake()->image('dog.jpg'), 'library_media_id' => $new->id], ['media' => $old->id])
        ->assertHasActionErrors(['library_media_id']);

    expect($value->fresh()->media_id)->toBe($old->id)
        ->and(Media::query()->where('file_name', 'dog.jpg')->exists())->toBeFalse();
});

it('a failed delete of the old media after the commit still reports success', function () {
    activateUsage();
    Gate::define('replace-media', fn () => true);
    Exceptions::fake();
    $old = Media::factory()->create();
    $new = Media::factory()->create();
    $value = placement($old);

    Media::deleting(function (Media $media) use ($old) {
        if ($media->id === $old->id) {
            throw new RuntimeException('delete failed');
        }
    });

    Livewire::test(MediaLibrary::class)
        ->callAction('replaceMedia', ['library_media_id' => $new->id], ['media' => $old->id])
        ->assertHasNoActionErrors()
        ->assertNotified(trans_choice('filament-media-manager::media.usage.replaced', 1, ['count' => 1]));

    expect($value->fresh()->media_id)->toBe($new->id);
    Exceptions::assertReported(fn (RuntimeException $e) => $e->getMessage() === 'delete failed');
});

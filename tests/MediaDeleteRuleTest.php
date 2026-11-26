<?php

use Filament\Notifications\Notification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use MarvelousDigital\FilamentMediaManager\Models\Media;
use MarvelousDigital\FilamentMediaManager\Pages\MediaLibrary;
use MarvelousDigital\FilamentMediaManager\Usage\DescribesMediaOwners;
use MarvelousDigital\FilamentMediaManager\Usage\FallbackOwnerDescriber;
use MarvelousDigital\FilamentMediaManager\Usage\MediaInUse;
use MarvelousDigital\FilamentMediaManager\Usage\MediaUsage;
use MarvelousDigital\FilamentMediaManager\Usage\OwnerDescription;
use MarvelousDigital\FilamentMediaManager\Usage\Usage;
use MarvelousDigital\FilamentMediaManager\Usage\UsageSource;

beforeEach(function () {
    Storage::fake('public');
    $this->actingAsUser();
    MediaUsage::resetTracking();
    $this->usage = app(MediaUsage::class);
});

afterEach(fn () => MediaUsage::resetTracking());

function activateUsage(): void
{
    MediaUsage::tracked();
    app(MediaUsage::class)->markIndexed();
}

function useMedia(Media $media, UsageSource $source, string $ownerType = 'App\Models\Page', int $ownerId = 7, ?int $revisionId = null): void
{
    DB::table(config('filament-media-manager.table_names.usages', 'media_manager_usages'))->insert([
        'media_id' => $media->id,
        'field_value_id' => null,
        'owner_type' => $ownerType,
        'owner_id' => (string) $ownerId,
        'source' => $source->value,
        'revision_id' => $revisionId,
        'locale' => null,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

it('deleting a media used live throws MediaInUse when usage is active', function () {
    activateUsage();
    $media = Media::factory()->create();
    useMedia($media, UsageSource::Live);

    expect(fn () => $media->delete())->toThrow(MediaInUse::class);
    expect(Media::query()->whereKey($media->id)->exists())->toBeTrue();
});

it('deleting a media used live is allowed when usage is inactive', function () {
    $media = Media::factory()->create();
    useMedia($media, UsageSource::Live);

    $media->delete();

    expect(Media::query()->whereKey($media->id)->exists())->toBeFalse();
});

it('deleting a media used only in history is allowed', function () {
    activateUsage();
    $media = Media::factory()->create();
    useMedia($media, UsageSource::History, revisionId: 3);

    $media->delete();

    expect(Media::query()->whereKey($media->id)->exists())->toBeFalse();
});

it('the delete action refuses a used media and names its owners', function () {
    activateUsage();
    $media = Media::factory()->create();
    useMedia($media, UsageSource::Draft);

    Livewire::test(MediaLibrary::class)
        ->callAction('deleteMedia', arguments: ['media' => $media->id])
        ->assertNotified(
            Notification::make()
                ->danger()
                ->title(__('filament-media-manager::media.usage.in_use_title'))
                ->body(__('filament-media-manager::media.usage.in_use_body', ['owners' => 'Page #7', 'more' => '']))
        );

    expect(Media::query()->whereKey($media->id)->exists())->toBeTrue();
});

it('lists owner titles through the bound describer', function () {
    activateUsage();
    $media = Media::factory()->create();
    useMedia($media, UsageSource::Live, ownerId: 1);
    useMedia($media, UsageSource::Draft, ownerId: 1);
    useMedia($media, UsageSource::Live, ownerId: 2);

    $page = Livewire::test(MediaLibrary::class)->instance();

    expect($page->ownersBlockingDelete($media->id))->toBe(['Page #1', 'Page #2'])
        ->and($page->ownersBlockingDelete($media->id, 1))->toBe(['Page #1']);

    app()->bind(DescribesMediaOwners::class, fn () => new class extends FallbackOwnerDescriber
    {
        public function describe(Usage $usage): OwnerDescription
        {
            return new OwnerDescription('Custom ' . $usage->ownerId, null);
        }
    });

    expect($page->ownersBlockingDelete($media->id))->toBe(['Custom 1', 'Custom 2']);
});

it('the delete action warns about history-only usage', function () {
    activateUsage();
    $media = Media::factory()->create();
    useMedia($media, UsageSource::History, revisionId: 3);
    useMedia($media, UsageSource::History, ownerId: 8, revisionId: 4);

    Livewire::test(MediaLibrary::class)
        ->mountAction('deleteMedia', arguments: ['media' => $media->id])
        ->assertActionMounted('deleteMedia')
        ->tap(fn ($t) => expect((string) $t->instance()->getMountedAction()->getModalDescription())
            ->toBe(__('filament-media-manager::media.usage.history_only_warning', ['count' => 2])))
        ->callMountedAction()
        ->assertNotNotified();

    expect(Media::query()->whereKey($media->id)->exists())->toBeFalse();
});

it('bulk delete deletes the unused and reports the used', function () {
    activateUsage();
    $free = Media::factory()->create();
    $used = Media::factory()->create(['name' => 'Used one']);
    useMedia($used, UsageSource::Live);

    Livewire::test(MediaLibrary::class)
        ->set('selected', [$free->id, $used->id])
        ->callAction('bulkDelete')
        ->assertNotified(
            Notification::make()
                ->warning()
                ->title(__('filament-media-manager::media.usage.bulk_skipped', ['count' => 1]))
                ->body('Used one')
        );

    expect(Media::query()->pluck('id')->all())->toBe([$used->id]);
});

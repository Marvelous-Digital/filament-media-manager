<?php

use Filament\Schemas\Components\View;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use MarvelousDigital\FilamentMediaManager\Livewire\MediaPickerBrowser;
use MarvelousDigital\FilamentMediaManager\Models\Media;
use MarvelousDigital\FilamentMediaManager\Pages\MediaLibrary;
use MarvelousDigital\FilamentMediaManager\Usage\DescribesMediaOwners;
use MarvelousDigital\FilamentMediaManager\Usage\FallbackOwnerDescriber;
use MarvelousDigital\FilamentMediaManager\Usage\MediaUsage;
use MarvelousDigital\FilamentMediaManager\Usage\OwnerDescription;
use MarvelousDigital\FilamentMediaManager\Usage\Usage;
use MarvelousDigital\FilamentMediaManager\Usage\UsageSource;

beforeEach(function () {
    $this->actingAsUser();
    MediaUsage::resetTracking();
});

afterEach(fn () => MediaUsage::resetTracking());

function bindDescriber(?array $children = null): void
{
    app()->bind(DescribesMediaOwners::class, fn () => new class($children) extends FallbackOwnerDescriber
    {
        public function __construct(private ?array $children) {}

        public function describe(Usage $usage): OwnerDescription
        {
            return new OwnerDescription(
                'Title ' . $usage->ownerId,
                (int) $usage->ownerId === 1 ? '/admin/pages/1' : null,
                children: $this->children === null ? [] : array_map(fn ($t) => new OwnerDescription($t, null), $this->children),
            );
        }
    });
}

function usagesView($schema): View
{
    return collect($schema->getComponents(withHidden: true))
        ->first(fn ($component) => $component instanceof View && str_contains((string) (fn () => $this->view)->call($component), 'media-usages'));
}

/** The rendered HTML of the details schema of the media's editMedia action. */
function detailsHtml(Media $media): string
{
    $page = Livewire::test(MediaLibrary::class)->mountAction('editMedia', arguments: ['media' => $media->id]);
    $schema = (fn () => $this->getMountedActionSchema())->call($page->instance());

    $view = collect($schema->getComponents(withHidden: true))
        ->first(fn ($component) => $component instanceof View && str_contains((string) (fn () => $this->view)->call($component), 'media-usages'));

    return $view?->isVisible() ? $view->toHtml() : '';
}

it('details list live draft and history groups', function () {
    activateUsage();
    bindDescriber();
    $media = Media::factory()->create();
    useMedia($media, UsageSource::Live, ownerId: 1, locale: 'fr');
    useMedia($media, UsageSource::Live, ownerId: 1, locale: 'en');
    useMedia($media, UsageSource::Draft, ownerId: 2);
    useMedia($media, UsageSource::History, ownerId: 3, revisionId: 9);

    $html = detailsHtml($media);

    expect($html)->toContain('Live', 'Draft', 'Earlier versions', 'Kept in the page history.', 'Title 1', 'Title 2', 'Title 3', 'href="/admin/pages/1"', 'target="_blank"')
        ->and($html)->toMatch('/>\s*FR\s*</')->toMatch('/>\s*EN\s*</');
});

it('usageGroups groups by source then owner and revision', function () {
    activateUsage();
    $media = Media::factory()->create();
    useMedia($media, UsageSource::History, ownerId: 3, revisionId: 9);
    useMedia($media, UsageSource::Live, ownerId: 1, locale: 'fr');
    useMedia($media, UsageSource::Live, ownerId: 1, locale: 'en');
    useMedia($media, UsageSource::Live, ownerId: 1);
    useMedia($media, UsageSource::Live, ownerId: 2);

    $groups = Livewire::test(MediaLibrary::class)->instance()->usageGroups($media->id);

    expect(array_keys($groups))->toBe(['live', 'draft', 'history'])
        ->and($groups['live'])->toHaveCount(2)
        ->and($groups['live'][0]['locales'])->toBe(['fr', 'en'])
        ->and($groups['live'][1]['locales'])->toBe([])
        ->and($groups['draft'])->toBe([])
        ->and($groups['history'])->toHaveCount(1)
        ->and($groups['history'][0]['owner']->title)->toBe('Page #3');
});

it('a global record lists the pages showing it', function () {
    activateUsage();
    bindDescriber(['About page', 'Contact page']);
    $media = Media::factory()->create();
    useMedia($media, UsageSource::Live, ownerId: 5);

    expect(detailsHtml($media))->toContain('Title 5', 'About page', 'Contact page');
});

it('details say not used anywhere when there are no rows', function () {
    activateUsage();
    $media = Media::factory()->create();

    expect(detailsHtml($media))->toContain('Not used anywhere');
});

it('the grid marks unused media', function () {
    activateUsage();
    $used = Media::factory()->create();
    Media::factory()->create();
    useMedia($used, UsageSource::Live);

    $html = Livewire::test(MediaLibrary::class)->html();

    expect(substr_count($html, 'data-unused'))->toBe(1);
});

it('nothing is shown when usage is inactive', function () {
    $media = Media::factory()->create();

    Livewire::test(MediaLibrary::class)->assertDontSeeHtml('data-unused');

    $page = Livewire::test(MediaLibrary::class)->mountAction('editMedia', arguments: ['media' => $media->id]);
    $view = usagesView((fn () => $this->getMountedActionSchema())->call($page->instance()));

    expect($view)->not->toBeNull()->and($view->isVisible())->toBeFalse()
        ->and(detailsHtml($media))->toBe('');
});

it('plural of the shown-on count', function () {
    activateUsage();
    bindDescriber(['Only page']);
    $media = Media::factory()->create();
    useMedia($media, UsageSource::Live, ownerId: 5);

    expect(detailsHtml($media))->toContain('Shown on 1 page')->not->toContain('1 pages');

    bindDescriber(['A', 'B']);

    expect(detailsHtml($media))->toContain('Shown on 2 pages');
});

it('the picker renders no badge and runs no usage query', function () {
    activateUsage();
    Media::factory()->create(['type' => 'image']);

    DB::flushQueryLog();
    DB::enableQueryLog();
    $html = Livewire::test(MediaPickerBrowser::class, ['statePath' => 'data.hero', 'target' => 'main'])->html();
    $usageQueries = collect(DB::getQueryLog())->filter(fn ($q) => str_contains($q['query'], 'media_manager_usages'));

    expect($html)->not->toContain('data-unused')->and($usageQueries)->toHaveCount(0);
});

/** A describer recording every usage it is asked about, with a revision detail. */
function recordingDescriber(): ArrayObject
{
    $calls = new ArrayObject;
    app()->bind(DescribesMediaOwners::class, fn () => new class($calls) extends FallbackOwnerDescriber
    {
        public function __construct(private ArrayObject $calls) {}

        public function describe(Usage $usage): OwnerDescription
        {
            $this->calls[] = $usage;

            return new OwnerDescription('Title ' . $usage->ownerId, null, $usage->revisionId === null ? null : 'Revision ' . $usage->revisionId);
        }
    });

    return $calls;
}

it('collapses the history of an owner into one entry counting its earlier versions', function () {
    activateUsage();
    $calls = recordingDescriber();
    $media = Media::factory()->create();
    useMedia($media, UsageSource::History, ownerId: 3, revisionId: 7);
    useMedia($media, UsageSource::History, ownerId: 3, revisionId: 8);
    useMedia($media, UsageSource::History, ownerId: 3, revisionId: 9);
    useMedia($media, UsageSource::History, ownerId: 4, revisionId: 10);

    $groups = Livewire::test(MediaLibrary::class)->instance()->usageGroups($media->id);

    expect($groups['history'])->toHaveCount(2)
        ->and($groups['history'][0]['owner']->title)->toBe('Title 3')
        ->and($groups['history'][0]['owner']->detail)->toBe(trans_choice('filament-media-manager::media.usage.earlier_versions', 3, ['count' => 3]))
        ->and($groups['history'][0]['owner']->detail)->toBe('3 earlier versions')
        ->and($groups['history'][1]['owner']->detail)->toBe('Revision 10')
        ->and($calls)->toHaveCount(2);
});

it('has en and fr strings for the earlier versions', function () {
    expect(trans_choice('filament-media-manager::media.usage.earlier_versions', 1, ['count' => 1], 'en'))->toBe('1 earlier version')
        ->and(trans_choice('filament-media-manager::media.usage.earlier_versions', 4, ['count' => 4], 'fr'))->toBe('4 versions antérieures')
        ->and(trans_choice('filament-media-manager::media.usage.earlier_versions', 1, ['count' => 1], 'fr'))->toBe('1 version antérieure');
});

it('describes a group with its locale only when it spans exactly one locale', function () {
    activateUsage();
    $calls = recordingDescriber();
    $media = Media::factory()->create();
    useMedia($media, UsageSource::Live, ownerId: 1, locale: 'fr');
    useMedia($media, UsageSource::Live, ownerId: 1, locale: 'en');
    useMedia($media, UsageSource::Live, ownerId: 2);
    useMedia($media, UsageSource::Live, ownerId: 2, locale: 'de');

    Livewire::test(MediaLibrary::class)->instance()->usageGroups($media->id);

    $locales = collect($calls)->mapWithKeys(fn (Usage $usage) => [$usage->ownerId => $usage->locale])->all();
    expect($locales)->toBe(['1' => null, '2' => 'de']);
});

it('says a media used only in earlier versions can be deleted', function () {
    activateUsage();
    bindDescriber();
    $media = Media::factory()->create();
    useMedia($media, UsageSource::History, ownerId: 3, revisionId: 9);

    expect(detailsHtml($media))->toContain('Earlier versions', 'Can be deleted')->not->toContain('Kept in the page history.');
});

it('shows five entries per group and hides the rest behind show more', function () {
    activateUsage();
    bindDescriber();
    $media = Media::factory()->create();
    foreach (range(1, 7) as $owner) {
        useMedia($media, UsageSource::Live, ownerId: $owner);
    }

    $html = detailsHtml($media);

    expect(substr_count($html, 'x-show="all"'))->toBe(3) // 2 hidden rows + the "Show less" label
        ->and($html)->toContain('Show 2 more', 'Live · 7');
});

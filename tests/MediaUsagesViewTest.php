<?php

use Filament\Schemas\Components\View;
use Livewire\Livewire;
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

/** The rendered HTML of the details schema of the media's editMedia action. */
function detailsHtml(Media $media): string
{
    $page = Livewire::test(MediaLibrary::class)->mountAction('editMedia', arguments: ['media' => $media->id]);
    $schema = (fn () => $this->getMountedActionSchema())->call($page->instance());

    $view = collect($schema->getComponents())
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

    expect($html)->toContain('Live', 'Draft', 'Only in history', 'Title 1', 'Title 2', 'Title 3', 'FR, EN', 'href="/admin/pages/1"', 'target="_blank"');
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

    expect(detailsHtml($media))->not->toContain('Not used anywhere')->not->toContain('Used in');
});

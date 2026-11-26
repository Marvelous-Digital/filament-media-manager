<?php

use MarvelousDigital\FilamentMediaManager\Models\Media;
use MarvelousDigital\FilamentMediaManager\Models\MediaConversion;
use MarvelousDigital\FilamentMediaManager\Models\MediaFieldValue;
use MarvelousDigital\FilamentMediaManager\Models\MediaUsageRow;
use MarvelousDigital\FilamentMediaManager\Usage\MediaUsage;
use MarvelousDigital\FilamentMediaManager\Usage\UsageSource;

beforeEach(function () {
    MediaUsage::resetTracking();
    $this->usage = app(MediaUsage::class);
});

function placementWithOverride(): array
{
    $a = Media::factory()->create();
    $b = Media::factory()->create();
    $value = MediaFieldValue::factory()->for($a, 'source')->create();
    MediaConversion::factory()->for($value, 'value')->create(['variation' => 'hero', 'media_id' => $b->id]);
    MediaConversion::factory()->for($value, 'value')->create(['variation' => 'thumb', 'media_id' => null]);

    return [$a, $b, $value];
}

it('sync writes one row per media of a placement', function () {
    [$a, $b, $value] = placementWithOverride();

    $this->usage->sync('App\Page', 1, UsageSource::Live, null, [['field_value_id' => $value->id, 'locale' => 'fr']]);

    foreach ([$a, $b] as $media) {
        $usages = $this->usage->usagesOf($media->id);
        expect($usages)->toHaveCount(1)
            ->and($usages->first()->fieldValueId)->toBe($value->id)
            ->and($usages->first()->ownerType)->toBe('App\Page')
            ->and((string) $usages->first()->ownerId)->toBe('1')
            ->and($usages->first()->source)->toBe(UsageSource::Live)
            ->and($usages->first()->locale)->toBe('fr');
    }
});

it('a conversion override counts as a usage of its media', function () {
    [, $b, $value] = placementWithOverride();

    $this->usage->sync('App\Page', 1, UsageSource::Live, null, [['field_value_id' => $value->id]]);

    expect($this->usage->blocksDelete($b->id))->toBeTrue();
});

it('a document reference is stored without a field value', function () {
    $doc = Media::factory()->create();

    $this->usage->sync('App\Page', 1, UsageSource::Live, null, [['media_id' => $doc->id]]);

    $usages = $this->usage->usagesOf($doc->id);
    expect($usages)->toHaveCount(1)->and($usages->first()->fieldValueId)->toBeNull();
});

it('sync replaces only that owner source and revision', function () {
    $value = MediaFieldValue::factory()->create();
    $mediaId = $value->media_id;
    $ref = [['field_value_id' => $value->id]];

    $this->usage->sync('App\Page', 1, UsageSource::Live, null, $ref);
    $this->usage->sync('App\Page', 2, UsageSource::Live, null, $ref);
    $this->usage->sync('App\Page', 1, UsageSource::Draft, 7, $ref);

    $this->usage->sync('App\Page', 1, UsageSource::Live, null, []);

    $usages = $this->usage->usagesOf($mediaId);
    expect($usages)->toHaveCount(2)
        ->and($usages->contains(fn ($u) => (string) $u->ownerId === '2' && $u->source === UsageSource::Live))->toBeTrue()
        ->and($usages->contains(fn ($u) => (string) $u->ownerId === '1' && $u->source === UsageSource::Draft && $u->revisionId === 7))->toBeTrue();
});

it('sync is idempotent', function () {
    $value = MediaFieldValue::factory()->create();
    $ref = [['field_value_id' => $value->id]];

    $this->usage->sync('App\Page', 1, UsageSource::Live, null, $ref);
    $this->usage->sync('App\Page', 1, UsageSource::Live, null, $ref);

    expect($this->usage->usagesOf($value->media_id))->toHaveCount(1);
});

it('forget without source drops every row of the owner', function () {
    $value = MediaFieldValue::factory()->create();
    $ref = [['field_value_id' => $value->id]];
    $this->usage->sync('App\Page', 1, UsageSource::Live, null, $ref);
    $this->usage->sync('App\Page', 1, UsageSource::Draft, 3, $ref);
    $this->usage->sync('App\Page', 1, UsageSource::History, 4, $ref);
    $this->usage->sync('App\Page', 2, UsageSource::Live, null, $ref);

    $this->usage->forget('App\Page', 1);

    $usages = $this->usage->usagesOf($value->media_id);
    expect($usages)->toHaveCount(1)->and((string) $usages->first()->ownerId)->toBe('2');
});

it('forget with a source keeps the other sources', function () {
    $value = MediaFieldValue::factory()->create();
    $ref = [['field_value_id' => $value->id]];
    $this->usage->sync('App\Page', 1, UsageSource::Live, null, $ref);
    $this->usage->sync('App\Page', 1, UsageSource::Draft, 3, $ref);

    $this->usage->forget('App\Page', 1, UsageSource::Draft);

    $usages = $this->usage->usagesOf($value->media_id);
    expect($usages)->toHaveCount(1)->and($usages->first()->source)->toBe(UsageSource::Live);
});

it('forgetRevision drops only that revision', function () {
    $value = MediaFieldValue::factory()->create();
    $ref = [['field_value_id' => $value->id]];
    $this->usage->sync('App\Page', 1, UsageSource::History, 4, $ref);
    $this->usage->sync('App\Page', 1, UsageSource::History, 5, $ref);

    $this->usage->forgetRevision(4);

    $usages = $this->usage->usagesOf($value->media_id);
    expect($usages)->toHaveCount(1)->and($usages->first()->revisionId)->toBe(5);
});

it('blocksDelete is false when only history rows exist and isUsed is true', function () {
    $value = MediaFieldValue::factory()->create();
    $this->usage->sync('App\Page', 1, UsageSource::History, 4, [['field_value_id' => $value->id]]);

    expect($this->usage->blocksDelete($value->media_id))->toBeFalse()
        ->and($this->usage->isUsed($value->media_id))->toBeTrue();
});

it('blocksDelete is true for a draft row', function () {
    $value = MediaFieldValue::factory()->create();
    $this->usage->sync('App\Page', 1, UsageSource::Draft, 4, [['field_value_id' => $value->id]]);

    expect($this->usage->blocksDelete($value->media_id))->toBeTrue();
});

it('unknown field value ids are skipped', function () {
    $this->usage->sync('App\Page', 1, UsageSource::Live, null, [['field_value_id' => 99999], ['media_id' => 99999], []]);

    expect(MediaUsageRow::count())->toBe(0);
});

it('isActive needs tracked and indexed', function () {
    expect($this->usage->isActive())->toBeFalse();

    MediaUsage::tracked();
    expect($this->usage->isActive())->toBeFalse();

    $this->usage->markIndexed();
    expect($this->usage->isActive())->toBeTrue();
});

it('unusedAmong returns ids without rows', function () {
    $used = MediaFieldValue::factory()->create();
    $free = Media::factory()->create();
    $this->usage->sync('App\Page', 1, UsageSource::Live, null, [['field_value_id' => $used->id]]);

    expect($this->usage->unusedAmong([$used->media_id, $free->id]))->toBe([$free->id])
        ->and($this->usage->unusedAmong([]))->toBe([]);
});

it('sync revision matching is exact', function () {
    $value = MediaFieldValue::factory()->create();
    $ref = [['field_value_id' => $value->id]];
    $this->usage->sync('App\Page', 1, UsageSource::Draft, 7, $ref);
    $this->usage->sync('App\Page', 1, UsageSource::Draft, 8, $ref);
    $this->usage->sync('App\Page', 1, UsageSource::Draft, null, $ref);

    $this->usage->sync('App\Page', 1, UsageSource::Draft, 7, []);

    expect(MediaUsageRow::pluck('revision_id')->all())->toEqualCanonicalizing([8, null]);

    $this->usage->sync('App\Page', 1, UsageSource::Draft, null, []);

    expect(MediaUsageRow::pluck('revision_id')->all())->toBe([8]);
});

it('sync dedupes the same placement and locale', function () {
    $value = MediaFieldValue::factory()->create();

    $this->usage->sync('App\Page', 1, UsageSource::Live, null, [
        ['field_value_id' => $value->id, 'locale' => 'fr'],
        ['field_value_id' => $value->id, 'locale' => 'fr'],
        ['field_value_id' => $value->id, 'locale' => 'en'],
    ]);

    expect(MediaUsageRow::count())->toBe(2);
});

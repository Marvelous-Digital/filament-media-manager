<?php

use Illuminate\Support\Facades\DB;
use MarvelousDigital\FilamentMediaManager\Models\Media;
use MarvelousDigital\FilamentMediaManager\Tests\TestCase;
use MarvelousDigital\FilamentMediaManager\Usage\MediaUsage;
use MarvelousDigital\FilamentMediaManager\Usage\UsageSource;

uses(TestCase::class)->in(__DIR__);

function activateUsage(): void
{
    MediaUsage::tracked();
    app(MediaUsage::class)->markIndexed();
}

function useMedia(Media $media, UsageSource $source, string $ownerType = 'App\Models\Page', int $ownerId = 7, ?int $revisionId = null, ?string $locale = null): void
{
    DB::table(config('filament-media-manager.table_names.usages', 'media_manager_usages'))->insert([
        'media_id' => $media->id,
        'field_value_id' => null,
        'owner_type' => $ownerType,
        'owner_id' => (string) $ownerId,
        'source' => $source->value,
        'revision_id' => $revisionId,
        'locale' => $locale,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

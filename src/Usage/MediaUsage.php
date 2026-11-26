<?php

namespace MarvelousDigital\FilamentMediaManager\Usage;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use MarvelousDigital\FilamentMediaManager\Models\MediaConversion;
use MarvelousDigital\FilamentMediaManager\Models\MediaFieldValue;
use MarvelousDigital\FilamentMediaManager\Models\MediaUsageRow;

/**
 * The usage index: where each media is used. The host application fills it (sync/forget); the
 * media library reads it. Inactive until a host declared it tracks usages and built the index once.
 */
class MediaUsage
{
    protected static bool $tracked = false;

    protected ?bool $active = null;

    /** The host declares that it keeps the index up to date. */
    public static function tracked(): void
    {
        static::$tracked = true;
    }

    /** @internal Test helper. */
    public static function resetTracking(): void
    {
        static::$tracked = false;

        if (function_exists('app') && app()->bound(self::class)) {
            app(self::class)->active = null;
        }
    }

    public function isTracked(): bool
    {
        return static::$tracked;
    }

    public function isActive(): bool
    {
        if (! static::$tracked) {
            return false;
        }

        try {
            return $this->active ??= $this->stateTable()->whereNotNull('indexed_at')->exists();
        } catch (QueryException) {
            // A host that upgraded but has not migrated yet: the usage features are simply off.
            return false;
        }
    }

    public function markIndexed(): void
    {
        $this->active = null;
        $state = $this->stateTable();

        if ($state->exists()) {
            $this->stateTable()->update(['indexed_at' => now()]);

            return;
        }

        $this->stateTable()->insert(['indexed_at' => now()]);
    }

    /**
     * Replaces every row of (owner, source, revision) with the given references.
     *
     * @param  list<array{field_value_id?: int, media_id?: int, locale?: ?string}>  $references
     */
    public function sync(string $ownerType, int | string $ownerId, UsageSource $source, ?int $revisionId, array $references): void
    {
        DB::transaction(function () use ($ownerType, $ownerId, $source, $revisionId, $references): void {
            $fieldValueIds = collect($references)->pluck('field_value_id')->filter()->unique()->all();
            $values = $fieldValueIds === []
                ? collect()
                : MediaFieldValue::query()->whereIn('id', $fieldValueIds)->get(['id', 'media_id'])->keyBy('id');
            $overrides = $fieldValueIds === []
                ? collect()
                : MediaConversion::query()
                    ->whereIn('media_field_value_id', $fieldValueIds)
                    ->whereNotNull('media_id')
                    ->get(['media_field_value_id', 'media_id'])
                    ->groupBy('media_field_value_id');

            $existingMedia = $this->existingMediaIds(
                collect($references)->pluck('media_id')->filter()->unique()->all()
            );

            $now = now();
            $rows = [];

            foreach ($references as $reference) {
                $locale = $reference['locale'] ?? null;

                if (! empty($reference['field_value_id'])) {
                    $value = $values->get($reference['field_value_id']);

                    if ($value === null) {
                        continue;
                    }

                    $mediaIds = collect([$value->media_id])
                        ->merge($overrides->get($value->id, collect())->pluck('media_id'))
                        ->unique();

                    foreach ($mediaIds as $mediaId) {
                        $rows[$mediaId . '|' . $value->id . '|' . $locale] = $this->row($mediaId, $value->id, $ownerType, $ownerId, $source, $revisionId, $locale, $now);
                    }
                } elseif (! empty($reference['media_id']) && isset($existingMedia[$reference['media_id']])) {
                    $rows[$reference['media_id'] . '||' . $locale] = $this->row($reference['media_id'], null, $ownerType, $ownerId, $source, $revisionId, $locale, $now);
                }
            }

            $this->scope($ownerType, $ownerId, $source, $revisionId, exact: true)->delete();

            foreach (array_chunk(array_values($rows), 200) as $chunk) {
                MediaUsageRow::query()->insert($chunk);
            }
        });
    }

    /** A null source drops every source and revision of the owner. */
    public function forget(string $ownerType, int | string $ownerId, ?UsageSource $source = null, ?int $revisionId = null): void
    {
        $this->scope($ownerType, $ownerId, $source, $revisionId)->delete();
    }

    public function forgetRevision(int $revisionId): void
    {
        MediaUsageRow::query()->where('revision_id', $revisionId)->delete();
    }

    /** @return Collection<int, Usage> */
    public function usagesOf(int $mediaId): Collection
    {
        return MediaUsageRow::query()
            ->where('media_id', $mediaId)
            ->orderBy('id')
            ->get()
            ->map(fn (MediaUsageRow $row) => new Usage(
                mediaId: $row->media_id,
                fieldValueId: $row->field_value_id,
                ownerType: $row->owner_type,
                ownerId: $row->owner_id,
                source: UsageSource::from($row->source),
                revisionId: $row->revision_id,
                locale: $row->locale,
            ))
            ->values();
    }

    public function isUsed(int $mediaId): bool
    {
        return MediaUsageRow::query()->where('media_id', $mediaId)->exists();
    }

    public function blocksDelete(int $mediaId): bool
    {
        return MediaUsageRow::query()
            ->where('media_id', $mediaId)
            ->whereIn('source', $this->blockingSources())
            ->exists();
    }

    /**
     * @param  array<int, int>  $mediaIds
     * @return list<int>
     */
    public function unusedAmong(array $mediaIds): array
    {
        if ($mediaIds === []) {
            return [];
        }

        $used = MediaUsageRow::query()->whereIn('media_id', $mediaIds)->pluck('media_id')->all();

        return array_values(array_diff($mediaIds, $used));
    }

    /** @return list<string> */
    protected function blockingSources(): array
    {
        return array_map(
            fn (UsageSource $source) => $source->value,
            array_filter(UsageSource::cases(), fn (UsageSource $source) => $source->blocksDelete()),
        );
    }

    /**
     * @param  array<int, int>  $ids
     * @return array<int, true>
     */
    protected function existingMediaIds(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $table = config('filament-media-manager.table_names.media', 'media_manager_media');

        return array_fill_keys(DB::table($table)->whereIn('id', $ids)->pluck('id')->map(fn ($id) => (int) $id)->all(), true);
    }

    protected function scope(string $ownerType, int | string $ownerId, ?UsageSource $source, ?int $revisionId, bool $exact = false): Builder
    {
        $query = MediaUsageRow::query()
            ->where('owner_type', $ownerType)
            ->where('owner_id', (string) $ownerId);

        if ($source !== null) {
            $query->where('source', $source->value);
        }

        if ($revisionId !== null) {
            $query->where('revision_id', $revisionId);
        } elseif ($exact) {
            $query->whereNull('revision_id');
        }

        return $query;
    }

    /** @return array<string, mixed> */
    protected function row(int $mediaId, ?int $fieldValueId, string $ownerType, int | string $ownerId, UsageSource $source, ?int $revisionId, ?string $locale, CarbonInterface $now): array
    {
        return [
            'media_id' => $mediaId,
            'field_value_id' => $fieldValueId,
            'owner_type' => $ownerType,
            'owner_id' => (string) $ownerId,
            'source' => $source->value,
            'revision_id' => $revisionId,
            'locale' => $locale,
            'created_at' => $now,
            'updated_at' => $now,
        ];
    }

    protected function stateTable(): \Illuminate\Database\Query\Builder
    {
        return DB::table(config('filament-media-manager.table_names.usage_state', 'media_manager_usage_state'));
    }
}

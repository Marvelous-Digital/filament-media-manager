<?php

namespace MarvelousDigital\FilamentMediaManager\Support;

use Illuminate\Support\Str;
use MarvelousDigital\FilamentMediaManager\Models\Media;
use MarvelousDigital\FilamentMediaManager\Models\MediaFieldValue;

/**
 * Pure transitions for the ImagesPicker's in-form state. Nothing here writes to the database.
 *
 * @phpstan-type Item array{key: string, id: int|null, media_id: int, alt: string|null}
 * @phpstan-type State array{items: list<Item>, removed: list<int>}
 */
class ImagesPickerState
{
    /**
     * @return State
     */
    public static function fromStoredValue(mixed $stored): array
    {
        if (is_array($stored) && array_key_exists('items', $stored)) {
            /** @var State $stored */
            return $stored;
        }

        if (! is_array($stored) || $stored === []) {
            return self::empty();
        }

        $ids = array_values(array_filter($stored, is_numeric(...)));
        $values = MediaFieldValue::query()->whereIn('id', $ids)->get()->keyBy('id');
        $items = [];

        foreach ($ids as $id) {
            $value = $values->get((int) $id);

            if ($value === null) {
                continue;
            }

            $items[] = ['key' => "v{$value->id}", 'id' => $value->id, 'media_id' => $value->media_id, 'alt' => $value->alt_text];
        }

        return ['items' => $items, 'removed' => []];
    }

    /**
     * @param  State  $state
     * @return State
     */
    public static function withImage(array $state, Media $media): array
    {
        $state['items'][] = ['key' => (string) Str::uuid(), 'id' => null, 'media_id' => $media->id, 'alt' => $media->alt_text];

        return $state;
    }

    /**
     * A saved image keeps its id in `removed` so the row can be deleted on save.
     *
     * @param  State  $state
     * @return State
     */
    public static function withoutImage(array $state, string $key): array
    {
        $items = [];

        foreach ($state['items'] as $item) {
            if ($item['key'] !== $key) {
                $items[] = $item;
            } elseif ($item['id'] !== null) {
                $state['removed'][] = $item['id'];
            }
        }

        $state['items'] = $items;

        return $state;
    }

    /**
     * @param  State  $state
     * @param  list<string>  $keys
     * @return State
     */
    public static function reordered(array $state, array $keys): array
    {
        $byKey = [];

        foreach ($state['items'] as $item) {
            $byKey[$item['key']] = $item;
        }

        $items = [];

        foreach ($keys as $key) {
            if (isset($byKey[$key])) {
                $items[] = $byKey[$key];
                unset($byKey[$key]);
            }
        }

        $state['items'] = [...$items, ...array_values($byKey)];

        return $state;
    }

    /**
     * @return State
     */
    private static function empty(): array
    {
        return ['items' => [], 'removed' => []];
    }
}

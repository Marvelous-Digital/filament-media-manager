<?php

namespace MarvelousDigital\FilamentMediaManager\Support;

use Illuminate\Support\Str;
use MarvelousDigital\FilamentMediaManager\Models\Media;
use MarvelousDigital\FilamentMediaManager\Models\MediaFieldValue;

/**
 * Pure transitions for the ImagesPicker's in-form state. Nothing here writes to the database.
 *
 * A saved item carries a FieldValueToken next to its id, and so does every entry in `removed`;
 * verified() drops whatever the browser could have forged before the state is persisted.
 *
 * @phpstan-type Item array{key: string, id: int|null, token: string|null, media_id: int, alt: string|null}
 * @phpstan-type Removal array{id: int, token: string|null}
 * @phpstan-type State array{items: list<Item>, removed: list<Removal>}
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

            $items[] = [
                'key' => "v{$value->id}",
                'id' => $value->id,
                'token' => FieldValueToken::for($value->id),
                'media_id' => $value->media_id,
                'alt' => $value->alt_text,
            ];
        }

        return ['items' => $items, 'removed' => []];
    }

    /**
     * @param  State  $state
     * @return State
     */
    public static function withImage(array $state, Media $media): array
    {
        $state['items'][] = ['key' => (string) Str::uuid(), 'id' => null, 'token' => null, 'media_id' => $media->id, 'alt' => $media->alt_text];

        return $state;
    }

    /**
     * A saved image keeps its id and token in `removed` so the row can be deleted on save.
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
                $state['removed'][] = ['id' => $item['id'], 'token' => $item['token'] ?? null];
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
     * The state as it may be persisted: an item whose token does not verify loses its id (it is
     * saved as a new row), a `removed` entry whose token does not verify is dropped, and malformed
     * items are skipped. A value that is not hydrated state did not come from the server (server
     * code hydrates through the field), so its rows are shown as new copies rather than trusted.
     *
     * @return State
     */
    public static function verified(mixed $state): array
    {
        if (! is_array($state) || ! array_key_exists('items', $state)) {
            $copies = self::fromStoredValue($state);

            foreach ($copies['items'] as $index => $item) {
                $copies['items'][$index] = [...$item, 'id' => null, 'token' => null];
            }

            return $copies;
        }

        $items = [];
        $keptIds = [];

        foreach (is_array($state['items']) ? $state['items'] : [] as $item) {
            if (! is_array($item) || ! self::isId($item['media_id'] ?? null)) {
                continue;
            }

            $id = self::isId($item['id'] ?? null) ? (int) $item['id'] : null;
            $token = $item['token'] ?? null;

            if ($id === null || isset($keptIds[$id]) || ! FieldValueToken::verifies($id, $token)) {
                $id = null;
                $token = null;
            }

            if ($id !== null) {
                $keptIds[$id] = true;
            }

            $key = $item['key'] ?? null;
            $alt = $item['alt'] ?? null;

            $items[] = [
                'key' => is_string($key) && $key !== '' ? $key : (string) Str::uuid(),
                'id' => $id,
                'token' => $token,
                'media_id' => (int) $item['media_id'],
                'alt' => is_scalar($alt) ? (string) $alt : null,
            ];
        }

        $removed = [];

        foreach (is_array($state['removed'] ?? null) ? $state['removed'] : [] as $entry) {
            if (! is_array($entry) || ! self::isId($entry['id'] ?? null)) {
                continue;
            }

            $id = (int) $entry['id'];

            if (! isset($keptIds[$id]) && FieldValueToken::verifies($id, $entry['token'] ?? null)) {
                $keptIds[$id] = true;
                $removed[] = ['id' => $id, 'token' => $entry['token']];
            }
        }

        return ['items' => $items, 'removed' => $removed];
    }

    private static function isId(mixed $value): bool
    {
        return (is_int($value) || (is_string($value) && ctype_digit($value))) && (int) $value > 0;
    }

    /**
     * @return State
     */
    private static function empty(): array
    {
        return ['items' => [], 'removed' => []];
    }
}

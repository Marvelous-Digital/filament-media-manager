<?php

namespace MarvelousDigital\FilamentMediaManager\Support;

use MarvelousDigital\FilamentMediaManager\Models\Media;
use MarvelousDigital\FilamentMediaManager\Models\MediaFieldValue;

/**
 * Pure transitions for the ImagePicker's in-form state. Nothing here writes to the database.
 *
 * A saved value carries a FieldValueToken next to its id; verified() drops an id the browser
 * could have forged before the state is persisted.
 *
 * @phpstan-type Variant array{media_id: int|null, alt: string|null, focus_x: float, focus_y: float, zoom: float}
 * @phpstan-type State array{id: int|null, token: string|null, media_id: int|null, alt: string|null, dirty: bool, variants: array<string, Variant>}
 */
class ImagePickerState
{
    /**
     * @return Variant
     */
    public static function defaultVariant(): array
    {
        return ['media_id' => null, 'alt' => null, 'focus_x' => 0.5, 'focus_y' => 0.5, 'zoom' => 1.0];
    }

    /**
     * @param  array<string, array{0: int, 1: int}>  $variations
     * @return State|null
     */
    public static function fromStoredValue(mixed $stored, array $variations): ?array
    {
        if (is_array($stored)) {
            return self::syncVariants($stored, $variations);
        }

        if (! is_numeric($stored)) {
            return null;
        }

        $value = MediaFieldValue::query()->with('conversions')->find((int) $stored);

        return $value ? self::fromValue($value, $variations) : null;
    }

    /**
     * @param  array<string, array{0: int, 1: int}>  $variations
     * @return State
     */
    public static function fromValue(MediaFieldValue $value, array $variations): array
    {
        $variants = [];

        foreach (array_keys($variations) as $name) {
            $conversion = $value->conversion($name);

            $variants[$name] = $conversion === null ? self::defaultVariant() : [
                'media_id' => $conversion->media_id,
                'alt' => $conversion->alt_text,
                'focus_x' => $conversion->focus_x,
                'focus_y' => $conversion->focus_y,
                'zoom' => $conversion->zoom,
            ];
        }

        return [
            'id' => $value->id,
            'token' => FieldValueToken::for($value->id),
            'media_id' => $value->media_id,
            'alt' => $value->alt_text,
            'dirty' => false,
            'variants' => $variants,
        ];
    }

    /**
     * @param  array<string, mixed>  $state
     * @param  array<string, array{0: int, 1: int}>  $variations
     * @return State
     */
    public static function syncVariants(array $state, array $variations): array
    {
        $variants = [];

        foreach (array_keys($variations) as $name) {
            $variant = $state['variants'][$name] ?? [];
            $variants[$name] = [...self::defaultVariant(), ...(is_array($variant) ? $variant : [])];
        }

        return [
            'id' => $state['id'] ?? null,
            'token' => $state['token'] ?? null,
            'media_id' => $state['media_id'] ?? null,
            'alt' => $state['alt'] ?? null,
            'dirty' => (bool) ($state['dirty'] ?? false),
            'variants' => $variants,
        ];
    }

    /**
     * @param  array<string, mixed>|null  $state
     * @param  array<string, array{0: int, 1: int}>  $variations
     * @return State
     */
    public static function withMainImage(?array $state, Media $media, array $variations): array
    {
        $state = self::syncVariants($state ?? [], $variations);

        foreach ($state['variants'] as $name => $variant) {
            if ($variant['media_id'] === null) {
                $state['variants'][$name] = self::defaultVariant();
            }
        }

        return [...$state, 'media_id' => $media->id, 'alt' => $media->alt_text, 'dirty' => true];
    }

    /**
     * @param  State  $state
     * @return State
     */
    public static function withVariantImage(array $state, string $variation, Media $media): array
    {
        if (! array_key_exists($variation, $state['variants'])) {
            return $state;
        }

        $state['variants'][$variation] = [...self::defaultVariant(), 'media_id' => $media->id, 'alt' => $media->alt_text];
        $state['dirty'] = true;

        return $state;
    }

    /**
     * @param  State  $state
     * @return State
     */
    public static function withoutVariantImage(array $state, string $variation): array
    {
        if (! array_key_exists($variation, $state['variants'])) {
            return $state;
        }

        $state['variants'][$variation] = self::defaultVariant();
        $state['dirty'] = true;

        return $state;
    }

    /**
     * An image that was never saved simply disappears; a saved one keeps its id and token so
     * the row can be deleted on save.
     *
     * @param  array<string, mixed>|null  $state
     * @return State|null
     */
    public static function withoutImage(?array $state): ?array
    {
        if (blank($state['id'] ?? null)) {
            return null;
        }

        return ['id' => (int) $state['id'], 'token' => $state['token'] ?? null, 'media_id' => null, 'alt' => null, 'dirty' => true, 'variants' => []];
    }

    /**
     * The state as it may be persisted. An id whose token does not verify is dropped, so the
     * image is saved as a new row; a removal left without a valid id becomes null (nothing to
     * do). Malformed values are coerced to their defaults. A value that is not hydrated state did
     * not come from the server (server code hydrates through the field), so it is shown as a new
     * copy rather than trusted.
     *
     * @param  array<string, array{0: int, 1: int}>  $variations
     * @return State|null
     */
    public static function verified(mixed $state, array $variations): ?array
    {
        if (! is_array($state)) {
            $copy = self::fromStoredValue($state, $variations);

            return $copy === null ? null : [...$copy, 'id' => null, 'token' => null, 'dirty' => true];
        }

        $id = self::idOrNull($state['id'] ?? null);
        $token = $state['token'] ?? null;

        if ($id === null || ! FieldValueToken::verifies($id, $token)) {
            $id = null;
            $token = null;
        }

        $mediaId = self::idOrNull($state['media_id'] ?? null);

        if ($mediaId === null && $id === null) {
            return null;
        }

        $variants = [];

        foreach (array_keys($variations) as $name) {
            $variant = is_array($state['variants'] ?? null) ? ($state['variants'][$name] ?? null) : null;
            $variant = is_array($variant) ? $variant : [];
            $alt = $variant['alt'] ?? null;

            $variants[$name] = [
                'media_id' => self::idOrNull($variant['media_id'] ?? null),
                'alt' => is_scalar($alt) ? (string) $alt : null,
                'focus_x' => is_numeric($variant['focus_x'] ?? null) ? (float) $variant['focus_x'] : 0.5,
                'focus_y' => is_numeric($variant['focus_y'] ?? null) ? (float) $variant['focus_y'] : 0.5,
                'zoom' => is_numeric($variant['zoom'] ?? null) ? (float) $variant['zoom'] : 1.0,
            ];
        }

        $alt = $state['alt'] ?? null;

        return [
            'id' => $id,
            'token' => $token,
            'media_id' => $mediaId,
            'alt' => is_scalar($alt) ? (string) $alt : null,
            'dirty' => (bool) ($state['dirty'] ?? false),
            'variants' => $mediaId === null ? [] : $variants,
        ];
    }

    private static function idOrNull(mixed $value): ?int
    {
        return (is_int($value) || (is_string($value) && ctype_digit($value))) && (int) $value > 0 ? (int) $value : null;
    }
}

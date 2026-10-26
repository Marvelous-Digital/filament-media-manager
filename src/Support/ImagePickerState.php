<?php

namespace MarvelousDigital\FilamentMediaManager\Support;

use MarvelousDigital\FilamentMediaManager\Models\Media;
use MarvelousDigital\FilamentMediaManager\Models\MediaFieldValue;

/**
 * Pure transitions for the ImagePicker's in-form state. Nothing here writes to the database.
 *
 * @phpstan-type Variant array{media_id: int|null, alt: string|null, focus_x: float, focus_y: float, zoom: float}
 * @phpstan-type State array{id: int|null, media_id: int|null, alt: string|null, dirty: bool, variants: array<string, Variant>}
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
            $variants[$name] = [...self::defaultVariant(), ...($state['variants'][$name] ?? [])];
        }

        return [
            'id' => $state['id'] ?? null,
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
     * An image that was never saved simply disappears; a saved one keeps its id so the
     * row can be deleted on save.
     *
     * @param  array<string, mixed>|null  $state
     * @return State|null
     */
    public static function withoutImage(?array $state): ?array
    {
        if (blank($state['id'] ?? null)) {
            return null;
        }

        return ['id' => (int) $state['id'], 'media_id' => null, 'alt' => null, 'dirty' => true, 'variants' => []];
    }
}

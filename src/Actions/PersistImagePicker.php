<?php

namespace MarvelousDigital\FilamentMediaManager\Actions;

use Illuminate\Support\Facades\Auth;
use MarvelousDigital\FilamentMediaManager\Enums\ConversionStatus;
use MarvelousDigital\FilamentMediaManager\Jobs\GenerateConversion;
use MarvelousDigital\FilamentMediaManager\Models\MediaConversion;
use MarvelousDigital\FilamentMediaManager\Models\MediaFieldValue;
use MarvelousDigital\FilamentMediaManager\Support\ImagePickerState;

class PersistImagePicker
{
    /** Columns whose change requires regenerating the conversion file. */
    private const SHAPING_COLUMNS = ['media_id', 'width', 'height', 'focus_x', 'focus_y', 'zoom'];

    /**
     * Write the ImagePicker's form state to the database and return the id to store on the
     * parent. Scalar state (never hydrated) passes through untouched.
     *
     * This is an internal action that trusts its caller: the row `id` names is rewritten, or
     * deleted with its conversions and files when `media_id` is empty, whoever owns it. Never
     * pass it state the browser could have written without running ImagePickerState::verified()
     * first, as the ImagePicker field does on save.
     *
     * @param  array<string, array{0: int, 1: int}>  $variations
     */
    public function execute(mixed $state, array $variations): ?int
    {
        if (! is_array($state)) {
            return is_numeric($state) ? (int) $state : null;
        }

        $existing = filled($state['id'] ?? null)
            ? MediaFieldValue::query()->with('conversions')->find((int) $state['id'])
            : null;

        if (blank($state['media_id'] ?? null)) {
            // Deleted explicitly rather than relying on the migration's cascadeOnDelete:
            // sqlite (used in tests) does not enforce foreign keys by default, so the
            // cascade silently no-ops there.
            $existing?->conversions->each->delete();
            $existing?->delete();

            return null;
        }

        $value = $existing ?? new MediaFieldValue(['created_by' => Auth::id()]);
        $mainImageChanged = $value->exists && $value->media_id !== (int) $state['media_id'];

        $value->fill([
            'media_id' => (int) $state['media_id'],
            'alt_text' => $state['alt'] ?? null,
        ])->save();

        $conversions = $value->conversions->keyBy('variation');

        foreach ($variations as $name => [$width, $height]) {
            $variant = [...ImagePickerState::defaultVariant(), ...($state['variants'][$name] ?? [])];
            $overrideId = filled($variant['media_id']) ? (int) $variant['media_id'] : null;

            /** @var MediaConversion $conversion */
            $conversion = $conversions->get($name) ?? $value->conversions()->make(['variation' => $name]);

            $conversion->fill([
                'media_id' => $overrideId,
                'alt_text' => $overrideId !== null ? ($variant['alt'] ?? null) : null,
                'width' => $width,
                'height' => $height,
                'focus_x' => $this->clamp((float) $variant['focus_x'], 0.0, 1.0),
                'focus_y' => $this->clamp((float) $variant['focus_y'], 0.0, 1.0),
                'zoom' => $this->clamp((float) $variant['zoom'], 1.0, 5.0),
            ]);

            $needsRegeneration = ! $conversion->exists
                || $conversion->isDirty(self::SHAPING_COLUMNS)
                || ($mainImageChanged && $overrideId === null);

            if ($needsRegeneration) {
                $conversion->status = ConversionStatus::Pending;
            }

            $conversion->save();

            if ($needsRegeneration) {
                GenerateConversion::dispatchFor($conversion);
            }
        }

        // Illuminate\Database\Eloquent\Collection::except() filters by primary key, not by
        // the array key set via keyBy() above, so the collection is downgraded to a base
        // Collection first to get plain array-key filtering by variation name.
        $conversions->toBase()->except(array_keys($variations))->each->delete();

        return $value->id;
    }

    private function clamp(float $value, float $min, float $max): float
    {
        return max($min, min($max, $value));
    }
}

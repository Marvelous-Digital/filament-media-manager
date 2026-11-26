<?php

namespace MarvelousDigital\FilamentMediaManager\Actions;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
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
     * Saving a copy of a stored row (copy-on-write): pass that row as $replaces, and a new
     * conversion whose crop is the same as the stored one copies its file instead of waiting for
     * it to be generated again. $generateNow generates the other new or changed conversions during
     * the save (after the transaction commits) instead of on the queue, so a page rendered right
     * after the save already has every crop.
     *
     * @param  array<string, array{0: int, 1: int}>  $variations
     */
    public function execute(mixed $state, array $variations, ?int $replaces = null, bool $generateNow = false): ?int
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
        $baseline = $existing ?? ($replaces !== null
            ? MediaFieldValue::query()->with('conversions')->find($replaces)
            : null);
        $baselineAlts = $baseline !== null && $baseline->alt_check_required ? $this->alts($baseline) : null;
        $baselineMediaId = $baseline?->media_id;
        $mainImageChanged = $value->exists && $value->media_id !== (int) $state['media_id'];

        $value->fill([
            'media_id' => (int) $state['media_id'],
            'alt_text' => $state['alt'] ?? null,
        ]);

        // State without the flag (the ImagesPicker sends none) keeps the saved one.
        if (array_key_exists('decorative', $state)) {
            $value->decorative = filter_var($state['decorative'], FILTER_VALIDATE_BOOLEAN);
        }

        // State without a base focus (the ImagesPicker sends none) keeps the saved one.
        if (array_key_exists('focus_x', $state) || array_key_exists('focus_y', $state)) {
            $value->fill([
                'focus_x' => $this->clamp((float) ($state['focus_x'] ?? 0.5), 0.0, 1.0),
                'focus_y' => $this->clamp((float) ($state['focus_y'] ?? 0.5), 0.0, 1.0),
            ]);
        }

        $value->save();
        $baseFocusX = $value->focus_x ?? 0.5;
        $baseFocusY = $value->focus_y ?? 0.5;

        $conversions = $value->conversions->keyBy('variation');
        $replaced = $replaces === null || $replaces === $value->id
            ? null
            : MediaFieldValue::query()->with('conversions')->find($replaces);

        foreach ($variations as $name => [$width, $height]) {
            $variant = [...ImagePickerState::defaultVariant(), ...($state['variants'][$name] ?? [])];
            $overrideId = filled($variant['media_id']) ? (int) $variant['media_id'] : null;
            // A variation with its own image always has its own focus: the base one belongs to another picture.
            $custom = $overrideId !== null || filter_var($variant['custom'], FILTER_VALIDATE_BOOLEAN);

            /** @var MediaConversion $conversion */
            $conversion = $conversions->get($name) ?? $value->conversions()->make(['variation' => $name]);

            $conversion->fill([
                'media_id' => $overrideId,
                'alt_text' => $overrideId !== null ? ($variant['alt'] ?? null) : null,
                'width' => $width,
                'height' => $height,
                'focus_x' => $custom ? $this->clamp((float) $variant['focus_x'], 0.0, 1.0) : $baseFocusX,
                'focus_y' => $custom ? $this->clamp((float) $variant['focus_y'], 0.0, 1.0) : $baseFocusY,
                'custom_focus' => $custom,
                'zoom' => $this->clamp((float) $variant['zoom'], 1.0, 5.0),
            ]);

            $needsRegeneration = ! $conversion->exists
                || $conversion->isDirty(self::SHAPING_COLUMNS)
                || ($mainImageChanged && $overrideId === null);

            if ($needsRegeneration) {
                $conversion->status = ConversionStatus::Pending;
            }

            $conversion->save();

            if ($needsRegeneration && $replaced !== null && $this->copyCrop($replaced, $value, $conversion)) {
                $needsRegeneration = false;
            }

            if ($needsRegeneration) {
                $generateNow
                    ? DB::afterCommit(fn () => (new GenerateConversion($conversion))->handle())
                    : GenerateConversion::dispatchFor($conversion);
            }
        }

        // Illuminate\Database\Eloquent\Collection::except() filters by primary key, not by
        // the array key set via keyBy() above, so the collection is downgraded to a base
        // Collection first to get plain array-key filtering by variation name.
        $conversions->toBase()->except(array_keys($variations))->each->delete();

        $this->carryAltCheck($value, $baselineAlts, $baselineMediaId);

        return $value->id;
    }

    /**
     * The "alt to check" flag follows the image: a row saved from a flagged one keeps the flag while
     * the main image and every alt text (base and variation overrides) are unchanged. An alt edit,
     * another image or a decorative image clears it. Never sets it on an unflagged row.
     *
     * @param  array<string, string>|null  $baselineAlts  null when the baseline row is not flagged
     */
    private function carryAltCheck(MediaFieldValue $value, ?array $baselineAlts, ?int $baselineMediaId): void
    {
        $keep = $baselineAlts !== null
            && ! $value->decorative
            && $baselineMediaId === $value->media_id
            && $baselineAlts === $this->alts($value->load('conversions'));

        if ((bool) $value->alt_check_required !== $keep) {
            $value->alt_check_required = $keep;
            $value->saveQuietly();
        }
    }

    /**
     * The non-blank alt texts of a row: the base one under '' and one per variation.
     *
     * @return array<string, string>
     */
    private function alts(MediaFieldValue $value): array
    {
        $alts = ['' => (string) $value->alt_text];

        foreach ($value->conversions as $conversion) {
            $alts[$conversion->variation] = (string) $conversion->alt_text;
        }

        ksort($alts);

        return array_filter($alts, fn (string $alt): bool => trim($alt) !== '');
    }

    /**
     * Give a new conversion the file of the replaced row's conversion when both crop the same
     * image the same way. False when there is nothing to copy: the crop is generated instead.
     */
    private function copyCrop(MediaFieldValue $replaced, MediaFieldValue $value, MediaConversion $conversion): bool
    {
        $previous = $replaced->conversion($conversion->variation);

        $same = $previous !== null
            && $previous->status === ConversionStatus::Completed
            && $previous->path !== null
            && (int) $replaced->media_id === (int) $value->media_id
            && $previous->media_id === $conversion->media_id
            && $previous->width === $conversion->width
            && $previous->height === $conversion->height
            && round((float) $previous->focus_x, 6) === round((float) $conversion->focus_x, 6)
            && round((float) $previous->focus_y, 6) === round((float) $conversion->focus_y, 6)
            && round((float) $previous->zoom, 6) === round((float) $conversion->zoom, 6);

        if (! $same) {
            return false;
        }

        $disk = Storage::disk($value->source->disk);
        $path = $value->directory() . '/' . basename($previous->path);

        if (! $disk->exists($previous->path) || ! $disk->copy($previous->path, $path)) {
            return false;
        }

        $conversion->update(['path' => $path, 'status' => ConversionStatus::Completed]);

        return true;
    }

    private function clamp(float $value, float $min, float $max): float
    {
        return max($min, min($max, $value));
    }
}

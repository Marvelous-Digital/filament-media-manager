<?php

namespace MarvelousDigital\FilamentMediaManager\Actions;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use MarvelousDigital\FilamentMediaManager\Enums\ConversionStatus;
use MarvelousDigital\FilamentMediaManager\Jobs\GenerateConversion;
use MarvelousDigital\FilamentMediaManager\Models\MediaFieldValue;

class DuplicateMediaFieldValue
{
    /**
     * Copy stored field value(s) so another owner (e.g. a different language) gets
     * independent rows. Ids with no row are dropped; non-id values pass through unchanged.
     *
     * @param  (callable(string): string)|null  $mapAlt
     */
    public function execute(mixed $value, ?callable $mapAlt = null): mixed
    {
        if (is_array($value) && array_is_list($value) && $value !== [] && $this->allIds($value)) {
            $sources = $this->load($value);

            return collect($value)
                ->map(fn ($id) => $sources->get((int) $id))
                ->filter()
                ->map(fn (MediaFieldValue $source) => $this->copy($source, $mapAlt))
                ->values()
                ->all();
        }

        if (is_array($value) && $value === []) {
            return [];
        }

        if (! is_array($value) && is_numeric($value)) {
            $source = $this->load([$value])->get((int) $value);

            return $source ? $this->copy($source, $mapAlt) : null;
        }

        return $value;
    }

    /** @param  array<int, mixed>  $values */
    private function allIds(array $values): bool
    {
        return collect($values)->every(fn ($v) => ! is_array($v) && is_numeric($v));
    }

    /**
     * @param  array<int, mixed>  $ids
     * @return Collection<int, MediaFieldValue>
     */
    private function load(array $ids): Collection
    {
        return MediaFieldValue::query()
            ->with('conversions')
            ->whereIn('id', array_map('intval', $ids))
            ->get()
            ->keyBy('id');
    }

    /** @param  (callable(string): string)|null  $mapAlt */
    private function copy(MediaFieldValue $source, ?callable $mapAlt): int
    {
        $copy = MediaFieldValue::create([
            'media_id' => $source->media_id,
            'alt_text' => $this->alt($source->alt_text, $mapAlt),
            'created_by' => Auth::id(),
        ]);

        foreach ($source->conversions as $conversion) {
            $new = $copy->conversions()->create([
                'variation' => $conversion->variation,
                'media_id' => $conversion->media_id,
                'alt_text' => $this->alt($conversion->alt_text, $mapAlt),
                'width' => $conversion->width,
                'height' => $conversion->height,
                'focus_x' => $conversion->focus_x,
                'focus_y' => $conversion->focus_y,
                'zoom' => $conversion->zoom,
                'status' => ConversionStatus::Pending,
            ]);

            GenerateConversion::dispatchFor($new);
        }

        return $copy->id;
    }

    /** @param  (callable(string): string)|null  $mapAlt */
    private function alt(?string $alt, ?callable $mapAlt): ?string
    {
        return $mapAlt !== null && filled($alt) ? $mapAlt($alt) : $alt;
    }
}

<?php

namespace MarvelousDigital\FilamentMediaManager\Actions;

use Illuminate\Support\Facades\Auth;
use MarvelousDigital\FilamentMediaManager\Enums\ConversionStatus;
use MarvelousDigital\FilamentMediaManager\Jobs\GenerateConversion;
use MarvelousDigital\FilamentMediaManager\Models\Media;
use MarvelousDigital\FilamentMediaManager\Models\MediaFieldValue;

class PickMedia
{
    /**
     * @param  array<string, array{0: int, 1: int}>  $variations
     */
    public function execute(Media $media, array $variations): MediaFieldValue
    {
        $value = MediaFieldValue::create([
            'media_id' => $media->id,
            'alt_text' => $media->alt_text,
            'created_by' => Auth::id(),
        ]);

        foreach ($variations as $name => [$width, $height]) {
            $conversion = $value->conversions()->create([
                'variation' => $name,
                'width' => $width,
                'height' => $height,
                'focus_x' => 0.5,
                'focus_y' => 0.5,
                'status' => ConversionStatus::Pending,
            ]);

            GenerateConversion::dispatchFor($conversion);
        }

        return $value->load('conversions');
    }
}

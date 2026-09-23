<?php

namespace MarvelousDigital\FilamentMediaManager\Actions;

use MarvelousDigital\FilamentMediaManager\Enums\ConversionStatus;
use MarvelousDigital\FilamentMediaManager\Jobs\GenerateConversion;
use MarvelousDigital\FilamentMediaManager\Models\MediaConversion;

class UpdateConversionFocus
{
    public function execute(MediaConversion $conversion, float $x, float $y, float $zoom = 1.0): void
    {
        $conversion->update([
            'focus_x' => max(0.0, min(1.0, $x)),
            'focus_y' => max(0.0, min(1.0, $y)),
            'zoom' => max(1.0, $zoom),
            'status' => ConversionStatus::Pending,
        ]);

        GenerateConversion::dispatchFor($conversion);
    }
}

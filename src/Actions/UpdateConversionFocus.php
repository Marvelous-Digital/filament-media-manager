<?php

namespace MarvelousDigital\FilamentMediaManager\Actions;

use MarvelousDigital\FilamentMediaManager\Enums\ConversionStatus;
use MarvelousDigital\FilamentMediaManager\Jobs\GenerateConversion;
use MarvelousDigital\FilamentMediaManager\Models\MediaConversion;

class UpdateConversionFocus
{
    public function execute(MediaConversion $conversion, float $x, float $y): void
    {
        $conversion->update([
            'focus_x' => max(0.0, min(1.0, $x)),
            'focus_y' => max(0.0, min(1.0, $y)),
            'status' => ConversionStatus::Pending,
        ]);

        GenerateConversion::dispatchFor($conversion);
    }
}

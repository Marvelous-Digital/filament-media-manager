<?php

namespace MarvelousDigital\FilamentMediaManager\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use MarvelousDigital\FilamentMediaManager\Enums\ConversionStatus;
use MarvelousDigital\FilamentMediaManager\Models\MediaConversion;

class MediaConversionFactory extends Factory
{
    protected $model = MediaConversion::class;

    public function definition(): array
    {
        return [
            'variation' => 'hero',
            'width' => 1600,
            'height' => 600,
            'focus_x' => 0.5,
            'focus_y' => 0.5,
            'path' => null,
            'status' => ConversionStatus::Pending,
        ];
    }
}

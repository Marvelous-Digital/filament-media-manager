<?php

namespace MarvelousDigital\FilamentMediaManager\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use MarvelousDigital\FilamentMediaManager\Models\Media;
use MarvelousDigital\FilamentMediaManager\Models\MediaFieldValue;

class MediaFieldValueFactory extends Factory
{
    protected $model = MediaFieldValue::class;

    public function definition(): array
    {
        return [
            'media_id' => Media::factory(),
            'alt_text' => null,
        ];
    }
}

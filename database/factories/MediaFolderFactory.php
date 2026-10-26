<?php

namespace MarvelousDigital\FilamentMediaManager\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use MarvelousDigital\FilamentMediaManager\Models\MediaFolder;

class MediaFolderFactory extends Factory
{
    protected $model = MediaFolder::class;

    public function definition(): array
    {
        return [
            'name' => $this->faker->unique()->words(2, true),
            'parent_id' => null,
        ];
    }
}

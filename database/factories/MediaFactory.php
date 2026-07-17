<?php

namespace MarvelousDigital\FilamentMediaManager\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use MarvelousDigital\FilamentMediaManager\Enums\MediaType;
use MarvelousDigital\FilamentMediaManager\Enums\ThumbnailStatus;
use MarvelousDigital\FilamentMediaManager\Models\Media;

class MediaFactory extends Factory
{
    protected $model = Media::class;

    public function definition(): array
    {
        $ulid = (string) Str::ulid();

        return [
            'folder_id' => null,
            'name' => 'photo',
            'file_name' => 'photo.jpg',
            'disk' => 'public',
            'path' => "media/{$ulid}/photo.jpg",
            'mime_type' => 'image/jpeg',
            'type' => MediaType::Image,
            'size' => 1024,
            'thumbnail_status' => ThumbnailStatus::Pending,
        ];
    }
}

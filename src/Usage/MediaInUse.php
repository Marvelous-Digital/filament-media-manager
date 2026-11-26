<?php

namespace MarvelousDigital\FilamentMediaManager\Usage;

use MarvelousDigital\FilamentMediaManager\Models\Media;
use RuntimeException;

final class MediaInUse extends RuntimeException
{
    public function __construct(public Media $media)
    {
        parent::__construct("Media #{$media->id} is in use and cannot be deleted.");
    }

    public static function for(Media $media): self
    {
        return new self($media);
    }
}

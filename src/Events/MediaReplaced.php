<?php

namespace MarvelousDigital\FilamentMediaManager\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * A media was replaced everywhere it is used, after the change committed. For an image $newMediaId
 * is the media now shown (the old one may be gone); for a document both ids are the same row.
 */
final class MediaReplaced
{
    use Dispatchable;

    public function __construct(
        public readonly int $oldMediaId,
        public readonly int $newMediaId,
        public readonly int $placements,
    ) {}
}

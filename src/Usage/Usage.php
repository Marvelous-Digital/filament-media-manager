<?php

namespace MarvelousDigital\FilamentMediaManager\Usage;

final readonly class Usage
{
    public function __construct(
        public int $mediaId,
        public ?int $fieldValueId,
        public string $ownerType,
        public int | string $ownerId,
        public UsageSource $source,
        public ?int $revisionId,
        public ?string $locale,
    ) {}
}

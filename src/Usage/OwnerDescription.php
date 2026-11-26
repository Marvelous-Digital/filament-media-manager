<?php

namespace MarvelousDigital\FilamentMediaManager\Usage;

final readonly class OwnerDescription
{
    /**
     * @param  list<OwnerDescription>  $children
     */
    public function __construct(
        public string $title,
        public ?string $url,
        public ?string $detail = null,
        public array $children = [],
    ) {}
}

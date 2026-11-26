<?php

namespace MarvelousDigital\FilamentMediaManager\Usage;

class FallbackOwnerDescriber implements DescribesMediaOwners
{
    public function describe(Usage $usage): OwnerDescription
    {
        return new OwnerDescription(class_basename($usage->ownerType) . ' #' . $usage->ownerId, null);
    }
}

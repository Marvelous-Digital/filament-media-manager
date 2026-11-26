<?php

namespace MarvelousDigital\FilamentMediaManager\Usage;

/** Turns a usage into something a person recognises (a title, a link). Hosts rebind it. */
interface DescribesMediaOwners
{
    public function describe(Usage $usage): OwnerDescription;
}

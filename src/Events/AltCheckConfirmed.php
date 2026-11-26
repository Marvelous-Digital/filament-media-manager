<?php

namespace MarvelousDigital\FilamentMediaManager\Events;

use Illuminate\Foundation\Events\Dispatchable;
use MarvelousDigital\FilamentMediaManager\Models\MediaFieldValue;

/** An editor confirmed the alt text of a placement flagged after a replacement. */
final class AltCheckConfirmed
{
    use Dispatchable;

    public function __construct(public readonly MediaFieldValue $value) {}
}

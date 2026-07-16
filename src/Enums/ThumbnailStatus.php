<?php

namespace MarvelousDigital\FilamentMediaManager\Enums;

enum ThumbnailStatus: string
{
    case Pending = 'pending';
    case Completed = 'completed';
    case Failed = 'failed';
    case Skipped = 'skipped';
}

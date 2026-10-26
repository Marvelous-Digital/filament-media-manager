<?php

namespace MarvelousDigital\FilamentMediaManager\Enums;

enum ConversionStatus: string
{
    case Pending = 'pending';
    case Processing = 'processing';
    case Completed = 'completed';
    case Failed = 'failed';
}

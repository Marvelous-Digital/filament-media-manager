<?php

namespace MarvelousDigital\FilamentMediaManager\Usage;

enum UsageSource: string
{
    case Live = 'live';
    case Draft = 'draft';
    case History = 'history';

    /** Live and draft usages stop a media from being deleted; history only warrants a warning. */
    public function blocksDelete(): bool
    {
        return $this !== self::History;
    }
}

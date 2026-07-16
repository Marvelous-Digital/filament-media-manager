<?php

namespace MarvelousDigital\FilamentMediaManager\Pages;

use Filament\Pages\Page;

class MediaLibrary extends Page
{
    protected string $view = 'filament-media-manager::media-library';

    public static function getNavigationLabel(): string
    {
        return 'Media';
    }

    public function getTitle(): string
    {
        return 'Media';
    }
}

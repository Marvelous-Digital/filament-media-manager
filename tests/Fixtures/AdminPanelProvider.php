<?php

namespace MarvelousDigital\FilamentMediaManager\Tests\Fixtures;

use Filament\Panel;
use Filament\PanelProvider;
use MarvelousDigital\FilamentMediaManager\MediaManagerPlugin;

class AdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('admin')
            ->path('admin')
            ->login()
            ->plugin(MediaManagerPlugin::make());
    }
}

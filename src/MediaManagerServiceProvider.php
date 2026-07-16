<?php

namespace MarvelousDigital\FilamentMediaManager;

use Filament\Support\Assets\Css;
use Filament\Support\Facades\FilamentAsset;
use Spatie\LaravelPackageTools\Commands\InstallCommand;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;

class MediaManagerServiceProvider extends PackageServiceProvider
{
    public static string $name = 'filament-media-manager';

    public static string $viewNamespace = 'filament-media-manager';

    public function configurePackage(Package $package): void
    {
        $package->name(static::$name)
            ->hasConfigFile()
            ->hasViews(static::$viewNamespace)
            ->hasMigrations(['create_filament_media_manager_tables'])
            ->hasInstallCommand(function (InstallCommand $command) {
                $command
                    ->publishConfigFile()
                    ->publishMigrations()
                    ->askToRunMigrations();
            });
    }

    public function packageBooted(): void
    {
        FilamentAsset::register([
            Css::make('filament-media-manager-styles', __DIR__ . '/../resources/dist/filament-media-manager.css'),
        ], 'marvelous-digital/filament-media-manager');
    }
}

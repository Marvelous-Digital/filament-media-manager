<?php

namespace MarvelousDigital\FilamentMediaManager;

use Filament\Support\Assets\Css;
use Filament\Support\Facades\FilamentAsset;
use Livewire\Livewire;
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
            ->hasMigrations(['create_filament_media_manager_tables', 'create_media_field_tables'])
            ->hasInstallCommand(function (InstallCommand $command) {
                $command
                    ->publishConfigFile()
                    ->publishMigrations()
                    ->askToRunMigrations();
            });
    }

    public function packageBooted(): void
    {
        $this->alignTemporaryUploadLimit();

        FilamentAsset::register([
            Css::make('filament-media-manager-styles', __DIR__ . '/../resources/dist/filament-media-manager.css'),
        ], 'marvelous-digital/filament-media-manager');

        Livewire::addNamespace(
            static::$viewNamespace,
            classNamespace: 'MarvelousDigital\\FilamentMediaManager\\Livewire',
        );
    }

    /**
     * Livewire's temporary upload rules default to a 12 MB cap, which is smaller than this
     * package's configured max_file_size. Raise the cap to match so uploads up to the
     * configured size reach the component instead of failing at the temporary upload stage.
     * Only applied when the app hasn't customised the rules itself.
     */
    protected function alignTemporaryUploadLimit(): void
    {
        if (config('livewire.temporary_file_upload.rules') !== null) {
            return;
        }

        $maxKilobytes = (int) config('filament-media-manager.max_file_size');

        if ($maxKilobytes <= 12288) {
            return;
        }

        config(['livewire.temporary_file_upload.rules' => ['required', 'file', "max:{$maxKilobytes}"]]);
    }
}

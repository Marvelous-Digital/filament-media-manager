<?php

use Illuminate\Support\Facades\Schema;
use MarvelousDigital\FilamentMediaManager\Models\Media;
use MarvelousDigital\FilamentMediaManager\Models\MediaConversion;
use MarvelousDigital\FilamentMediaManager\Models\MediaFieldValue;
use MarvelousDigital\FilamentMediaManager\Models\MediaFolder;

it('creates prefixed tables that do not clash with spatie/laravel-medialibrary', function () {
    expect(Schema::hasTable('media_manager_media'))->toBeTrue()
        ->and(Schema::hasTable('media_manager_folders'))->toBeTrue()
        ->and(Schema::hasTable('media_manager_field_values'))->toBeTrue()
        ->and(Schema::hasTable('media_manager_conversions'))->toBeTrue()
        ->and(Schema::hasTable('media'))->toBeFalse();
});

it('reads model table names from the config', function (string $model, string $key) {
    config()->set("filament-media-manager.table_names.{$key}", "custom_{$key}");

    expect((new $model)->getTable())->toBe("custom_{$key}");
})->with([
    'media' => [Media::class, 'media'],
    'folders' => [MediaFolder::class, 'folders'],
    'field values' => [MediaFieldValue::class, 'field_values'],
    'conversions' => [MediaConversion::class, 'conversions'],
]);

it('creates the tables under the configured names', function () {
    config()->set('filament-media-manager.table_names', [
        'media' => 'custom_media',
        'folders' => 'custom_folders',
        'field_values' => 'custom_field_values',
        'conversions' => 'custom_conversions',
    ]);

    (require __DIR__ . '/../database/migrations/create_filament_media_manager_tables.php')->up();
    (require __DIR__ . '/../database/migrations/create_media_field_tables.php')->up();

    expect(Schema::hasTable('custom_media'))->toBeTrue()
        ->and(Schema::hasTable('custom_folders'))->toBeTrue()
        ->and(Schema::hasTable('custom_field_values'))->toBeTrue()
        ->and(Schema::hasTable('custom_conversions'))->toBeTrue();
});

<?php

use Illuminate\Support\Facades\Schema;
use MarvelousDigital\FilamentMediaManager\Models\Media;
use MarvelousDigital\FilamentMediaManager\Models\MediaConversion;
use MarvelousDigital\FilamentMediaManager\Models\MediaFieldValue;
use MarvelousDigital\FilamentMediaManager\Models\MediaFolder;
use MarvelousDigital\FilamentMediaManager\Models\MediaUsageRow;

it('creates prefixed tables that do not clash with spatie/laravel-medialibrary', function () {
    expect(Schema::hasTable('media_manager_media'))->toBeTrue()
        ->and(Schema::hasTable('media_manager_folders'))->toBeTrue()
        ->and(Schema::hasTable('media_manager_field_values'))->toBeTrue()
        ->and(Schema::hasTable('media_manager_conversions'))->toBeTrue()
        ->and(Schema::hasTable('media_manager_usages'))->toBeTrue()
        ->and(Schema::hasTable('media_manager_usage_state'))->toBeTrue()
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
    'usages' => [MediaUsageRow::class, 'usages'],
]);

it('creates the tables under the configured names', function () {
    config()->set('filament-media-manager.table_names', [
        'media' => 'custom_media',
        'folders' => 'custom_folders',
        'field_values' => 'custom_field_values',
        'conversions' => 'custom_conversions',
        'usages' => 'custom_usages',
        'usage_state' => 'custom_usage_state',
    ]);

    (require __DIR__ . '/../database/migrations/create_filament_media_manager_tables.php')->up();
    (require __DIR__ . '/../database/migrations/create_media_field_tables.php')->up();
    (require __DIR__ . '/../database/migrations/create_media_usages_table.php')->up();

    expect(Schema::hasTable('custom_media'))->toBeTrue()
        ->and(Schema::hasTable('custom_folders'))->toBeTrue()
        ->and(Schema::hasTable('custom_field_values'))->toBeTrue()
        ->and(Schema::hasTable('custom_conversions'))->toBeTrue()
        ->and(Schema::hasTable('custom_usages'))->toBeTrue()
        ->and(Schema::hasTable('custom_usage_state'))->toBeTrue();
});

it('creates the usage tables when a pre-0.4.0 published config lacks their table names', function () {
    config()->set('filament-media-manager.table_names', [
        'media' => 'media_manager_media',
        'folders' => 'media_manager_folders',
        'field_values' => 'media_manager_field_values',
        'conversions' => 'media_manager_conversions',
    ]);

    Schema::dropIfExists('media_manager_usage_state');
    Schema::dropIfExists('media_manager_usages');

    (require __DIR__ . '/../database/migrations/create_media_usages_table.php')->up();

    expect(Schema::hasTable('media_manager_usages'))->toBeTrue()
        ->and(Schema::hasTable('media_manager_usage_state'))->toBeTrue();
});

<?php

use MarvelousDigital\FilamentMediaManager\Forms\Components\ImagePicker;

it('defaults the disk to the configured media disk', function () {
    config()->set('filament-media-manager.disk', 'public');

    $field = ImagePicker::make('hero_image_id');

    expect($field->getDisk())->toBe('public');
});

it('exposes configured variations', function () {
    $field = ImagePicker::make('hero_image_id')->variations([
        'hero' => [1600, 600],
    ]);

    expect($field->getVariations())->toBe(['hero' => [1600, 600]]);
});

it('falls back to a single default variation', function () {
    $field = ImagePicker::make('hero_image_id');

    expect($field->getVariations())->toBe(['default' => [1200, 1200]]);
});

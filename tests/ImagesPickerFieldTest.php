<?php

use MarvelousDigital\FilamentMediaManager\Forms\Components\ImagesPicker;

it('has no variations unless configured', function () {
    expect(ImagesPicker::make('gallery')->getVariations())->toBe([]);
});

it('exposes configured variations', function () {
    expect(ImagesPicker::make('gallery')->variations(['tile' => [800, 450]])->getVariations())->toBe(['tile' => [800, 450]]);
});

it('defaults the disk to the configured media disk and can override it', function () {
    config()->set('filament-media-manager.disk', 'public');

    expect(ImagesPicker::make('gallery')->getDisk())->toBe('public')
        ->and(ImagesPicker::make('gallery')->disk('s3')->getDisk())->toBe('s3');
});

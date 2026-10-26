<?php

use MarvelousDigital\FilamentMediaManager\Enums\ConversionStatus;
use MarvelousDigital\FilamentMediaManager\Models\MediaConversion;
use MarvelousDigital\FilamentMediaManager\Models\MediaFieldValue;

it('has the expected string values', function () {
    expect(ConversionStatus::Pending->value)->toBe('pending')
        ->and(ConversionStatus::Processing->value)->toBe('processing')
        ->and(ConversionStatus::Completed->value)->toBe('completed')
        ->and(ConversionStatus::Failed->value)->toBe('failed');
});

it('creates the media field tables', function () {
    // Tables exist and are empty (migrations ran in the test harness).
    expect(MediaFieldValue::query()->count())->toBe(0)
        ->and(MediaConversion::query()->count())->toBe(0);
});

<?php

use Illuminate\Support\Facades\DB;
use MarvelousDigital\FilamentMediaManager\Enums\ConversionStatus;

it('has the expected string values', function () {
    expect(ConversionStatus::Pending->value)->toBe('pending')
        ->and(ConversionStatus::Processing->value)->toBe('processing')
        ->and(ConversionStatus::Completed->value)->toBe('completed')
        ->and(ConversionStatus::Failed->value)->toBe('failed');
});

it('creates the media field tables', function () {
    // Tables exist and are empty (migrations ran in the test harness).
    // Note: MediaFieldValue and MediaConversion Eloquent models are created in
    // a later task; here we assert directly against the query builder.
    expect(DB::table('media_field_values')->count())->toBe(0)
        ->and(DB::table('media_conversions')->count())->toBe(0);
});

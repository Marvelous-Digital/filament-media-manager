<?php

use Illuminate\Support\Facades\Queue;
use MarvelousDigital\FilamentMediaManager\Actions\UpdateConversionFocus;
use MarvelousDigital\FilamentMediaManager\Enums\ConversionStatus;
use MarvelousDigital\FilamentMediaManager\Jobs\GenerateConversion;
use MarvelousDigital\FilamentMediaManager\Models\Media;
use MarvelousDigital\FilamentMediaManager\Models\MediaConversion;
use MarvelousDigital\FilamentMediaManager\Models\MediaFieldValue;

it('updates focus and zoom, marks pending, and re-dispatches generation', function () {
    Queue::fake();
    $media = Media::factory()->create();
    $value = MediaFieldValue::factory()->for($media, 'source')->create();
    $conversion = MediaConversion::factory()->for($value, 'value')->create([
        'status' => ConversionStatus::Completed, 'focus_x' => 0.5, 'focus_y' => 0.5, 'zoom' => 1.0,
    ]);

    app(UpdateConversionFocus::class)->execute($conversion, 0.2, 0.9, 2.5);

    $conversion->refresh();
    expect($conversion->focus_x)->toBe(0.2)
        ->and($conversion->focus_y)->toBe(0.9)
        ->and($conversion->zoom)->toBe(2.5)
        ->and($conversion->status)->toBe(ConversionStatus::Pending);

    Queue::assertPushed(GenerateConversion::class);
});

it('clamps focus values to the unit interval and zoom to a minimum of one', function () {
    Queue::fake();
    $media = Media::factory()->create();
    $value = MediaFieldValue::factory()->for($media, 'source')->create();
    $conversion = MediaConversion::factory()->for($value, 'value')->create();

    app(UpdateConversionFocus::class)->execute($conversion, -1.0, 5.0, 0.3);

    $conversion->refresh();
    expect($conversion->focus_x)->toBe(0.0)
        ->and($conversion->focus_y)->toBe(1.0)
        ->and($conversion->zoom)->toBe(1.0);
});

it('defaults zoom to one when omitted', function () {
    Queue::fake();
    $media = Media::factory()->create();
    $value = MediaFieldValue::factory()->for($media, 'source')->create();
    $conversion = MediaConversion::factory()->for($value, 'value')->create(['zoom' => 3.0]);

    app(UpdateConversionFocus::class)->execute($conversion, 0.5, 0.5);

    expect($conversion->refresh()->zoom)->toBe(1.0);
});

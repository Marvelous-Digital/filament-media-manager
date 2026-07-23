<?php

use Illuminate\Support\Facades\Queue;
use MarvelousDigital\FilamentMediaManager\Actions\UpdateConversionFocus;
use MarvelousDigital\FilamentMediaManager\Enums\ConversionStatus;
use MarvelousDigital\FilamentMediaManager\Jobs\GenerateConversion;
use MarvelousDigital\FilamentMediaManager\Models\Media;
use MarvelousDigital\FilamentMediaManager\Models\MediaConversion;
use MarvelousDigital\FilamentMediaManager\Models\MediaFieldValue;

it('updates focus, marks pending, and re-dispatches generation', function () {
    Queue::fake();
    $media = Media::factory()->create();
    $value = MediaFieldValue::factory()->for($media, 'source')->create();
    $conversion = MediaConversion::factory()->for($value, 'value')->create([
        'status' => ConversionStatus::Completed, 'focus_x' => 0.5, 'focus_y' => 0.5,
    ]);

    app(UpdateConversionFocus::class)->execute($conversion, 0.2, 0.9);

    $conversion->refresh();
    expect($conversion->focus_x)->toBe(0.2)
        ->and($conversion->focus_y)->toBe(0.9)
        ->and($conversion->status)->toBe(ConversionStatus::Pending);

    Queue::assertPushed(GenerateConversion::class);
});

it('clamps focus values to the unit interval', function () {
    Queue::fake();
    $media = Media::factory()->create();
    $value = MediaFieldValue::factory()->for($media, 'source')->create();
    $conversion = MediaConversion::factory()->for($value, 'value')->create();

    app(UpdateConversionFocus::class)->execute($conversion, -1.0, 5.0);

    $conversion->refresh();
    expect($conversion->focus_x)->toBe(0.0)->and($conversion->focus_y)->toBe(1.0);
});

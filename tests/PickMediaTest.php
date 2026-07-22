<?php

use Illuminate\Support\Facades\Queue;
use MarvelousDigital\FilamentMediaManager\Actions\PickMedia;
use MarvelousDigital\FilamentMediaManager\Enums\ConversionStatus;
use MarvelousDigital\FilamentMediaManager\Jobs\GenerateConversion;
use MarvelousDigital\FilamentMediaManager\Models\Media;

it('creates a usage record copying alt text and seeding conversions', function () {
    Queue::fake();
    $media = Media::factory()->create(['alt_text' => 'A cat']);

    $value = app(PickMedia::class)->execute($media, [
        'hero' => [1600, 600],
        'thumb' => [400, 400],
    ]);

    expect($value->media_id)->toBe($media->id)
        ->and($value->alt_text)->toBe('A cat')
        ->and($value->conversions)->toHaveCount(2)
        ->and($value->conversion('hero')->width)->toBe(1600)
        ->and($value->conversion('hero')->height)->toBe(600)
        ->and($value->conversion('hero')->focus_x)->toBe(0.5)
        ->and($value->conversion('hero')->status)->toBe(ConversionStatus::Pending);

    Queue::assertPushed(GenerateConversion::class, 2);
});

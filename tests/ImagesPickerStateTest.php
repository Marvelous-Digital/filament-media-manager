<?php

use MarvelousDigital\FilamentMediaManager\Models\Media;
use MarvelousDigital\FilamentMediaManager\Models\MediaFieldValue;
use MarvelousDigital\FilamentMediaManager\Support\ImagesPickerState;

it('hydrates stored ids in order', function () {
    $a = MediaFieldValue::create(['media_id' => Media::factory()->create()->id, 'alt_text' => 'A']);
    $b = MediaFieldValue::create(['media_id' => Media::factory()->create()->id]);

    $state = ImagesPickerState::fromStoredValue([$b->id, $a->id]);

    expect($state['removed'])->toBe([])
        ->and(array_column($state['items'], 'id'))->toBe([$b->id, $a->id])
        ->and($state['items'][0]['key'])->toBe("v{$b->id}")
        ->and($state['items'][1]['media_id'])->toBe($a->media_id)
        ->and($state['items'][1]['alt'])->toBe('A');
});

it('skips ids whose row no longer exists', function () {
    $a = MediaFieldValue::create(['media_id' => Media::factory()->create()->id]);

    $state = ImagesPickerState::fromStoredValue([9999, $a->id]);

    expect(array_column($state['items'], 'id'))->toBe([$a->id]);
});

it('returns hydrated state as-is and empty state for anything else', function () {
    $hydrated = ['items' => [['key' => 'x', 'id' => null, 'media_id' => 1, 'alt' => null]], 'removed' => [3]];

    expect(ImagesPickerState::fromStoredValue($hydrated))->toBe($hydrated)
        ->and(ImagesPickerState::fromStoredValue(null))->toBe(['items' => [], 'removed' => []])
        ->and(ImagesPickerState::fromStoredValue('nope'))->toBe(['items' => [], 'removed' => []]);
});

it('appends an image', function () {
    $media = Media::factory()->create(['alt_text' => 'Cat']);

    $state = ImagesPickerState::withImage(['items' => [], 'removed' => []], $media);

    expect($state['items'])->toHaveCount(1)
        ->and($state['items'][0]['id'])->toBeNull()
        ->and($state['items'][0]['media_id'])->toBe($media->id)
        ->and($state['items'][0]['alt'])->toBe('Cat')
        ->and($state['items'][0]['key'])->toBeString()->not->toBe('');
});

it('removing a saved image remembers its id', function () {
    $a = MediaFieldValue::create(['media_id' => Media::factory()->create()->id]);
    $state = ImagesPickerState::fromStoredValue([$a->id]);

    $state = ImagesPickerState::withoutImage($state, "v{$a->id}");

    expect($state['items'])->toBe([])->and($state['removed'])->toBe([$a->id]);
});

it('removing a new image forgets it', function () {
    $state = ImagesPickerState::withImage(['items' => [], 'removed' => []], Media::factory()->create());

    $state = ImagesPickerState::withoutImage($state, $state['items'][0]['key']);

    expect($state)->toBe(['items' => [], 'removed' => []]);
});

it('reorders by key', function () {
    $state = ['items' => [], 'removed' => []];
    foreach (range(1, 4) as $ignored) {
        $state = ImagesPickerState::withImage($state, Media::factory()->create());
    }
    [$a, $b, $c, $d] = array_column($state['items'], 'key');

    $reordered = ImagesPickerState::reordered($state, [$c, 'unknown', $a]);

    expect(array_column($reordered['items'], 'key'))->toBe([$c, $a, $b, $d]);
});

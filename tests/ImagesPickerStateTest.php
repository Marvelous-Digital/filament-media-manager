<?php

use MarvelousDigital\FilamentMediaManager\Models\Media;
use MarvelousDigital\FilamentMediaManager\Models\MediaFieldValue;
use MarvelousDigital\FilamentMediaManager\Support\FieldValueToken;
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

    expect($state['items'])->toBe([])->and($state['removed'])->toBe([['id' => $a->id, 'token' => FieldValueToken::for($a->id)]]);
});

it('hydrates each saved item with a token for its id', function () {
    $a = MediaFieldValue::create(['media_id' => Media::factory()->create()->id]);

    expect(ImagesPickerState::fromStoredValue([$a->id])['items'][0]['token'])->toBe(FieldValueToken::for($a->id))
        ->and(ImagesPickerState::withImage(['items' => [], 'removed' => []], Media::factory()->create())['items'][0]['token'])->toBeNull();
});

it('verified keeps tokened ids and removals and drops forged ones', function () {
    [$a, $b, $c] = MediaFieldValue::factory()->count(3)->for(Media::factory(), 'source')->create()->all();
    $state = ImagesPickerState::withoutImage(ImagesPickerState::fromStoredValue([$a->id, $b->id]), "v{$b->id}");

    expect(ImagesPickerState::verified($state))->toBe($state);

    $state['items'][] = ['key' => 'forged', 'id' => $c->id, 'token' => 'nope', 'media_id' => $c->media_id, 'alt' => null];
    $state['removed'][] = ['id' => $c->id, 'token' => FieldValueToken::for($c->id + 1)];
    $state['removed'][] = $c->id;
    $verified = ImagesPickerState::verified($state);

    expect(array_column($verified['items'], 'id'))->toBe([$a->id, null])
        ->and($verified['items'][1]['token'])->toBeNull()
        ->and(array_column($verified['removed'], 'id'))->toBe([$b->id]);
});

it('verified does not let an id be kept twice or kept and removed', function () {
    $a = MediaFieldValue::factory()->for(Media::factory(), 'source')->create();
    $state = ImagesPickerState::fromStoredValue([$a->id]);
    $state['items'][] = [...$state['items'][0], 'key' => 'copy'];
    $state['removed'][] = ['id' => $a->id, 'token' => FieldValueToken::for($a->id)];

    $verified = ImagesPickerState::verified($state);

    expect(array_column($verified['items'], 'id'))->toBe([$a->id, null])
        ->and($verified['removed'])->toBe([]);
});

it('verified skips malformed items and treats a plain id list as untrusted copies', function () {
    $a = MediaFieldValue::factory()->for(Media::factory(), 'source')->create(['alt_text' => 'Theirs']);

    $verified = ImagesPickerState::verified(['items' => ['junk', ['id' => 3], ['media_id' => ['x']], ['media_id' => 7, 'alt' => ['x'], 'key' => 5]], 'removed' => 'junk']);

    expect($verified['removed'])->toBe([])
        ->and($verified['items'])->toHaveCount(1)
        ->and($verified['items'][0])->toMatchArray(['id' => null, 'token' => null, 'media_id' => 7, 'alt' => null])
        ->and(ImagesPickerState::verified(['items' => 'junk'])['items'])->toBe([])
        ->and(ImagesPickerState::verified([$a->id])['items'])->toBe([['key' => "v{$a->id}", 'id' => null, 'token' => null, 'media_id' => $a->media_id, 'alt' => 'Theirs']]);
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

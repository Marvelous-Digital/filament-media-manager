<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use MarvelousDigital\FilamentMediaManager\Actions\DuplicateMediaFieldValue;
use MarvelousDigital\FilamentMediaManager\Actions\PersistImagePicker;
use MarvelousDigital\FilamentMediaManager\Enums\ConversionStatus;
use MarvelousDigital\FilamentMediaManager\Jobs\GenerateConversion;
use MarvelousDigital\FilamentMediaManager\Models\Media;
use MarvelousDigital\FilamentMediaManager\Models\MediaConversion;
use MarvelousDigital\FilamentMediaManager\Models\MediaFieldValue;
use MarvelousDigital\FilamentMediaManager\Support\ImagePickerState;

beforeEach(fn () => Queue::fake());

$variations = ['desktop' => [1500, 900], 'mobile' => [480, 640]];

function persistFocus(mixed $state, array $variations): ?int
{
    return app(PersistImagePicker::class)->execute($state, $variations);
}

it('starts a new image with a centred base focus and variants that follow it', function () use ($variations) {
    $state = ImagePickerState::withMainImage(null, Media::factory()->create(), $variations);

    expect($state['focus_x'])->toBe(0.5)
        ->and($state['focus_y'])->toBe(0.5)
        ->and($state['variants']['desktop']['custom'])->toBeFalse()
        ->and($state['variants']['mobile']['custom'])->toBeFalse();
});

it('hydrates the base focus and which variants have their own focus', function () use ($variations) {
    $value = MediaFieldValue::factory()->create(['focus_x' => 0.3, 'focus_y' => 0.7]);
    MediaConversion::factory()->for($value, 'value')->create(['variation' => 'desktop', 'focus_x' => 0.3, 'focus_y' => 0.7]);
    MediaConversion::factory()->for($value, 'value')->create(['variation' => 'mobile', 'focus_x' => 0.9, 'focus_y' => 0.1, 'custom_focus' => true]);

    $state = ImagePickerState::fromStoredValue($value->id, $variations);

    expect($state['focus_x'])->toBe(0.3)
        ->and($state['focus_y'])->toBe(0.7)
        ->and($state['variants']['desktop'])->toMatchArray(['custom' => false, 'focus_x' => 0.3, 'focus_y' => 0.7])
        ->and($state['variants']['mobile'])->toMatchArray(['custom' => true, 'focus_x' => 0.9, 'focus_y' => 0.1]);
});

it('gives an own image its own focus and puts the base focus back when it is removed', function () use ($variations) {
    $state = ImagePickerState::withMainImage(null, Media::factory()->create(), $variations);
    $state['focus_x'] = 0.2;
    $state['focus_y'] = 0.8;

    $state = ImagePickerState::withVariantImage($state, 'mobile', Media::factory()->create());
    expect($state['variants']['mobile'])->toMatchArray(['custom' => true, 'focus_x' => 0.5, 'focus_y' => 0.5]);

    $state = ImagePickerState::withoutVariantImage($state, 'mobile');
    expect($state['variants']['mobile'])->toMatchArray(['media_id' => null, 'custom' => false, 'focus_x' => 0.2, 'focus_y' => 0.8, 'zoom' => 1.0]);
});

it('verified coerces the base focus and the custom flag', function () use ($variations) {
    $state = ImagePickerState::verified([
        'media_id' => Media::factory()->create()->id,
        'focus_x' => 'nope',
        'focus_y' => '0.25',
        'variants' => ['desktop' => ['custom' => '1'], 'mobile' => ['custom' => 'no']],
    ], $variations);

    expect($state['focus_x'])->toBe(0.5)
        ->and($state['focus_y'])->toBe(0.25)
        ->and($state['variants']['desktop']['custom'])->toBeTrue()
        ->and($state['variants']['mobile']['custom'])->toBeFalse();
});

it('saves the base focus and crops following variants on it whatever focus they carry', function () use ($variations) {
    $state = ImagePickerState::withMainImage(null, Media::factory()->create(), $variations);
    $state['focus_x'] = 0.2;
    $state['focus_y'] = 1.4;
    $state['variants']['desktop']['focus_x'] = 0.9;
    $state['variants']['mobile'] = [...$state['variants']['mobile'], 'custom' => true, 'focus_x' => 0.7, 'focus_y' => 0.6];

    $value = MediaFieldValue::with('conversions')->find(persistFocus($state, $variations));

    expect($value->focus_x)->toBe(0.2)
        ->and($value->focus_y)->toBe(1.0)
        ->and($value->conversion('desktop'))->toMatchArray(['focus_x' => 0.2, 'focus_y' => 1.0, 'custom_focus' => false])
        ->and($value->conversion('mobile'))->toMatchArray(['focus_x' => 0.7, 'focus_y' => 0.6, 'custom_focus' => true]);
});

it('keeps the own focus of a variant with its own image', function () use ($variations) {
    $state = ImagePickerState::withVariantImage(ImagePickerState::withMainImage(null, Media::factory()->create(), $variations), 'mobile', Media::factory()->create());
    $state['focus_x'] = 0.1;
    $state['variants']['mobile']['focus_x'] = 0.8;
    $state['variants']['mobile']['custom'] = false;

    $value = MediaFieldValue::with('conversions')->find(persistFocus($state, $variations));

    expect($value->conversion('mobile'))->toMatchArray(['focus_x' => 0.8, 'custom_focus' => true]);
});

it('re-crops only the variants that follow the base when the base focus moves', function () use ($variations) {
    $state = ImagePickerState::withMainImage(null, Media::factory()->create(), $variations);
    $state['variants']['mobile'] = [...$state['variants']['mobile'], 'custom' => true, 'focus_x' => 0.7];
    $id = persistFocus($state, $variations);
    MediaConversion::query()->update(['status' => ConversionStatus::Completed]);
    Queue::fake();

    $state = ImagePickerState::fromStoredValue($id, $variations);
    $state['focus_x'] = 0.3;
    persistFocus($state, $variations);

    $value = MediaFieldValue::with('conversions')->find($id);
    expect($value->conversion('desktop')->status)->toBe(ConversionStatus::Pending)
        ->and($value->conversion('desktop')->focus_x)->toBe(0.3)
        ->and($value->conversion('mobile')->status)->toBe(ConversionStatus::Completed);
    Queue::assertPushed(GenerateConversion::class, 1);
});

it('keeps the saved base focus when the state carries none', function () use ($variations) {
    $value = MediaFieldValue::factory()->create(['focus_x' => 0.3, 'focus_y' => 0.6]);

    persistFocus(['id' => $value->id, 'media_id' => $value->media_id, 'alt' => null, 'variants' => []], $variations);

    expect($value->fresh()->focus_x)->toBe(0.3)
        ->and($value->fresh()->conversions->firstWhere('variation', 'desktop')->focus_x)->toBe(0.3);
});

it('copies the base focus and custom flags when duplicating', function () {
    $value = MediaFieldValue::factory()->create(['focus_x' => 0.3, 'focus_y' => 0.6]);
    MediaConversion::factory()->for($value, 'value')->create(['variation' => 'mobile', 'focus_x' => 0.9, 'custom_focus' => true]);

    $copy = MediaFieldValue::with('conversions')->find(app(DuplicateMediaFieldValue::class)->execute($value->id));

    expect($copy->focus_x)->toBe(0.3)
        ->and($copy->focus_y)->toBe(0.6)
        ->and($copy->conversion('mobile')->custom_focus)->toBeTrue();
});

it('marks already reframed conversions as custom when migrating existing data', function () {
    $migration = require __DIR__ . '/../database/migrations/update_media_field_tables_for_base_focus.php';
    $migration->down();

    $valueId = DB::table('media_manager_field_values')->insertGetId(['media_id' => Media::factory()->create()->id]);
    $row = fn (string $variation, array $attributes) => DB::table('media_manager_conversions')->insert([
        'media_field_value_id' => $valueId, 'variation' => $variation, 'width' => 10, 'height' => 10, ...$attributes,
    ]);
    $row('centred', ['focus_x' => 0.5, 'focus_y' => 0.5, 'zoom' => 2.0]);
    $row('moved', ['focus_x' => 0.2, 'focus_y' => 0.5]);
    $row('own', ['media_id' => Media::factory()->create()->id]);

    $migration->up();

    expect(DB::table('media_manager_conversions')->orderBy('variation')->pluck('custom_focus', 'variation')->map(fn ($flag) => (bool) $flag)->all())
        ->toBe(['centred' => false, 'moved' => true, 'own' => true])
        ->and((float) DB::table('media_manager_field_values')->value('focus_x'))->toBe(0.5);
});

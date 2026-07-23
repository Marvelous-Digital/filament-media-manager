<?php

use Filament\Actions\Concerns\InteractsWithActions;
use Filament\Actions\Contracts\HasActions;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\Queue;
use Livewire\Component;
use Livewire\Livewire;
use MarvelousDigital\FilamentMediaManager\Enums\ConversionStatus;
use MarvelousDigital\FilamentMediaManager\Forms\Components\ImagePicker;
use MarvelousDigital\FilamentMediaManager\Jobs\GenerateConversion;
use MarvelousDigital\FilamentMediaManager\Models\Media;
use MarvelousDigital\FilamentMediaManager\Models\MediaConversion;
use MarvelousDigital\FilamentMediaManager\Models\MediaFieldValue;

/**
 * Minimal Livewire host exposing an ImagePicker field so its registered
 * form-component actions (updateAlt / updateFocus / retryConversion) can be
 * exercised the same way the blade view invokes them via mountFormComponentAction.
 */
class ImagePickerFieldActionsHarness extends Component implements HasActions, HasForms
{
    use InteractsWithActions;
    use InteractsWithForms;

    /** @var array<string, mixed> */
    public array $data = [];

    public function mount(): void
    {
        $this->form->fill();
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                ImagePicker::make('hero_image_id')->variations(['hero' => [1600, 600]]),
            ])
            ->statePath('data');
    }

    public function render(): string
    {
        return '<div></div>';
    }
}

it('persists alt text via the updateAlt field action', function () {
    $value = MediaFieldValue::factory()->for(Media::factory(), 'source')->create(['alt_text' => null]);

    Livewire::test(ImagePickerFieldActionsHarness::class)
        ->set('data.hero_image_id', $value->id)
        ->callFormComponentAction('hero_image_id', 'updateAlt', arguments: ['alt' => 'A red bicycle'])
        ->assertHasNoFormComponentActionErrors();

    expect($value->refresh()->alt_text)->toBe('A red bicycle');
});

it('updates focus via the updateFocus field action', function () {
    Queue::fake();
    $value = MediaFieldValue::factory()->for(Media::factory(), 'source')->create();
    $conversion = MediaConversion::factory()->for($value, 'value')->create([
        'status' => ConversionStatus::Completed, 'focus_x' => 0.5, 'focus_y' => 0.5,
    ]);

    Livewire::test(ImagePickerFieldActionsHarness::class)
        ->set('data.hero_image_id', $value->id)
        ->callFormComponentAction('hero_image_id', 'updateFocus', arguments: [
            'conversion' => $conversion->id, 'x' => 0.25, 'y' => 0.75,
        ])
        ->assertHasNoFormComponentActionErrors();

    $conversion->refresh();
    expect($conversion->focus_x)->toBe(0.25)
        ->and($conversion->focus_y)->toBe(0.75)
        ->and($conversion->status)->toBe(ConversionStatus::Pending);
    Queue::assertPushed(GenerateConversion::class);
});

it('re-dispatches generation via the retryConversion field action', function () {
    Queue::fake();
    $value = MediaFieldValue::factory()->for(Media::factory(), 'source')->create();
    $conversion = MediaConversion::factory()->for($value, 'value')->create([
        'status' => ConversionStatus::Failed,
    ]);

    Livewire::test(ImagePickerFieldActionsHarness::class)
        ->set('data.hero_image_id', $value->id)
        ->callFormComponentAction('hero_image_id', 'retryConversion', arguments: [
            'conversion' => $conversion->id,
        ])
        ->assertHasNoFormComponentActionErrors();

    expect($conversion->refresh()->status)->toBe(ConversionStatus::Pending);
    Queue::assertPushed(GenerateConversion::class);
});

it('refuses to update focus on a conversion belonging to another field value', function () {
    Queue::fake();
    $ownValue = MediaFieldValue::factory()->for(Media::factory(), 'source')->create();
    $foreignValue = MediaFieldValue::factory()->for(Media::factory(), 'source')->create();
    $foreignConversion = MediaConversion::factory()->for($foreignValue, 'value')->create([
        'status' => ConversionStatus::Completed, 'focus_x' => 0.5, 'focus_y' => 0.5,
    ]);

    Livewire::test(ImagePickerFieldActionsHarness::class)
        ->set('data.hero_image_id', $ownValue->id)
        ->callFormComponentAction('hero_image_id', 'updateFocus', arguments: [
            'conversion' => $foreignConversion->id, 'x' => 0.25, 'y' => 0.75,
        ])
        ->assertHasNoFormComponentActionErrors();

    $foreignConversion->refresh();
    expect($foreignConversion->focus_x)->toBe(0.5)
        ->and($foreignConversion->focus_y)->toBe(0.5)
        ->and($foreignConversion->status)->toBe(ConversionStatus::Completed);
    Queue::assertNotPushed(GenerateConversion::class);
});

it('refuses to retry a conversion belonging to another field value', function () {
    Queue::fake();
    $ownValue = MediaFieldValue::factory()->for(Media::factory(), 'source')->create();
    $foreignValue = MediaFieldValue::factory()->for(Media::factory(), 'source')->create();
    $foreignConversion = MediaConversion::factory()->for($foreignValue, 'value')->create([
        'status' => ConversionStatus::Completed,
    ]);

    Livewire::test(ImagePickerFieldActionsHarness::class)
        ->set('data.hero_image_id', $ownValue->id)
        ->callFormComponentAction('hero_image_id', 'retryConversion', arguments: [
            'conversion' => $foreignConversion->id,
        ])
        ->assertHasNoFormComponentActionErrors();

    expect($foreignConversion->refresh()->status)->toBe(ConversionStatus::Completed);
    Queue::assertNotPushed(GenerateConversion::class);
});

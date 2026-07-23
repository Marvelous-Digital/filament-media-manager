<?php

namespace MarvelousDigital\FilamentMediaManager\Forms\Components;

use Filament\Actions\Action;
use Filament\Forms\Components\Field;
use MarvelousDigital\FilamentMediaManager\Actions\UpdateConversionFocus;
use MarvelousDigital\FilamentMediaManager\Enums\ConversionStatus;
use MarvelousDigital\FilamentMediaManager\Jobs\GenerateConversion;
use MarvelousDigital\FilamentMediaManager\Models\MediaConversion;
use MarvelousDigital\FilamentMediaManager\Models\MediaFieldValue;

class ImagePicker extends Field
{
    protected string $view = 'filament-media-manager::forms.components.image-picker';

    /** @var array<string, array{0: int, 1: int}> */
    protected array $variations = [];

    protected ?string $pickerDisk = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dehydrated(true);

        $this->registerActions([
            fn (): Action => Action::make('updateAlt')
                ->action(function (array $arguments): void {
                    $value = MediaFieldValue::find($this->getState());

                    $value?->update(['alt_text' => $arguments['alt'] ?? null]);
                }),
            fn (): Action => Action::make('updateFocus')
                ->action(function (array $arguments): void {
                    $conversion = MediaConversion::find((int) ($arguments['conversion'] ?? 0));

                    if ($conversion === null) {
                        return;
                    }

                    app(UpdateConversionFocus::class)->execute(
                        $conversion,
                        (float) ($arguments['x'] ?? 0),
                        (float) ($arguments['y'] ?? 0),
                    );
                }),
            fn (): Action => Action::make('retryConversion')
                ->action(function (array $arguments): void {
                    $conversion = MediaConversion::find((int) ($arguments['conversion'] ?? 0));

                    if ($conversion === null) {
                        return;
                    }

                    $conversion->update(['status' => ConversionStatus::Pending]);

                    GenerateConversion::dispatchFor($conversion);
                }),
        ]);
    }

    /**
     * @param  array<string, array{0: int, 1: int}>  $variations
     */
    public function variations(array $variations): static
    {
        $this->variations = $variations;

        return $this;
    }

    public function disk(string $disk): static
    {
        $this->pickerDisk = $disk;

        return $this;
    }

    /**
     * @return array<string, array{0: int, 1: int}>
     */
    public function getVariations(): array
    {
        return $this->variations !== [] ? $this->variations : ['default' => [1200, 1200]];
    }

    public function getDisk(): string
    {
        return $this->pickerDisk ?? config('filament-media-manager.disk');
    }
}

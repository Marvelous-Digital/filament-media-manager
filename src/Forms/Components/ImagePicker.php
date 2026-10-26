<?php

namespace MarvelousDigital\FilamentMediaManager\Forms\Components;

use Closure;
use Filament\Actions\Action;
use Filament\Forms\Components\Concerns\HasFileAttachments;
use Filament\Forms\Components\Field;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Component;
use Filament\Support\Components\Attributes\ExposedLivewireMethod;
use Filament\Support\Enums\Width;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\ValidationException;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use MarvelousDigital\FilamentMediaManager\Actions\PersistImagePicker;
use MarvelousDigital\FilamentMediaManager\Actions\UploadMedia;
use MarvelousDigital\FilamentMediaManager\Enums\ConversionStatus;
use MarvelousDigital\FilamentMediaManager\Enums\MediaType;
use MarvelousDigital\FilamentMediaManager\Jobs\GenerateConversion;
use MarvelousDigital\FilamentMediaManager\Models\Media;
use MarvelousDigital\FilamentMediaManager\Models\MediaConversion;
use MarvelousDigital\FilamentMediaManager\Models\MediaFieldValue;
use MarvelousDigital\FilamentMediaManager\Support\ImagePickerState;

class ImagePicker extends Field
{
    use HasFileAttachments;

    protected string $view = 'filament-media-manager::forms.components.image-picker';

    /** @var array<string, array{0: int, 1: int}> */
    protected array $variations = [];

    protected ?string $pickerDisk = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->afterStateHydrated(static function (ImagePicker $component, mixed $state): void {
            $component->state(ImagePickerState::fromStoredValue($state, $component->getVariations()));
        });

        $this->dehydrateStateUsing(static function (ImagePicker $component, mixed $state): ?int {
            $id = app(PersistImagePicker::class)->execute($state, $component->getVariations());

            // Filament does not refill forms after saving; write the persisted state back so a
            // later save updates this row instead of creating another.
            $component->state(ImagePickerState::fromStoredValue($id, $component->getVariations()));

            return $id;
        });

        $this->rule(static fn (ImagePicker $component): Closure => static function (string $attribute, mixed $value, Closure $fail) use ($component): void {
            if (! is_array($value)) {
                return;
            }

            if (blank($value['media_id'] ?? null)) {
                if ($component->isRequired()) {
                    $fail(__('validation.required', ['attribute' => $component->getValidationAttribute()]));
                }

                return;
            }

            $mediaIds = array_unique(array_filter([
                $value['media_id'],
                ...array_column($value['variants'] ?? [], 'media_id'),
            ]));

            if (Media::query()->whereKey($mediaIds)->count() !== count($mediaIds)) {
                $fail(__('This image is no longer in the library.'));
            }
        });

        $this->registerActions([
            fn (): Action => Action::make('select')
                ->modalHeading(__('Select an image'))
                ->modalWidth(Width::FiveExtraLarge)
                ->modalContent(fn (array $arguments): View => view('filament-media-manager::forms.components.image-picker-modal', [
                    'statePath' => $arguments['statePath'] ?? '',
                    'target' => $arguments['target'] ?? 'main',
                ]))
                ->modalSubmitAction(false)
                ->modalCancelAction(false),
            fn (): Action => Action::make('retryConversion')
                ->action(function (array $arguments, Component $component): void {
                    $conversion = MediaConversion::find((int) ($arguments['conversion'] ?? 0));
                    $valueId = (int) ($component->getState()['id'] ?? 0);

                    if (! $conversion || $valueId === 0 || $conversion->media_field_value_id !== $valueId) {
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

    #[ExposedLivewireMethod]
    public function selectImage(string $target, int $mediaId): void
    {
        $media = Media::query()->whereKey($mediaId)->where('type', MediaType::Image)->first();

        if (! $media) {
            Notification::make()->title(__('This image is no longer in the library.'))->danger()->send();

            return;
        }

        $this->applyMedia($target, $media);
    }

    #[ExposedLivewireMethod]
    public function storeUploadedImage(string $target): void
    {
        $file = $this->getUploadedFileAttachment();

        try {
            if (! $file instanceof TemporaryUploadedFile) {
                Notification::make()->title(__('Upload failed'))->body(__('The file could not be uploaded. Please try again.'))->danger()->send();

                return;
            }

            $media = app(UploadMedia::class)->execute($file);
        } catch (ValidationException $exception) {
            Notification::make()
                ->title(__('Upload failed'))
                ->body(implode(' ', collect($exception->errors())->flatten()->all()))
                ->danger()
                ->send();

            return;
        } finally {
            $livewire = $this->getLivewire();
            data_set($livewire, "componentFileAttachments.{$this->getStatePath()}", null);
        }

        $this->applyMedia($target, $media);
    }

    #[ExposedLivewireMethod]
    public function useMainImage(string $variation): void
    {
        $state = $this->getState();

        if (is_array($state)) {
            $this->state(ImagePickerState::withoutVariantImage($state, $variation));
        }
    }

    #[ExposedLivewireMethod]
    public function removeImage(): void
    {
        $state = $this->getState();

        $this->state(ImagePickerState::withoutImage(is_array($state) ? $state : null));
    }

    /**
     * The generic attachment endpoint from HasFileAttachments is not used by this field;
     * overriding it without the ExposedLivewireMethod attribute keeps it off the wire.
     */
    public function saveUploadedFileAttachmentAndGetUrl(): ?string
    {
        return null;
    }

    public function getSavedValue(): ?MediaFieldValue
    {
        $id = $this->getState()['id'] ?? null;

        return $id ? MediaFieldValue::query()->with('conversions')->find($id) : null;
    }

    /**
     * @return array<int, array{url: string|null, width: int|null, height: int|null}>
     */
    public function getMediaPayload(): array
    {
        $state = $this->getState();

        if (! is_array($state)) {
            return [];
        }

        $mediaIds = array_filter([$state['media_id'] ?? null, ...array_column($state['variants'] ?? [], 'media_id')]);

        return Media::query()
            ->whereKey($mediaIds)
            ->get()
            ->mapWithKeys(fn (Media $media): array => [$media->id => [
                'url' => $media->url(),
                'width' => $media->width,
                'height' => $media->height,
            ]])
            ->all();
    }

    protected function applyMedia(string $target, Media $media): void
    {
        $media->ensureDimensions();

        $state = $this->getState();

        $this->state($target === 'main' || ! is_array($state)
            ? ImagePickerState::withMainImage(is_array($state) ? $state : null, $media, $this->getVariations())
            : ImagePickerState::withVariantImage($state, $target, $media));
    }
}

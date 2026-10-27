<?php

namespace MarvelousDigital\FilamentMediaManager\Forms\Components;

use Closure;
use Filament\Actions\Action;
use Filament\Forms\Components\Concerns\HasFileAttachments;
use Filament\Forms\Components\Field;
use Filament\Support\Components\Attributes\ExposedLivewireMethod;
use Filament\Support\Enums\Width;
use Illuminate\Contracts\View\View;
use MarvelousDigital\FilamentMediaManager\Actions\PersistImagesPicker;
use MarvelousDigital\FilamentMediaManager\Forms\Components\Concerns\HandlesPickedImages;
use MarvelousDigital\FilamentMediaManager\Models\Media;
use MarvelousDigital\FilamentMediaManager\Support\ImagesPickerState;

/**
 * Several images in a fixed order, each with its own alt text. Stored as a list of
 * MediaFieldValue ids.
 *
 * @phpstan-import-type State from ImagesPickerState
 */
class ImagesPicker extends Field
{
    use HandlesPickedImages;
    use HasFileAttachments;

    protected string $view = 'filament-media-manager::forms.components.images-picker';

    /** @var array<string, array{0: int, 1: int}> */
    protected array $variations = [];

    protected ?string $pickerDisk = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->afterStateHydrated(static function (ImagesPicker $component, mixed $state): void {
            $component->state(ImagesPickerState::fromStoredValue($state));
        });

        $this->dehydrateStateUsing(static function (ImagesPicker $component, mixed $state): array {
            $ids = app(PersistImagesPicker::class)->execute($state, $component->getVariations());

            // Filament does not refill forms after saving; write the persisted state back so a
            // later save updates these rows instead of creating more.
            $component->state(ImagesPickerState::fromStoredValue($ids));

            return $ids;
        });

        $this->rule(static fn (ImagesPicker $component): Closure => static function (string $attribute, mixed $value, Closure $fail) use ($component): void {
            if (! is_array($value) || ! is_array($value['items'] ?? null)) {
                return;
            }

            if ($value['items'] === []) {
                if ($component->isRequired()) {
                    $fail(__('validation.required', ['attribute' => $component->getValidationAttribute()]));
                }

                return;
            }

            $mediaIds = array_unique(array_filter(array_column($value['items'], 'media_id')));

            if (Media::query()->whereKey($mediaIds)->count() !== count($mediaIds)) {
                $fail(__('This image is no longer in the library.'));
            }
        });

        $this->registerActions([
            fn (): Action => Action::make('select')
                ->modalHeading(__('Add images'))
                ->modalWidth(Width::FiveExtraLarge)
                ->modalContent(fn (array $arguments): View => view('filament-media-manager::forms.components.image-picker-modal', [
                    'statePath' => $arguments['statePath'] ?? '',
                    'target' => 'append',
                ]))
                ->modalSubmitAction(false)
                ->modalCancelActionLabel(__('Done')),
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
        return $this->variations;
    }

    public function getDisk(): string
    {
        return $this->pickerDisk ?? config('filament-media-manager.disk');
    }

    #[ExposedLivewireMethod]
    public function selectImage(string $target, int $mediaId): void
    {
        $media = $this->imageOrNotify($mediaId);

        if ($media) {
            $this->appendMedia($media);
        }
    }

    #[ExposedLivewireMethod]
    public function storeUploadedImage(string $target): void
    {
        $media = $this->uploadedMediaOrNotify();

        if ($media) {
            $this->appendMedia($media);
        }
    }

    #[ExposedLivewireMethod]
    public function removeImage(string $key): void
    {
        $this->state(ImagesPickerState::withoutImage($this->getPickerState(), $key));
    }

    /**
     * @param  array<int, mixed>  $keys
     */
    #[ExposedLivewireMethod]
    public function reorderImages(array $keys): void
    {
        $keys = array_values(array_map(strval(...), array_filter($keys, is_scalar(...))));

        $this->state(ImagesPickerState::reordered($this->getPickerState(), $keys));
    }

    /**
     * The generic attachment endpoint from HasFileAttachments is not used by this field;
     * overriding it without the ExposedLivewireMethod attribute keeps it off the wire.
     */
    public function saveUploadedFileAttachmentAndGetUrl(): ?string
    {
        return null;
    }

    /**
     * @return array<int, string|null>
     */
    public function getThumbnails(): array
    {
        $mediaIds = array_unique(array_column($this->getPickerState()['items'], 'media_id'));

        return Media::query()
            ->whereKey($mediaIds)
            ->get()
            ->mapWithKeys(fn (Media $media): array => [$media->id => $media->thumbnailUrl() ?? $media->url()])
            ->all();
    }

    /**
     * @return State
     */
    protected function getPickerState(): array
    {
        return ImagesPickerState::fromStoredValue($this->getState());
    }

    protected function appendMedia(Media $media): void
    {
        $media->ensureDimensions();

        $this->state(ImagesPickerState::withImage($this->getPickerState(), $media));
    }
}

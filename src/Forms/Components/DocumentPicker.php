<?php

namespace MarvelousDigital\FilamentMediaManager\Forms\Components;

use Closure;
use Filament\Actions\Action;
use Filament\Forms\Components\Field;
use Filament\Support\Components\Attributes\ExposedLivewireMethod;
use Filament\Support\Enums\Width;
use Illuminate\Contracts\View\View;
use MarvelousDigital\FilamentMediaManager\Models\Media;

/**
 * Picks one file (of the given mime types) from the media library. The state is the Media id:
 * no alt text, crops or variations, and nothing is written to the field value tables.
 */
class DocumentPicker extends Field
{
    protected string $view = 'filament-media-manager::forms.components.document-picker';

    /** @var array<int, string> */
    protected array $mimeTypes = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->rule(static fn (DocumentPicker $component): Closure => static function (string $attribute, mixed $value, Closure $fail) use ($component): void {
            if (blank($value)) {
                return;
            }

            $media = is_numeric($value) ? Media::query()->find((int) $value) : null;

            if (! $media || ! in_array($media->mime_type, $component->getMimeTypes(), true)) {
                $fail(__('filament-media-manager::media.picker.file_missing'));
            }
        });

        $this->registerActions([
            fn (DocumentPicker $component): Action => Action::make('select')
                ->modalHeading(__('filament-media-manager::media.picker.select_file'))
                ->modalWidth(Width::FiveExtraLarge)
                ->modalContent(fn (array $arguments): View => view('filament-media-manager::forms.components.image-picker-modal', [
                    'statePath' => $arguments['statePath'] ?? '',
                    'target' => 'main',
                    'mimeTypes' => $component->getMimeTypes(),
                ]))
                ->modalSubmitAction(false)
                ->modalCancelAction(false),
        ]);
    }

    /**
     * @param  array<int, string>  $mimeTypes
     */
    public function mimeTypes(array $mimeTypes): static
    {
        $this->mimeTypes = array_values($mimeTypes);

        return $this;
    }

    /**
     * @return array<int, string>
     */
    public function getMimeTypes(): array
    {
        return $this->mimeTypes;
    }

    public function getSelectedMedia(): ?Media
    {
        $state = $this->getState();

        return is_numeric($state) ? Media::query()->find((int) $state) : null;
    }

    #[ExposedLivewireMethod]
    public function selectDocument(int $mediaId): void
    {
        $allowed = $this->getMimeTypes();

        if ($allowed !== [] && Media::query()->whereKey($mediaId)->whereIn('mime_type', $allowed)->exists()) {
            $this->state($mediaId);
        }
    }

    #[ExposedLivewireMethod]
    public function removeDocument(): void
    {
        $this->state(null);
    }
}

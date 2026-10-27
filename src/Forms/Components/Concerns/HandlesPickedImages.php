<?php

namespace MarvelousDigital\FilamentMediaManager\Forms\Components\Concerns;

use Filament\Notifications\Notification;
use Illuminate\Validation\ValidationException;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use MarvelousDigital\FilamentMediaManager\Actions\UploadMedia;
use MarvelousDigital\FilamentMediaManager\Enums\MediaType;
use MarvelousDigital\FilamentMediaManager\Models\Media;

/**
 * Library lookup and direct upload shared by the image picker fields. Both notify the editor
 * and return null when there is no usable image. Requires HasFileAttachments.
 */
trait HandlesPickedImages
{
    protected function imageOrNotify(int $mediaId): ?Media
    {
        $media = Media::query()->whereKey($mediaId)->where('type', MediaType::Image)->first();

        if (! $media) {
            Notification::make()->title(__('This image is no longer in the library.'))->danger()->send();
        }

        return $media;
    }

    protected function uploadedMediaOrNotify(): ?Media
    {
        $file = $this->getUploadedFileAttachment();

        try {
            if (! $file instanceof TemporaryUploadedFile) {
                Notification::make()->title(__('Upload failed'))->body(__('The file could not be uploaded. Please try again.'))->danger()->send();

                return null;
            }

            return app(UploadMedia::class)->execute($file);
        } catch (ValidationException $exception) {
            Notification::make()
                ->title(__('Upload failed'))
                ->body(implode(' ', collect($exception->errors())->flatten()->all()))
                ->danger()
                ->send();

            return null;
        } finally {
            $livewire = $this->getLivewire();
            data_set($livewire, "componentFileAttachments.{$this->getStatePath()}", null);
        }
    }
}

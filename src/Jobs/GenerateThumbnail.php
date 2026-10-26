<?php

namespace MarvelousDigital\FilamentMediaManager\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Image;
use MarvelousDigital\FilamentMediaManager\Enums\MediaType;
use MarvelousDigital\FilamentMediaManager\Enums\ThumbnailStatus;
use MarvelousDigital\FilamentMediaManager\Models\Media;
use Throwable;

class GenerateThumbnail implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function __construct(public Media $media) {}

    public static function dispatchFor(Media $media): void
    {
        if (config('filament-media-manager.thumbnail.queue')) {
            static::dispatch($media)->onQueue(config('filament-media-manager.thumbnail.queue_name'));
        } else {
            static::dispatchSync($media);
        }
    }

    public function handle(): void
    {
        if ($this->media->type !== MediaType::Image || $this->media->mime_type === 'image/svg+xml') {
            $this->media->update(['thumbnail_status' => ThumbnailStatus::Skipped]);

            return;
        }

        try {
            $image = Image::fromStorage($this->media->path, $this->media->disk)->orient();

            [$width, $height] = $image->dimensions();

            $format = config('filament-media-manager.thumbnail.format', 'webp');
            $thumbnailName = 'thumb.' . $format;

            $path = $image
                ->scale(width: config('filament-media-manager.thumbnail.width', 600))
                ->optimize(format: $format, quality: config('filament-media-manager.thumbnail.quality', 70))
                ->storeAs(
                    path: $this->media->directory(),
                    name: $thumbnailName,
                    disk: $this->media->disk,
                );

            if ($path === false) {
                throw new \RuntimeException("Could not store thumbnail for media [{$this->media->id}].");
            }

            $this->media->update([
                'thumbnail_path' => $path,
                'thumbnail_status' => ThumbnailStatus::Completed,
                'width' => $width,
                'height' => $height,
            ]);
        } catch (Throwable $exception) {
            $this->media->update(['thumbnail_status' => ThumbnailStatus::Failed]);

            report($exception);
        }
    }
}

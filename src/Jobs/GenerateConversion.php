<?php

namespace MarvelousDigital\FilamentMediaManager\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Image;
use MarvelousDigital\FilamentMediaManager\Enums\ConversionStatus;
use MarvelousDigital\FilamentMediaManager\Models\MediaConversion;
use MarvelousDigital\FilamentMediaManager\Support\CropBox;
use RuntimeException;
use Throwable;

class GenerateConversion implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function __construct(public MediaConversion $conversion) {}

    public static function dispatchFor(MediaConversion $conversion): void
    {
        if (config('filament-media-manager.thumbnail.queue')) {
            static::dispatch($conversion)->onQueue(config('filament-media-manager.thumbnail.queue_name'));
        } else {
            static::dispatchSync($conversion);
        }
    }

    public function handle(): void
    {
        $source = $this->conversion->value->source;

        try {
            $image = Image::fromStorage($source->path, $source->disk)->orient();
            [$sw, $sh] = $image->dimensions();

            $box = CropBox::for(
                $sw,
                $sh,
                $this->conversion->width,
                $this->conversion->height,
                $this->conversion->focus_x,
                $this->conversion->focus_y,
            );

            $format = config('filament-media-manager.thumbnail.format', 'webp');

            $path = $image
                ->crop($box['width'], $box['height'], $box['x'], $box['y'])
                ->resize($this->conversion->width, $this->conversion->height)
                ->optimize(format: $format, quality: config('filament-media-manager.thumbnail.quality', 70))
                ->storeAs(
                    path: $this->conversion->value->directory(),
                    name: $this->conversion->variation . '.' . $format,
                    disk: $source->disk,
                );

            if ($path === false) {
                throw new RuntimeException("Could not store conversion [{$this->conversion->id}].");
            }

            $this->conversion->update(['path' => $path, 'status' => ConversionStatus::Completed]);
        } catch (Throwable $exception) {
            $this->conversion->update(['status' => ConversionStatus::Failed]);
            report($exception);
        }
    }
}

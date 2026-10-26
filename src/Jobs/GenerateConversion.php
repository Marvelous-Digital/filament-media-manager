<?php

namespace MarvelousDigital\FilamentMediaManager\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Image;
use Illuminate\Support\Facades\Storage;
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

    public bool $deleteWhenMissingModels = true;

    public function __construct(public MediaConversion $conversion) {}

    /**
     * Dispatch after the surrounding transaction commits so a rolled-back form save never
     * generates files. Outside a transaction this runs immediately.
     */
    public static function dispatchFor(MediaConversion $conversion): void
    {
        if (config('filament-media-manager.thumbnail.queue')) {
            static::dispatch($conversion)
                ->onQueue(config('filament-media-manager.thumbnail.queue_name'))
                ->afterCommit();

            return;
        }

        DB::afterCommit(fn () => static::dispatchSync($conversion));
    }

    public function handle(): void
    {
        $conversion = $this->conversion->fresh(['value.source', 'source']);

        if ($conversion === null) {
            return;
        }

        $source = $conversion->effectiveSource();
        $disk = $conversion->value->source->disk;

        try {
            $image = Image::fromStorage($source->path, $source->disk)->orient();
            [$sw, $sh] = $image->dimensions();

            $box = CropBox::for(
                $sw,
                $sh,
                $conversion->width,
                $conversion->height,
                $conversion->focus_x,
                $conversion->focus_y,
                $conversion->zoom,
            );

            $format = config('filament-media-manager.thumbnail.format', 'webp');

            $path = $image
                ->crop($box['width'], $box['height'], $box['x'], $box['y'])
                ->resize($conversion->width, $conversion->height)
                ->optimize(format: $format, quality: config('filament-media-manager.thumbnail.quality', 70))
                ->storeAs(
                    path: $conversion->value->directory(),
                    name: $conversion->variation . '-' . $conversion->fingerprint() . '.' . $format,
                    disk: $disk,
                );

            if ($path === false) {
                throw new RuntimeException("Could not store conversion [{$conversion->id}].");
            }

            $previousPath = $conversion->path;

            $conversion->update(['path' => $path, 'status' => ConversionStatus::Completed]);

            if ($previousPath !== null && $previousPath !== $path) {
                Storage::disk($disk)->delete($previousPath);
            }
        } catch (Throwable $exception) {
            $conversion->update(['status' => ConversionStatus::Failed]);
            report($exception);
        }
    }
}

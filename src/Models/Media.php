<?php

namespace MarvelousDigital\FilamentMediaManager\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use MarvelousDigital\FilamentMediaManager\Enums\ConversionStatus;
use MarvelousDigital\FilamentMediaManager\Enums\MediaType;
use MarvelousDigital\FilamentMediaManager\Enums\ThumbnailStatus;
use MarvelousDigital\FilamentMediaManager\Jobs\GenerateConversion;
use Throwable;

/**
 * @property int $id
 * @property int|null $folder_id
 * @property string $name
 * @property string $file_name
 * @property string $disk
 * @property string $path
 * @property string $mime_type
 * @property MediaType $type
 * @property int $size
 * @property int|null $width
 * @property int|null $height
 * @property string|null $alt_text
 * @property string|null $thumbnail_path
 * @property ThumbnailStatus $thumbnail_status
 * @property int|null $created_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read MediaFolder|null $folder
 */
class Media extends Model
{
    use HasFactory;

    protected $fillable = [
        'folder_id', 'name', 'file_name', 'disk', 'path', 'mime_type', 'type',
        'size', 'width', 'height', 'alt_text', 'thumbnail_path', 'thumbnail_status', 'created_by',
    ];

    public function getTable(): string
    {
        return config('filament-media-manager.table_names.media', 'media_manager_media');
    }

    protected function casts(): array
    {
        return [
            'type' => MediaType::class,
            'thumbnail_status' => ThumbnailStatus::class,
            'size' => 'integer',
            'width' => 'integer',
            'height' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::deleting(function (self $media): void {
            MediaConversion::query()
                ->where('media_id', $media->id)
                ->each(function (MediaConversion $conversion): void {
                    $conversion->update([
                        'media_id' => null,
                        'alt_text' => null,
                        'status' => ConversionStatus::Pending,
                    ]);

                    GenerateConversion::dispatchFor($conversion);
                });
        });

        static::deleted(function (self $media): void {
            try {
                Storage::disk($media->disk)->deleteDirectory($media->directory());
            } catch (Throwable) {
                // File cleanup is best-effort; never block row deletion.
            }
        });
    }

    public function folder(): BelongsTo
    {
        return $this->belongsTo(MediaFolder::class, 'folder_id');
    }

    public function directory(): string
    {
        return dirname($this->path);
    }

    public function url(): ?string
    {
        return $this->resolveUrl($this->path);
    }

    public function thumbnailUrl(): ?string
    {
        if ($this->mime_type === 'image/svg+xml') {
            return $this->url();
        }

        return $this->thumbnail_path ? $this->resolveUrl($this->thumbnail_path) : null;
    }

    public function resolvePublicUrl(string $path): ?string
    {
        return $this->resolveUrl($path);
    }

    protected function resolveUrl(string $path): ?string
    {
        $disk = Storage::disk($this->disk);

        try {
            return $disk->url($path);
        } catch (Throwable) {
            // Disk has no public URL support; fall through.
        }

        try {
            return $disk->temporaryUrl($path, now()->addMinutes(30));
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Measure and store width/height when they are unknown (e.g. uploads that predate
     * upload-time measuring). Silently does nothing for unreadable or non-image files.
     */
    public function ensureDimensions(): void
    {
        if (($this->width && $this->height) || $this->type !== MediaType::Image) {
            return;
        }

        try {
            $contents = Storage::disk($this->disk)->get($this->path);
            $size = is_string($contents) && $contents !== '' ? getimagesizefromstring($contents) : false;
        } catch (Throwable) {
            return;
        }

        if ($size === false) {
            return;
        }

        $this->update(['width' => $size[0], 'height' => $size[1]]);
    }
}

<?php

namespace MarvelousDigital\FilamentMediaManager\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;
use MarvelousDigital\FilamentMediaManager\Enums\MediaType;
use MarvelousDigital\FilamentMediaManager\Enums\ThumbnailStatus;
use Throwable;

class Media extends Model
{
    use HasFactory;

    protected $table = 'media';

    protected $fillable = [
        'folder_id', 'name', 'file_name', 'disk', 'path', 'mime_type', 'type',
        'size', 'width', 'height', 'alt_text', 'thumbnail_path', 'thumbnail_status', 'created_by',
    ];

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
}

<?php

namespace MarvelousDigital\FilamentMediaManager\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use MarvelousDigital\FilamentMediaManager\Enums\ConversionStatus;
use MarvelousDigital\FilamentMediaManager\Support\CropBox;
use Throwable;

/**
 * @property int $id
 * @property int $media_field_value_id
 * @property string $variation
 * @property int $width
 * @property int $height
 * @property float $focus_x
 * @property float $focus_y
 * @property float $zoom
 * @property string|null $path
 * @property ConversionStatus $status
 * @property int|null $media_id
 * @property string|null $alt_text
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read MediaFieldValue $value
 * @property-read Media|null $source
 */
class MediaConversion extends Model
{
    use HasFactory;

    protected $fillable = [
        'media_field_value_id', 'variation', 'width', 'height',
        'focus_x', 'focus_y', 'zoom', 'path', 'status', 'media_id', 'alt_text',
    ];

    public function getTable(): string
    {
        return config('filament-media-manager.table_names.conversions', 'media_manager_conversions');
    }

    protected function casts(): array
    {
        return [
            'status' => ConversionStatus::class,
            'width' => 'integer',
            'height' => 'integer',
            'focus_x' => 'float',
            'focus_y' => 'float',
            'zoom' => 'float',
            'media_id' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::deleted(function (self $conversion): void {
            if ($conversion->path === null) {
                return;
            }

            try {
                Storage::disk($conversion->value->source->disk)->delete($conversion->path);
            } catch (Throwable) {
                // Best-effort cleanup; never block row deletion.
            }
        });
    }

    public function value(): BelongsTo
    {
        return $this->belongsTo(MediaFieldValue::class, 'media_field_value_id');
    }

    public function source(): BelongsTo
    {
        return $this->belongsTo(Media::class, 'media_id');
    }

    public function effectiveSource(): Media
    {
        return $this->source ?? $this->value->source;
    }

    public function effectiveAltText(): ?string
    {
        if ($this->media_id !== null && filled($this->alt_text)) {
            return $this->alt_text;
        }

        return $this->value->alt_text;
    }

    /**
     * Short hash of everything that shapes the generated file; used to version file names.
     */
    public function fingerprint(): string
    {
        return substr(sha1(implode('|', [
            $this->effectiveSource()->id,
            $this->width,
            $this->height,
            $this->focus_x,
            $this->focus_y,
            $this->zoom,
        ])), 0, 8);
    }

    /**
     * The zoom level past which the cropped source region is smaller than the output,
     * i.e. where further zooming upscales and loses quality. Null when source dimensions are unknown.
     */
    public function maxSafeZoom(): ?float
    {
        $source = $this->effectiveSource();
        $sourceW = $source->width;
        $sourceH = $source->height;

        if (! $sourceW || ! $sourceH) {
            return null;
        }

        $cover = CropBox::coverSize($sourceW, $sourceH, $this->width, $this->height);

        return $cover['width'] / $this->width;
    }

    public function url(): ?string
    {
        if ($this->path === null) {
            return null;
        }

        $url = $this->value->source->resolvePublicUrl($this->path);

        if ($url === null) {
            return null;
        }

        // Cache-bust: conversions regenerate in place at the same path, so tie the URL to
        // updated_at to force browsers and CDNs to refetch after a focus or zoom change.
        $separator = str_contains($url, '?') ? '&' : '?';

        return $url . $separator . 'v=' . $this->updated_at?->timestamp;
    }
}

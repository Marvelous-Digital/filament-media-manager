<?php

namespace MarvelousDigital\FilamentMediaManager\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use MarvelousDigital\FilamentMediaManager\Enums\ConversionStatus;
use MarvelousDigital\FilamentMediaManager\Support\CropBox;

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
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read MediaFieldValue $value
 */
class MediaConversion extends Model
{
    use HasFactory;

    protected $fillable = [
        'media_field_value_id', 'variation', 'width', 'height',
        'focus_x', 'focus_y', 'zoom', 'path', 'status',
    ];

    protected function casts(): array
    {
        return [
            'status' => ConversionStatus::class,
            'width' => 'integer',
            'height' => 'integer',
            'focus_x' => 'float',
            'focus_y' => 'float',
            'zoom' => 'float',
        ];
    }

    public function value(): BelongsTo
    {
        return $this->belongsTo(MediaFieldValue::class, 'media_field_value_id');
    }

    /**
     * The zoom level past which the cropped source region is smaller than the output,
     * i.e. where further zooming upscales and loses quality. Null when source dimensions are unknown.
     */
    public function maxSafeZoom(): ?float
    {
        $sourceW = $this->value->source->width;
        $sourceH = $this->value->source->height;

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

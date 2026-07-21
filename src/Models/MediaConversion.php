<?php

namespace MarvelousDigital\FilamentMediaManager\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use MarvelousDigital\FilamentMediaManager\Enums\ConversionStatus;

/**
 * @property int $id
 * @property int $media_field_value_id
 * @property string $variation
 * @property int $width
 * @property int $height
 * @property float $focus_x
 * @property float $focus_y
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
        'focus_x', 'focus_y', 'path', 'status',
    ];

    protected function casts(): array
    {
        return [
            'status' => ConversionStatus::class,
            'width' => 'integer',
            'height' => 'integer',
            'focus_x' => 'float',
            'focus_y' => 'float',
        ];
    }

    public function value(): BelongsTo
    {
        return $this->belongsTo(MediaFieldValue::class, 'media_field_value_id');
    }

    public function url(): ?string
    {
        if ($this->path === null) {
            return null;
        }

        return $this->value->source->resolvePublicUrl($this->path);
    }
}

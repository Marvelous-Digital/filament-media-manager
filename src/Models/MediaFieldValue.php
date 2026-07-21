<?php

namespace MarvelousDigital\FilamentMediaManager\Models;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * @property int $id
 * @property int $media_id
 * @property string|null $alt_text
 * @property int|null $created_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Media $source
 * @property-read Collection<int, MediaConversion> $conversions
 */
class MediaFieldValue extends Model
{
    use HasFactory;

    protected $fillable = ['media_id', 'alt_text', 'created_by'];

    protected static function booted(): void
    {
        static::deleted(function (self $value): void {
            try {
                Storage::disk($value->source->disk)->deleteDirectory($value->directory());
            } catch (Throwable) {
                // Best-effort cleanup; never block row deletion.
            }
        });
    }

    public function source(): BelongsTo
    {
        return $this->belongsTo(Media::class, 'media_id');
    }

    public function conversions(): HasMany
    {
        return $this->hasMany(MediaConversion::class);
    }

    public function conversion(string $variation): ?MediaConversion
    {
        return $this->conversions->firstWhere('variation', $variation);
    }

    public function url(): ?string
    {
        return $this->source->url();
    }

    public function directory(): string
    {
        return dirname($this->source->path) . '/conversions/' . $this->id;
    }
}

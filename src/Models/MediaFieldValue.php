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

    public function getTable(): string
    {
        return config('filament-media-manager.table_names.field_values', 'media_manager_field_values');
    }

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

    public function urlFor(?string $variation = null): ?string
    {
        if ($variation !== null) {
            $url = $this->conversion($variation)?->url();

            if ($url !== null) {
                return $url;
            }
        }

        return $this->url();
    }

    public function altFor(?string $variation = null): ?string
    {
        if ($variation !== null && ($conversion = $this->conversion($variation)) !== null) {
            return $conversion->effectiveAltText();
        }

        return $this->alt_text;
    }

    public function directory(): string
    {
        return trim(config('filament-media-manager.directory'), '/') . '/conversions/' . $this->id;
    }
}

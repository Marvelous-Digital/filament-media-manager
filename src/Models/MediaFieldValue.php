<?php

namespace MarvelousDigital\FilamentMediaManager\Models;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use MarvelousDigital\FilamentMediaManager\Events\AltCheckConfirmed;
use Throwable;

/**
 * @property int $id
 * @property int $media_id
 * @property string|null $alt_text
 * @property float $focus_x
 * @property float $focus_y
 * @property bool $decorative
 * @property bool $alt_check_required
 * @property int|null $created_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Media $source
 * @property-read Collection<int, MediaConversion> $conversions
 */
class MediaFieldValue extends Model
{
    use HasFactory;

    protected $fillable = ['media_id', 'alt_text', 'focus_x', 'focus_y', 'decorative', 'alt_check_required', 'created_by'];

    protected function casts(): array
    {
        return [
            'focus_x' => 'float',
            'focus_y' => 'float',
            'decorative' => 'boolean',
            'alt_check_required' => 'boolean',
        ];
    }

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

    /** A decorative image has an empty alt text, so screen readers skip it. */
    public function altFor(?string $variation = null): ?string
    {
        if ($this->decorative) {
            return '';
        }

        if ($variation !== null && ($conversion = $this->conversion($variation)) !== null) {
            return $conversion->effectiveAltText();
        }

        return $this->alt_text;
    }

    /** The editor checked the alt text after a replacement: clears the flag in place (metadata, not image content). */
    public function confirmAlt(): void
    {
        $this->alt_check_required = false;
        $this->saveQuietly();

        AltCheckConfirmed::dispatch($this);
    }

    public function directory(): string
    {
        return trim(config('filament-media-manager.directory'), '/') . '/conversions/' . $this->id;
    }
}

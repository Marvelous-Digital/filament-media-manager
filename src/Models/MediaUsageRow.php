<?php

namespace MarvelousDigital\FilamentMediaManager\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $media_id
 * @property int|null $field_value_id
 * @property string $owner_type
 * @property string $owner_id
 * @property string $source
 * @property int|null $revision_id
 * @property string|null $locale
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class MediaUsageRow extends Model
{
    protected $guarded = [];

    public function getTable(): string
    {
        return config('filament-media-manager.table_names.usages', 'media_manager_usages');
    }
}

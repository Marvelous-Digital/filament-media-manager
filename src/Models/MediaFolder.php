<?php

namespace MarvelousDigital\FilamentMediaManager\Models;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $name
 * @property int|null $parent_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read self|null $parent
 * @property-read Collection<int, self> $children
 * @property-read Collection<int, Media> $media
 */
class MediaFolder extends Model
{
    use HasFactory;

    protected $fillable = ['name', 'parent_id'];

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    public function media(): HasMany
    {
        return $this->hasMany(Media::class, 'folder_id');
    }

    public function isEmpty(): bool
    {
        return ! $this->children()->exists() && ! $this->media()->exists();
    }

    /**
     * Ancestor folders, root first, excluding this folder.
     *
     * @return array<int, self>
     */
    public function ancestors(): array
    {
        $ancestors = [];
        $current = $this->parent;

        while ($current !== null) {
            array_unshift($ancestors, $current);
            $current = $current->parent;
        }

        return $ancestors;
    }

    /**
     * All folders as a flat, depth-indented map for selects.
     *
     * @return array<int, string>
     */
    public static function treeOptions(): array
    {
        $byParent = static::query()
            ->orderBy('name')
            ->get()
            ->groupBy('parent_id');

        $options = [];

        $walk = function (?int $parentId, int $depth) use (&$walk, &$options, $byParent): void {
            foreach ($byParent->get($parentId ?? '', collect()) as $folder) {
                $options[$folder->id] = str_repeat('— ', $depth) . $folder->name;
                $walk($folder->id, $depth + 1);
            }
        };

        $walk(null, 0);

        return $options;
    }
}

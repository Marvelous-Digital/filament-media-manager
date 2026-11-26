<?php

namespace MarvelousDigital\FilamentMediaManager\Actions;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use InvalidArgumentException;
use MarvelousDigital\FilamentMediaManager\Enums\ConversionStatus;
use MarvelousDigital\FilamentMediaManager\Enums\MediaType;
use MarvelousDigital\FilamentMediaManager\Enums\ThumbnailStatus;
use MarvelousDigital\FilamentMediaManager\Events\MediaReplaced;
use MarvelousDigital\FilamentMediaManager\Jobs\GenerateConversion;
use MarvelousDigital\FilamentMediaManager\Jobs\GenerateThumbnail;
use MarvelousDigital\FilamentMediaManager\Models\Media;
use MarvelousDigital\FilamentMediaManager\Models\MediaConversion;
use MarvelousDigital\FilamentMediaManager\Models\MediaFieldValue;
use MarvelousDigital\FilamentMediaManager\Models\MediaUsageRow;
use Throwable;

/**
 * Replaces a library media once so every place that uses it changes. The one deliberate
 * exception to copy-on-write: placements are repointed in place, history included.
 */
class ReplaceMedia
{
    /**
     * Repoints every placement, crop override and usage row of $old to $new, then deletes $old
     * when nothing references it any more.
     *
     * @return int the number of affected placements
     */
    public function image(Media $old, Media $new): int
    {
        if ($old->is($new)) {
            throw new InvalidArgumentException('A media cannot replace itself.');
        }

        if ($old->type !== MediaType::Image || $new->type !== MediaType::Image) {
            throw new InvalidArgumentException('Only an image can replace an image.');
        }

        $count = DB::transaction(function () use ($old, $new): int {
            $valueIds = MediaFieldValue::query()->where('media_id', $old->id)->pluck('id')
                ->merge(MediaConversion::query()->where('media_id', $old->id)->pluck('media_field_value_id'))
                ->unique()
                ->values();

            MediaFieldValue::query()->where('media_id', $old->id)->update(['media_id' => $new->id]);
            MediaConversion::query()->where('media_id', $old->id)->update(['media_id' => $new->id]);

            MediaFieldValue::query()
                ->whereIn('id', $valueIds)
                ->where(fn ($query) => $query->where('decorative', false)->orWhereNull('decorative'))
                ->update(['alt_check_required' => true]);

            MediaConversion::query()
                ->whereIn('media_field_value_id', $valueIds)
                ->each(function (MediaConversion $conversion): void {
                    $conversion->update(['status' => ConversionStatus::Pending]);

                    GenerateConversion::dispatchFor($conversion);
                });

            $this->moveUsageRows($old, $new);

            return $valueIds->count();
        });

        // The replace committed: a failed cleanup of the old media must not undo it in the caller's eyes.
        try {
            if (! $this->isReferenced($old)) {
                $old->delete();
            }
        } catch (Throwable $e) {
            report($e);
        }

        MediaReplaced::dispatch($old->id, $new->id, $count);

        return $count;
    }

    /**
     * Swaps the file inside $old: same row, same id; name and folder are kept.
     */
    public function document(Media $old, UploadedFile $file): Media
    {
        if ($old->type === MediaType::Image) {
            throw new InvalidArgumentException('Images are replaced with another image.');
        }

        $mimeType = $file->getMimeType() ?? 'application/octet-stream';

        if (MediaType::fromMimeType($mimeType) === MediaType::Image) {
            throw new InvalidArgumentException('A document cannot be replaced with an image.');
        }

        $allowed = static::documentMimeTypes();

        if ($allowed !== null && ! in_array($mimeType, $allowed, true)) {
            throw new InvalidArgumentException(__('filament-media-manager::media.usage.replace_document_type', ['types' => implode(', ', $allowed)]));
        }

        $uploads = app(UploadMedia::class);
        $uploads->validate($file);

        $fileName = $uploads->sanitizeFileName($file->getClientOriginalName());
        $oldPath = $old->path;
        $oldThumbnail = $old->thumbnail_path;
        $path = $file->storeAs($old->directory(), Str::ulid() . '-' . $fileName, ['disk' => $old->disk]);

        try {
            $old->update([
                'file_name' => $fileName,
                'path' => $path,
                'mime_type' => $mimeType,
                'type' => MediaType::fromMimeType($mimeType),
                'size' => $file->getSize(),
                'width' => null,
                'height' => null,
                'thumbnail_path' => null,
                'thumbnail_status' => ThumbnailStatus::Pending,
            ]);
        } catch (Throwable $e) {
            Storage::disk($old->disk)->delete($path);

            throw $e;
        }

        GenerateThumbnail::dispatchFor($old);

        $disk = $old->disk;

        $mediaId = $old->id;

        DB::afterCommit(function () use ($disk, $oldPath, $oldThumbnail, $mediaId): void {
            try {
                Storage::disk($disk)->delete(array_filter([$oldPath, $oldThumbnail]));
            } catch (Throwable) {
                // Best-effort cleanup of the previous file.
            }

            MediaReplaced::dispatch($mediaId, $mediaId, 0);
        });

        return $old->refresh();
    }

    /**
     * The mime types a document may be replaced with, or null for any non-image file.
     *
     * @return list<string>|null
     */
    public static function documentMimeTypes(): ?array
    {
        $types = config('filament-media-manager.usage.document_mime_types');

        return is_array($types) ? array_values(array_map('strval', $types)) : null;
    }

    /** Deletes a freshly created media nobody references (after a failed replace). */
    public function discardIfUnreferenced(Media $media): void
    {
        if (! $this->isReferenced($media)) {
            $media->delete();
        }
    }

    protected function isReferenced(Media $media): bool
    {
        return MediaFieldValue::query()->where('media_id', $media->id)->exists()
            || MediaConversion::query()->where('media_id', $media->id)->exists()
            || MediaUsageRow::query()->where('media_id', $media->id)->exists();
    }

    protected function moveUsageRows(Media $old, Media $new): void
    {
        MediaUsageRow::query()->where('media_id', $old->id)->each(function (MediaUsageRow $row) use ($new): void {
            $exists = MediaUsageRow::query()
                ->where('media_id', $new->id)
                ->where('field_value_id', $row->field_value_id)
                ->where('owner_type', $row->owner_type)
                ->where('owner_id', $row->owner_id)
                ->where('source', $row->source)
                ->where('revision_id', $row->revision_id)
                ->where('locale', $row->locale)
                ->exists();

            $exists ? $row->delete() : $row->update(['media_id' => $new->id]);
        });
    }
}

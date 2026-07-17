<?php

namespace MarvelousDigital\FilamentMediaManager\Actions;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use MarvelousDigital\FilamentMediaManager\Enums\MediaType;
use MarvelousDigital\FilamentMediaManager\Enums\ThumbnailStatus;
use MarvelousDigital\FilamentMediaManager\Jobs\GenerateThumbnail;
use MarvelousDigital\FilamentMediaManager\Models\Media;
use MarvelousDigital\FilamentMediaManager\Models\MediaFolder;

class UploadMedia
{
    public function execute(UploadedFile $file, ?MediaFolder $folder = null): Media
    {
        $this->validate($file);

        $disk = config('filament-media-manager.disk');
        $directory = trim(config('filament-media-manager.directory'), '/') . '/' . Str::ulid();
        $fileName = $this->sanitizeFileName($file->getClientOriginalName());
        $mimeType = $file->getMimeType() ?? 'application/octet-stream';
        $type = MediaType::fromMimeType($mimeType);

        $path = $file->storeAs($directory, $fileName, ['disk' => $disk]);

        $needsThumbnail = $type === MediaType::Image && $mimeType !== 'image/svg+xml';

        $media = Media::create([
            'folder_id' => $folder?->id,
            'name' => pathinfo($fileName, PATHINFO_FILENAME),
            'file_name' => $fileName,
            'disk' => $disk,
            'path' => $path,
            'mime_type' => $mimeType,
            'type' => $type,
            'size' => $file->getSize(),
            'thumbnail_status' => $needsThumbnail ? ThumbnailStatus::Pending : ThumbnailStatus::Skipped,
            'created_by' => Auth::id(),
        ]);

        if ($needsThumbnail) {
            GenerateThumbnail::dispatchFor($media);
        }

        return $media;
    }

    protected function validate(UploadedFile $file): void
    {
        $accepted = config('filament-media-manager.accepted_mime_types');

        if (is_array($accepted) && ! in_array($file->getMimeType(), $accepted, true)) {
            throw ValidationException::withMessages([
                'file' => "The file type of \"{$file->getClientOriginalName()}\" is not allowed.",
            ]);
        }

        $maxKilobytes = config('filament-media-manager.max_file_size');

        if ($maxKilobytes !== null && $file->getSize() > $maxKilobytes * 1024) {
            throw ValidationException::withMessages([
                'file' => "\"{$file->getClientOriginalName()}\" exceeds the maximum size of {$maxKilobytes} KB.",
            ]);
        }
    }

    protected function sanitizeFileName(string $fileName): string
    {
        $fileName = basename(str_replace('\\', '/', $fileName));
        $fileName = str_replace(['..', '#', '?'], '', $fileName);

        return $fileName === '' ? 'file' : $fileName;
    }
}

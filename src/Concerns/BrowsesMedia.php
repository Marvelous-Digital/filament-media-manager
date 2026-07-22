<?php

namespace MarvelousDigital\FilamentMediaManager\Concerns;

use Filament\Notifications\Notification;
use Illuminate\Contracts\Database\Eloquent\Builder;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Url;
use MarvelousDigital\FilamentMediaManager\Actions\UploadMedia;
use MarvelousDigital\FilamentMediaManager\Enums\MediaType;
use MarvelousDigital\FilamentMediaManager\Models\Media;
use MarvelousDigital\FilamentMediaManager\Models\MediaFolder;

trait BrowsesMedia
{
    #[Url]
    public ?int $folderId = null;

    #[Url]
    public string $search = '';

    public int $loadedPages = 1;

    /** @var array<UploadedFile> */
    public array $uploads = [];

    protected function imagesOnly(): bool
    {
        return false;
    }

    public function currentFolder(): ?MediaFolder
    {
        return $this->folderId ? MediaFolder::find($this->folderId) : null;
    }

    public function openFolder(?int $folderId): void
    {
        $this->folderId = $folderId;
        $this->search = '';
        $this->loadedPages = 1;
        $this->afterOpenFolder();
    }

    protected function afterOpenFolder(): void
    {
        // Overridable hook (e.g. MediaLibrary resets $selected).
    }

    public function loadMore(): void
    {
        $this->loadedPages++;
    }

    public function updatedSearch(): void
    {
        $this->loadedPages = 1;
    }

    public function updatedUploads(): void
    {
        $folder = $this->currentFolder();
        $stored = 0;

        foreach ($this->uploads as $file) {
            try {
                app(UploadMedia::class)->execute($file, $folder);
                $stored++;
            } catch (ValidationException $exception) {
                Notification::make()
                    ->title('Upload failed')
                    ->body(implode(' ', collect($exception->errors())->flatten()->all()))
                    ->danger()
                    ->send();
            }
        }

        $this->uploads = [];

        if ($stored > 0) {
            Notification::make()
                ->title($stored === 1 ? '1 file uploaded' : "{$stored} files uploaded")
                ->success()
                ->send();
        }
    }

    protected function mediaQuery(): Builder
    {
        $query = Media::query();

        $this->folderId === null
            ? $query->whereNull('folder_id')
            : $query->where('folder_id', $this->folderId);

        if ($this->imagesOnly()) {
            $query->where('type', MediaType::Image->value);
        }

        return $query
            ->when($this->search !== '', function (Builder $query) {
                $query->where(function (Builder $query) {
                    $query->where('name', 'like', "%{$this->search}%")
                        ->orWhere('alt_text', 'like', "%{$this->search}%");
                });
            })
            ->latest();
    }

    /**
     * @return array<string, mixed>
     */
    protected function browseData(): array
    {
        $limit = $this->loadedPages * config('filament-media-manager.per_page', 30);
        $mediaItems = $this->mediaQuery()->limit($limit + 1)->get();
        $hasMore = $mediaItems->count() > $limit;

        $current = $this->currentFolder();

        return [
            'folders' => MediaFolder::query()
                ->where('parent_id', $this->folderId)
                ->orderBy('name')
                ->get(),
            'mediaItems' => $mediaItems->take($limit),
            'hasMore' => $hasMore,
            'currentFolder' => $current,
            'breadcrumbFolders' => $current ? [...$current->ancestors(), $current] : [],
        ];
    }
}

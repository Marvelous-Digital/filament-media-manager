<?php

namespace MarvelousDigital\FilamentMediaManager\Pages;

use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Contracts\Database\Eloquent\Builder;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Url;
use Livewire\WithFileUploads;
use MarvelousDigital\FilamentMediaManager\Actions\UploadMedia;
use MarvelousDigital\FilamentMediaManager\Models\Media;
use MarvelousDigital\FilamentMediaManager\Models\MediaFolder;

class MediaLibrary extends Page
{
    use WithFileUploads;

    protected string $view = 'filament-media-manager::media-library';

    #[Url]
    public ?int $folderId = null;

    #[Url]
    public string $search = '';

    public int $loadedPages = 1;

    /** @var array<int> */
    public array $selected = [];

    /** @var array<UploadedFile> */
    public array $uploads = [];

    public static function getNavigationLabel(): string
    {
        return 'Media';
    }

    public static function getNavigationIcon(): ?string
    {
        return 'heroicon-o-photo';
    }

    public function getTitle(): string
    {
        return 'Media';
    }

    public function createFolderAction(): Action
    {
        return Action::make('createFolder')
            ->label('New folder')
            ->icon('heroicon-o-folder-plus')
            ->color('gray')
            ->modalHeading('New folder')
            ->schema([
                TextInput::make('name')
                    ->required()
                    ->maxLength(255)
                    ->rules([
                        fn () => Rule::unique('media_folders', 'name')->where('parent_id', $this->folderId),
                    ]),
            ])
            ->action(function (array $data): void {
                MediaFolder::create(['name' => $data['name'], 'parent_id' => $this->folderId]);
            });
    }

    public function renameFolderAction(): Action
    {
        return Action::make('renameFolder')
            ->label('Rename')
            ->icon('heroicon-o-pencil')
            ->modalHeading('Rename folder')
            ->fillForm(fn (array $arguments): array => [
                'name' => MediaFolder::find($arguments['folder'])?->name,
            ])
            ->schema(fn (array $arguments): array => [
                TextInput::make('name')
                    ->required()
                    ->maxLength(255)
                    ->rules([
                        fn () => Rule::unique('media_folders', 'name')
                            ->where('parent_id', MediaFolder::find($arguments['folder'])?->parent_id)
                            ->ignore($arguments['folder']),
                    ]),
            ])
            ->action(function (array $arguments, array $data): void {
                MediaFolder::find($arguments['folder'])?->update(['name' => $data['name']]);
            });
    }

    public function deleteFolderAction(): Action
    {
        return Action::make('deleteFolder')
            ->label('Delete')
            ->icon('heroicon-o-trash')
            ->color('danger')
            ->requiresConfirmation()
            ->modalHeading('Delete folder')
            ->action(function (array $arguments): void {
                $folder = MediaFolder::find($arguments['folder']);

                if (! $folder) {
                    return;
                }

                if (! $folder->isEmpty()) {
                    Notification::make()
                        ->title('Folder is not empty')
                        ->body('Move or delete its contents first.')
                        ->warning()
                        ->send();

                    return;
                }

                $folder->delete();
            });
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
        $this->selected = [];
    }

    public function loadMore(): void
    {
        $this->loadedPages++;
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

    public function updatedSearch(): void
    {
        $this->loadedPages = 1;
    }

    protected function mediaQuery(): Builder
    {
        $query = Media::query();

        $this->folderId === null
            ? $query->whereNull('folder_id')
            : $query->where('folder_id', $this->folderId);

        return $query
            ->when($this->search !== '', function (Builder $query) {
                $query->where(function (Builder $query) {
                    $query->where('name', 'like', "%{$this->search}%")
                        ->orWhere('alt_text', 'like', "%{$this->search}%");
                });
            })
            ->latest();
    }

    protected function getViewData(): array
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

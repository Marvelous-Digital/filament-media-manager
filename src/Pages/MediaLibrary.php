<?php

namespace MarvelousDigital\FilamentMediaManager\Pages;

use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\View;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Livewire\WithFileUploads;
use MarvelousDigital\FilamentMediaManager\Concerns\BrowsesMedia;
use MarvelousDigital\FilamentMediaManager\Enums\ThumbnailStatus;
use MarvelousDigital\FilamentMediaManager\Jobs\GenerateThumbnail;
use MarvelousDigital\FilamentMediaManager\Models\Media;
use MarvelousDigital\FilamentMediaManager\Models\MediaFolder;

class MediaLibrary extends Page
{
    use BrowsesMedia;
    use WithFileUploads;

    protected string $view = 'filament-media-manager::media-library';

    protected static ?string $slug = 'media-manager';

    /** @var array<int> */
    public array $selected = [];

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
                        fn () => Rule::unique(MediaFolder::class, 'name')->where('parent_id', $this->folderId),
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
                'name' => MediaFolder::find((int) $arguments['folder'])?->name,
            ])
            ->schema(fn (array $arguments): array => [
                TextInput::make('name')
                    ->required()
                    ->maxLength(255)
                    ->rules([
                        fn () => Rule::unique(MediaFolder::class, 'name')
                            ->where('parent_id', MediaFolder::find((int) $arguments['folder'])?->parent_id)
                            ->ignore($arguments['folder']),
                    ]),
            ])
            ->action(function (array $arguments, array $data): void {
                MediaFolder::find((int) $arguments['folder'])?->update(['name' => $data['name']]);
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
                $folder = MediaFolder::find((int) $arguments['folder']);

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

    public function editMediaAction(): Action
    {
        return Action::make('editMedia')
            ->label('Details')
            ->slideOver()
            ->modalHeading(fn (array $arguments): string => Media::query()->whereKey((int) $arguments['media'])->value('name') ?? 'Media')
            ->fillForm(function (array $arguments): array {
                $media = Media::find((int) $arguments['media']);

                return [
                    'name' => $media?->name,
                    'alt_text' => $media?->alt_text,
                    'folder_id' => $media?->folder_id,
                ];
            })
            ->schema(fn (array $arguments): array => [
                View::make('filament-media-manager::media-details')
                    ->viewData(['media' => Media::find((int) $arguments['media'])]),
                TextInput::make('name')->required()->maxLength(255),
                Textarea::make('alt_text')->label('Alt text')->rows(3)->maxLength(255),
                Select::make('folder_id')
                    ->label('Folder')
                    ->options(MediaFolder::treeOptions())
                    ->placeholder('Root')
                    ->nullable(),
            ])
            ->action(function (array $arguments, array $data): void {
                Media::find((int) $arguments['media'])?->update([
                    'name' => $data['name'],
                    'alt_text' => $data['alt_text'],
                    'folder_id' => $data['folder_id'],
                ]);
            });
    }

    public function deleteMediaAction(): Action
    {
        return Action::make('deleteMedia')
            ->label('Delete')
            ->icon('heroicon-o-trash')
            ->color('danger')
            ->requiresConfirmation()
            ->action(function (array $arguments): void {
                Media::find((int) $arguments['media'])?->delete();
            });
    }

    public function downloadMediaAction(): Action
    {
        return Action::make('downloadMedia')
            ->label('Download')
            ->icon('heroicon-o-arrow-down-tray')
            ->action(function (array $arguments) {
                $media = Media::find((int) $arguments['media']);

                if (! $media) {
                    return null;
                }

                return Storage::disk($media->disk)->download($media->path, $media->file_name);
            });
    }

    public function retryThumbnailAction(): Action
    {
        return Action::make('retryThumbnail')
            ->label('Retry thumbnail')
            ->icon('heroicon-o-arrow-path')
            ->action(function (array $arguments): void {
                $media = Media::find((int) $arguments['media']);

                if (! $media) {
                    return;
                }

                $media->update(['thumbnail_status' => ThumbnailStatus::Pending]);
                GenerateThumbnail::dispatchFor($media);
            });
    }

    public function bulkDeleteAction(): Action
    {
        return Action::make('bulkDelete')
            ->label('Delete selected')
            ->icon('heroicon-o-trash')
            ->color('danger')
            ->requiresConfirmation()
            ->modalHeading('Delete selected media')
            ->action(function (): void {
                Media::query()->whereIn('id', $this->selected)->get()->each->delete();
                $this->selected = [];
            });
    }

    public function bulkMoveAction(): Action
    {
        return Action::make('bulkMove')
            ->label('Move selected')
            ->icon('heroicon-o-folder-arrow-down')
            ->color('gray')
            ->schema([
                Select::make('folder_id')
                    ->label('Move to')
                    ->options(MediaFolder::treeOptions())
                    ->placeholder('Root')
                    ->nullable(),
            ])
            ->action(function (array $data): void {
                Media::query()->whereIn('id', $this->selected)->update(['folder_id' => $data['folder_id']]);
                $this->selected = [];
            });
    }

    protected function afterOpenFolder(): void
    {
        $this->selected = [];
    }

    protected function getViewData(): array
    {
        return $this->browseData();
    }
}

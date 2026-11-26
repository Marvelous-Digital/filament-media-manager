<?php

namespace MarvelousDigital\FilamentMediaManager\Pages;

use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\View;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Livewire\WithFileUploads;
use MarvelousDigital\FilamentMediaManager\Concerns\BrowsesMedia;
use MarvelousDigital\FilamentMediaManager\Enums\ThumbnailStatus;
use MarvelousDigital\FilamentMediaManager\Jobs\GenerateThumbnail;
use MarvelousDigital\FilamentMediaManager\Models\Media;
use MarvelousDigital\FilamentMediaManager\Models\MediaFolder;
use MarvelousDigital\FilamentMediaManager\Usage\DescribesMediaOwners;
use MarvelousDigital\FilamentMediaManager\Usage\MediaInUse;
use MarvelousDigital\FilamentMediaManager\Usage\MediaUsage;
use MarvelousDigital\FilamentMediaManager\Usage\OwnerDescription;
use MarvelousDigital\FilamentMediaManager\Usage\Usage;
use MarvelousDigital\FilamentMediaManager\Usage\UsageSource;

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
        return __('filament-media-manager::media.library.title');
    }

    public static function getNavigationIcon(): ?string
    {
        return 'heroicon-o-photo';
    }

    public function getTitle(): string
    {
        return __('filament-media-manager::media.library.title');
    }

    public function createFolderAction(): Action
    {
        return Action::make('createFolder')
            ->label(__('filament-media-manager::media.actions.new_folder'))
            ->icon('heroicon-o-folder-plus')
            ->color('gray')
            ->modalHeading(__('filament-media-manager::media.actions.new_folder'))
            ->schema([
                TextInput::make('name')
                    ->label(__('filament-media-manager::media.fields.name'))
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
            ->label(__('filament-media-manager::media.actions.rename'))
            ->icon('heroicon-o-pencil')
            ->modalHeading(__('filament-media-manager::media.actions.rename_folder'))
            ->fillForm(fn (array $arguments): array => [
                'name' => MediaFolder::find((int) $arguments['folder'])?->name,
            ])
            ->schema(fn (array $arguments): array => [
                TextInput::make('name')
                    ->label(__('filament-media-manager::media.fields.name'))
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
            ->label(__('filament-media-manager::media.actions.delete'))
            ->icon('heroicon-o-trash')
            ->color('danger')
            ->requiresConfirmation()
            ->modalHeading(__('filament-media-manager::media.actions.delete_folder'))
            ->action(function (array $arguments): void {
                $folder = MediaFolder::find((int) $arguments['folder']);

                if (! $folder) {
                    return;
                }

                if (! $folder->isEmpty()) {
                    Notification::make()
                        ->title(__('filament-media-manager::media.notifications.folder_not_empty_title'))
                        ->body(__('filament-media-manager::media.notifications.folder_not_empty_body'))
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
            ->label(__('filament-media-manager::media.actions.details'))
            ->slideOver()
            ->modalHeading(fn (array $arguments): string => Media::query()->whereKey((int) $arguments['media'])->value('name') ?? __('filament-media-manager::media.library.title'))
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
                View::make('filament-media-manager::partials.media-usages')
                    ->viewData(fn (): array => ['groups' => $this->usageGroups((int) $arguments['media'])])
                    ->visible(fn (): bool => app(MediaUsage::class)->isActive()),
                TextInput::make('name')->label(__('filament-media-manager::media.fields.name'))->required()->maxLength(255),
                Textarea::make('alt_text')->label(__('filament-media-manager::media.fields.alt_text'))->rows(3)->maxLength(255),
                Select::make('folder_id')
                    ->label(__('filament-media-manager::media.fields.folder'))
                    ->options(MediaFolder::treeOptions())
                    ->placeholder(__('filament-media-manager::media.fields.root'))
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
            ->label(__('filament-media-manager::media.actions.delete'))
            ->icon('heroicon-o-trash')
            ->color('danger')
            ->requiresConfirmation()
            ->modalDescription(function (array $arguments): ?string {
                $count = $this->historyOnlyCount((int) ($arguments['media'] ?? 0));

                return $count > 0
                    ? __('filament-media-manager::media.usage.history_only_warning', ['count' => $count])
                    : null;
            })
            ->action(function (array $arguments): void {
                $media = Media::find((int) $arguments['media']);

                try {
                    $media?->delete();
                } catch (MediaInUse) {
                    $this->notifyInUse($media);
                }
            });
    }

    /**
     * Titles of the owners that block deleting a media (live or draft), deduplicated.
     *
     * @return list<string>
     */
    public function ownersBlockingDelete(int $mediaId, int $limit = 5): array
    {
        $groups = $this->groupUsages(
            app(MediaUsage::class)->usagesOf($mediaId)->filter(fn (Usage $usage) => $usage->source->blocksDelete())
        );
        $describer = app(DescribesMediaOwners::class);
        $titles = [];

        foreach ($groups as $group) {
            foreach ($group as $entry) {
                $title = $describer->describe($entry['usage'])->title;

                if (! in_array($title, $titles, true)) {
                    $titles[] = $title;
                }
            }
        }

        return array_slice($titles, 0, $limit);
    }

    /**
     * Where a media is used: per source (live, draft, history), one entry per owner and revision,
     * with the locales it appears in.
     *
     * @return array{live: list<array{owner: OwnerDescription, locales: list<string>}>, draft: list<array{owner: OwnerDescription, locales: list<string>}>, history: list<array{owner: OwnerDescription, locales: list<string>}>}
     */
    public function usageGroups(int $mediaId): array
    {
        $describer = app(DescribesMediaOwners::class);
        $result = [];

        foreach ($this->groupUsages(app(MediaUsage::class)->usagesOf($mediaId)) as $source => $entries) {
            $result[$source] = array_map(fn (array $entry) => [
                'owner' => $describer->describe($entry['usage']),
                'locales' => $entry['locales'],
            ], $entries);
        }

        return $result;
    }

    /**
     * Groups usages by source (live, draft, history), then by owner type, id and revision.
     *
     * @param  Collection<int, Usage>  $usages
     * @return array<string, list<array{usage: Usage, locales: list<string>}>>
     */
    protected function groupUsages(Collection $usages): array
    {
        $groups = [];

        foreach (UsageSource::cases() as $source) {
            $groups[$source->value] = [];
        }

        foreach ($usages as $usage) {
            $key = $usage->ownerType . '|' . $usage->ownerId . '|' . $usage->revisionId;
            $entry = $groups[$usage->source->value][$key] ?? ['usage' => $usage, 'locales' => []];

            if ($usage->locale !== null && ! in_array($usage->locale, $entry['locales'], true)) {
                $entry['locales'][] = $usage->locale;
            }

            $groups[$usage->source->value][$key] = $entry;
        }

        return array_map(fn (array $entries) => array_values($entries), $groups);
    }

    protected function historyOnlyCount(int $mediaId): int
    {
        $usage = app(MediaUsage::class);

        if (! $usage->isActive() || $usage->blocksDelete($mediaId)) {
            return 0;
        }

        return count($this->groupUsages($usage->usagesOf($mediaId))[UsageSource::History->value]);
    }

    protected function notifyInUse(?Media $media): void
    {
        if (! $media) {
            return;
        }

        $all = $this->ownersBlockingDelete($media->id, PHP_INT_MAX);
        $owners = array_slice($all, 0, 5);
        $more = count($all) - count($owners);

        Notification::make()
            ->danger()
            ->title(__('filament-media-manager::media.usage.in_use_title'))
            ->body(__('filament-media-manager::media.usage.in_use_body', [
                'owners' => implode(', ', $owners),
                'more' => $more > 0 ? ' +' . $more : '',
            ]))
            ->send();
    }

    public function downloadMediaAction(): Action
    {
        return Action::make('downloadMedia')
            ->label(__('filament-media-manager::media.actions.download'))
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
            ->label(__('filament-media-manager::media.actions.retry_thumbnail'))
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
            ->label(__('filament-media-manager::media.actions.delete_selected'))
            ->icon('heroicon-o-trash')
            ->color('danger')
            ->requiresConfirmation()
            ->modalHeading(__('filament-media-manager::media.actions.delete_selected_heading'))
            ->action(function (): void {
                $skipped = [];

                foreach (Media::query()->whereIn('id', $this->selected)->get() as $media) {
                    try {
                        $media->delete();
                    } catch (MediaInUse) {
                        $skipped[] = $media->name;
                    }
                }

                $this->selected = [];

                if ($skipped !== []) {
                    Notification::make()
                        ->warning()
                        ->title(__('filament-media-manager::media.usage.bulk_skipped', ['count' => count($skipped)]))
                        ->body(implode(', ', $skipped))
                        ->send();
                }
            });
    }

    public function bulkMoveAction(): Action
    {
        return Action::make('bulkMove')
            ->label(__('filament-media-manager::media.actions.move_selected'))
            ->icon('heroicon-o-folder-arrow-down')
            ->color('gray')
            ->schema([
                Select::make('folder_id')
                    ->label(__('filament-media-manager::media.actions.move_to'))
                    ->options(MediaFolder::treeOptions())
                    ->placeholder(__('filament-media-manager::media.fields.root'))
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

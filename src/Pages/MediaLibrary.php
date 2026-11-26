<?php

namespace MarvelousDigital\FilamentMediaManager\Pages;

use Closure;
use Filament\Actions\Action;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\View;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use InvalidArgumentException;
use Livewire\WithFileUploads;
use MarvelousDigital\FilamentMediaManager\Actions\ReplaceMedia;
use MarvelousDigital\FilamentMediaManager\Actions\UploadMedia;
use MarvelousDigital\FilamentMediaManager\Concerns\BrowsesMedia;
use MarvelousDigital\FilamentMediaManager\Enums\MediaType;
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
            ->extraModalFooterActions(fn (array $arguments): array => $this->canReplace()
                ? [$this->replaceMediaAction()->arguments(['media' => (int) $arguments['media']])->color('gray')]
                : [])
            ->action(function (array $arguments, array $data): void {
                Media::find((int) $arguments['media'])?->update([
                    'name' => $data['name'],
                    'alt_text' => $data['alt_text'],
                    'folder_id' => $data['folder_id'],
                ]);
            });
    }

    public function replaceMediaAction(): Action
    {
        return Action::make('replaceMedia')
            ->label(__('filament-media-manager::media.usage.replace'))
            ->icon('heroicon-o-arrows-right-left')
            ->modalHeading(__('filament-media-manager::media.usage.replace'))
            ->modalDescription(__('filament-media-manager::media.usage.replace_description'))
            ->visible(fn (): bool => $this->canReplace())
            ->schema(function (array $arguments): array {
                $media = Media::find((int) ($arguments['media'] ?? 0));
                $isImage = $media?->type === MediaType::Image;
                $acceptedTypes = $isImage ? ['image/*'] : ReplaceMedia::documentMimeTypes();

                $upload = FileUpload::make('file')
                    ->label(__('filament-media-manager::media.usage.replace_upload'))
                    ->storeFiles(false)
                    ->maxFiles(1)
                    ->required(! $isImage);

                if ($isImage) {
                    $upload->requiredWithout('library_media_id');
                }

                if ($acceptedTypes !== null) {
                    $upload->acceptedFileTypes($acceptedTypes);
                }

                return [
                    $upload,
                    Select::make('library_media_id')
                        ->label(__('filament-media-manager::media.usage.replace_pick'))
                        ->searchable()
                        ->getSearchResultsUsing(fn (string $search): array => Media::query()
                            ->where('type', MediaType::Image->value)
                            ->whereKeyNot($media?->id)
                            ->where('name', 'like', '%' . $search . '%')
                            ->orderBy('name')
                            ->limit(50)
                            ->pluck('name', 'id')
                            ->all())
                        ->getOptionLabelUsing(fn ($value): ?string => Media::query()->whereKey($value)->value('name'))
                        ->requiredWithout('file')
                        ->rules([
                            fn (Get $get): Closure => function (string $attribute, mixed $value, Closure $fail) use ($get, $media): void {
                                if ($media !== null && (int) $value === $media->id) {
                                    $fail(__('filament-media-manager::media.usage.replace_same'));
                                } elseif (filled($value) && filled($get('file'))) {
                                    $fail(__('filament-media-manager::media.usage.replace_one_source'));
                                }
                            },
                        ])
                        ->visible($isImage),
                ];
            })
            ->action(function (array $arguments, array $data, Action $action): void {
                $old = Media::find((int) $arguments['media']);

                if (! $old || ! $this->canReplace()) {
                    return;
                }

                $file = $data['file'] ?? null;
                $file = is_array($file) ? (reset($file) ?: null) : $file;

                if ($old->type !== MediaType::Image) {
                    try {
                        app(ReplaceMedia::class)->document($old, $file);
                    } catch (InvalidArgumentException $e) {
                        $this->refuseReplace($action, $e);
                    }

                    Notification::make()->success()->title(__('filament-media-manager::media.usage.replaced_document'))->send();

                    return;
                }

                $created = null;

                try {
                    if (! empty($data['library_media_id'])) {
                        $new = Media::query()->where('type', MediaType::Image->value)->find((int) $data['library_media_id']);
                    } else {
                        $new = $created = $file instanceof UploadedFile ? app(UploadMedia::class)->execute($file, $old->folder) : null;
                        $new?->update(['alt_text' => $old->alt_text]);
                    }

                    if (! $new) {
                        return;
                    }

                    $count = app(ReplaceMedia::class)->image($old, $new);
                } catch (\Throwable $e) {
                    // Only drop the upload when the replace did not commit: once it did, it owns the moved rows.
                    try {
                        $created && app(ReplaceMedia::class)->discardIfUnreferenced($created);
                    } catch (\Throwable $cleanup) {
                        report($cleanup);
                    }

                    if ($e instanceof InvalidArgumentException) {
                        $this->refuseReplace($action, $e);
                    }

                    throw $e;
                }

                Notification::make()
                    ->success()
                    ->title(trans_choice('filament-media-manager::media.usage.replaced', $count, ['count' => $count]))
                    ->send();
            });
    }

    /** A replace the rules refused: a danger notification, the modal stays open. */
    protected function refuseReplace(Action $action, InvalidArgumentException $exception): never
    {
        Notification::make()
            ->danger()
            ->title(__('filament-media-manager::media.usage.replace_refused'))
            ->body($exception->getMessage())
            ->send();

        $action->halt();

        throw new \LogicException('Unreachable.');
    }

    protected function canReplace(): bool
    {
        return app(MediaUsage::class)->isActive()
            && Gate::allows(config('filament-media-manager.usage.replace_ability', 'replace-media'));
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
     * Titles of the owners that block deleting a media (live or draft), deduplicated. Only the first
     * $limit owners are described.
     *
     * @return list<string>
     */
    public function ownersBlockingDelete(int $mediaId, int $limit = 5): array
    {
        return $this->describeTitles(array_slice($this->blockingOwners($mediaId), 0, $limit));
    }

    /**
     * Where a media is used: per source (live, draft, history), one entry per owner (and per revision
     * for drafts), with the locales it appears in. An owner's history is one entry counting its
     * earlier versions.
     *
     * @return array{live: list<array{owner: OwnerDescription, locales: list<string>}>, draft: list<array{owner: OwnerDescription, locales: list<string>}>, history: list<array{owner: OwnerDescription, locales: list<string>}>}
     */
    public function usageGroups(int $mediaId): array
    {
        $describer = app(DescribesMediaOwners::class);
        $result = [];

        foreach ($this->groupUsages(app(MediaUsage::class)->usagesOf($mediaId)) as $source => $entries) {
            $result[$source] = array_map(function (array $entry) use ($describer): array {
                $owner = $describer->describe($entry['usage']);
                $versions = count($entry['revisions']);

                if ($versions > 1) {
                    $owner = new OwnerDescription(
                        $owner->title,
                        $owner->url,
                        trans_choice('filament-media-manager::media.usage.earlier_versions', $versions, ['count' => $versions]),
                        $owner->children,
                    );
                }

                return ['owner' => $owner, 'locales' => $entry['locales']];
            }, $entries);
        }

        return $result;
    }

    /**
     * Groups usages by source (live, draft, history), then by owner (and revision, except for history,
     * which is one group per owner). Each group carries the usage to describe: its locale is the
     * group's only locale, or null when the group spans several.
     *
     * @param  Collection<int, Usage>  $usages
     * @return array<string, list<array{usage: Usage, locales: list<string>, revisions: list<int>}>>
     */
    protected function groupUsages(Collection $usages): array
    {
        $groups = [];

        foreach (UsageSource::cases() as $source) {
            $groups[$source->value] = [];
        }

        foreach ($usages as $usage) {
            $key = $usage->ownerType . '|' . $usage->ownerId
                . ($usage->source === UsageSource::History ? '' : '|' . $usage->revisionId);
            $entry = $groups[$usage->source->value][$key] ?? ['usage' => $usage, 'locales' => [], 'revisions' => []];

            if ($usage->locale !== null && ! in_array($usage->locale, $entry['locales'], true)) {
                $entry['locales'][] = $usage->locale;
            }

            if ($usage->revisionId !== null && ! in_array($usage->revisionId, $entry['revisions'], true)) {
                $entry['revisions'][] = $usage->revisionId;
            }

            $groups[$usage->source->value][$key] = $entry;
        }

        return array_map(fn (array $entries): array => array_values(array_map(function (array $entry): array {
            $usage = $entry['usage'];
            $entry['usage'] = new Usage(
                mediaId: $usage->mediaId,
                fieldValueId: $usage->fieldValueId,
                ownerType: $usage->ownerType,
                ownerId: $usage->ownerId,
                source: $usage->source,
                revisionId: $usage->revisionId,
                locale: count($entry['locales']) === 1 ? $entry['locales'][0] : null,
            );

            return $entry;
        }, $entries)), $groups);
    }

    /**
     * One usage per owner blocking a delete (live or draft), keyed by owner, in index order.
     *
     * @return array<string, Usage>
     */
    protected function blockingOwners(int $mediaId): array
    {
        $owners = [];

        foreach (app(MediaUsage::class)->usagesOf($mediaId) as $usage) {
            if ($usage->source->blocksDelete()) {
                $owners[$usage->ownerType . '|' . $usage->ownerId] ??= $usage;
            }
        }

        return $owners;
    }

    /**
     * @param  array<string, Usage>  $owners
     * @return list<string>
     */
    protected function describeTitles(array $owners): array
    {
        $describer = app(DescribesMediaOwners::class);
        $titles = [];

        foreach ($owners as $usage) {
            $title = $describer->describe($usage)->title;

            if (! in_array($title, $titles, true)) {
                $titles[] = $title;
            }
        }

        return $titles;
    }

    protected function historyOnlyCount(int $mediaId): int
    {
        $usage = app(MediaUsage::class);

        if (! $usage->isActive() || $usage->blocksDelete($mediaId)) {
            return 0;
        }

        return array_sum(array_map(
            fn (array $entry): int => max(1, count($entry['revisions'])),
            $this->groupUsages($usage->usagesOf($mediaId))[UsageSource::History->value],
        ));
    }

    protected function notifyInUse(?Media $media, int $limit = 5): void
    {
        if (! $media) {
            return;
        }

        $owners = $this->blockingOwners($media->id);
        $titles = $this->describeTitles(array_slice($owners, 0, $limit));
        $more = count($owners) - min($limit, count($owners));

        Notification::make()
            ->danger()
            ->title(__('filament-media-manager::media.usage.in_use_title'))
            ->body(__('filament-media-manager::media.usage.in_use_body', [
                'owners' => implode(', ', $titles),
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

    protected function marksUnused(): bool
    {
        return true;
    }

    protected function afterOpenFolder(): void
    {
        $this->selected = [];
    }

    protected function getViewData(): array
    {
        return $this->browseData() + ['canReplace' => $this->canReplace()];
    }
}

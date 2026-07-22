@php($mode = $mode ?? 'manage')
<div class="fmm-grid">
    @foreach ($folders as $folder)
        <div class="fmm-card fmm-folder" wire:key="folder-{{ $folder->id }}">
            <button type="button" class="fmm-card-body" wire:click="openFolder({{ $folder->id }})">
                <x-filament::icon icon="heroicon-o-folder" class="fmm-folder-icon" />
                <span class="fmm-card-name">{{ $folder->name }}</span>
            </button>
            @if ($mode === 'manage')
                <div class="fmm-card-menu">
                    <x-filament::icon-button icon="heroicon-o-pencil" color="gray" size="sm" label="Rename"
                        wire:click="mountAction('renameFolder', { folder: {{ $folder->id }} })" />
                    <x-filament::icon-button icon="heroicon-o-trash" color="danger" size="sm" label="Delete"
                        wire:click="mountAction('deleteFolder', { folder: {{ $folder->id }} })" />
                </div>
            @endif
        </div>
    @endforeach

    @foreach ($mediaItems as $media)
        <div class="fmm-card fmm-media" wire:key="media-{{ $media->id }}">
            <div class="fmm-card-body">
                <div class="fmm-thumb">
                    @if ($media->thumbnailUrl())
                        <img src="{{ $media->thumbnailUrl() }}" alt="{{ $media->alt_text ?? $media->name }}" loading="lazy" />
                    @else
                        <x-filament::icon :icon="match ($media->type->value) {
                            'video' => 'heroicon-o-film',
                            'document' => 'heroicon-o-document-text',
                            default => 'heroicon-o-document',
                        }" class="fmm-type-icon" />
                        @if ($media->alt_text)
                            <span class="fmm-sr-only">{{ $media->alt_text }}</span>
                        @endif
                    @endif
                </div>
                <span class="fmm-card-name" title="{{ $media->file_name }}">{{ $media->name }}</span>
            </div>

            @if ($mode === 'select')
                <button type="button" class="fmm-card-overlay" wire:click="select({{ $media->id }})"
                    aria-label="Select {{ $media->name }}"></button>
            @else
                <button type="button" class="fmm-card-overlay" wire:click="mountAction('editMedia', { media: {{ $media->id }} })"
                    aria-label="Open details for {{ $media->name }}"></button>
                <label class="fmm-card-check" x-on:click.stop>
                    <input type="checkbox" value="{{ $media->id }}" wire:model.live="selected" class="fi-checkbox-input" />
                </label>
                <div class="fmm-card-menu">
                    <x-filament::icon-button icon="heroicon-o-arrow-down-tray" color="gray" size="sm" label="Download"
                        wire:click="mountAction('downloadMedia', { media: {{ $media->id }} })" />
                    @if ($media->thumbnail_status === \MarvelousDigital\FilamentMediaManager\Enums\ThumbnailStatus::Failed)
                        <x-filament::icon-button icon="heroicon-o-arrow-path" color="warning" size="sm" label="Retry thumbnail"
                            wire:click="mountAction('retryThumbnail', { media: {{ $media->id }} })" />
                    @endif
                    <x-filament::icon-button icon="heroicon-o-trash" color="danger" size="sm" label="Delete"
                        wire:click="mountAction('deleteMedia', { media: {{ $media->id }} })" />
                </div>
            @endif
        </div>
    @endforeach
</div>

@if ($folders->isEmpty() && $mediaItems->isEmpty())
    <div class="fmm-empty">
        <x-filament::icon icon="heroicon-o-photo" class="fmm-empty-icon" />
        <p>No media here yet. Upload files or create a folder to get started.</p>
    </div>
@endif

@if ($hasMore)
    <div class="fmm-load-more">
        <x-filament::button color="gray" wire:click="loadMore">Load more</x-filament::button>
    </div>
@endif

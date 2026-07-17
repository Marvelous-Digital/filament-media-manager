<x-filament-panels::page>
    <div
        class="fmm-library"
        x-data
        x-on:dragover.prevent
        x-on:drop.prevent="
            const uploadEl = document.querySelector('.fmm-upload');
            if (uploadEl) Alpine.$data(uploadEl).handleFiles($event.dataTransfer.files);
        "
    >
        {{-- Toolbar --}}
        <div class="fmm-toolbar">
            <nav class="fmm-breadcrumbs">
                <button type="button" wire:click="openFolder(null)" @class(['fmm-crumb', 'fmm-crumb-current' => ! $currentFolder])>
                    Media
                </button>
                @foreach ($breadcrumbFolders as $crumb)
                    <span class="fmm-crumb-sep">/</span>
                    <button type="button" wire:click="openFolder({{ $crumb->id }})" @class(['fmm-crumb', 'fmm-crumb-current' => $crumb->id === $folderId])>
                        {{ $crumb->name }}
                    </button>
                @endforeach
            </nav>

            <div class="fmm-toolbar-actions">
                <input
                    type="search"
                    wire:model.live.debounce.400ms="search"
                    placeholder="Search this folder…"
                    class="fmm-search fi-input"
                />
                {{ $this->createFolderAction }}
                <div
                    x-data="{
                        progress: 0,
                        uploading: false,
                        handleFiles(files) {
                            if (! files.length) return;
                            this.uploading = true;
                            $wire.uploadMultiple('uploads', files,
                                () => { this.uploading = false; this.progress = 0; },
                                () => { this.uploading = false; this.progress = 0; },
                                (event) => { this.progress = event.detail.progress; },
                            );
                        },
                    }"
                    x-on:dragover.prevent
                    x-on:drop.prevent.stop="handleFiles($event.dataTransfer.files)"
                    class="fmm-upload"
                >
                    <input type="file" multiple class="fmm-upload-input" x-ref="input" x-on:change="handleFiles($event.target.files); $event.target.value = null;" />
                    <x-filament::button icon="heroicon-o-arrow-up-tray" x-on:click="$refs.input.click()" x-show="! uploading">
                        Upload
                    </x-filament::button>
                    <div class="fmm-upload-progress" x-show="uploading" x-cloak>
                        <div class="fmm-upload-progress-bar" x-bind:style="`width: ${progress}%`"></div>
                    </div>
                </div>
            </div>
        </div>

        {{-- @bulk-actions --}}

        {{-- Grid --}}
        <div class="fmm-grid">
            @foreach ($folders as $folder)
                <div class="fmm-card fmm-folder" wire:key="folder-{{ $folder->id }}">
                    <button type="button" class="fmm-card-body" wire:click="openFolder({{ $folder->id }})">
                        <x-filament::icon icon="heroicon-o-folder" class="fmm-folder-icon" />
                        <span class="fmm-card-name">{{ $folder->name }}</span>
                    </button>
                    <div class="fmm-card-menu">
                        <x-filament::icon-button icon="heroicon-o-pencil" color="gray" size="sm" label="Rename"
                            wire:click="mountAction('renameFolder', { folder: {{ $folder->id }} })" />
                        <x-filament::icon-button icon="heroicon-o-trash" color="danger" size="sm" label="Delete"
                            wire:click="mountAction('deleteFolder', { folder: {{ $folder->id }} })" />
                    </div>
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
                    <button type="button" class="fmm-card-overlay" wire:click="mountAction('editMedia', { media: {{ $media->id }} })" aria-label="Open details for {{ $media->name }}"></button>
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
    </div>
</x-filament-panels::page>

<x-filament-panels::page>
    <div class="fmm-library">
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
                {{-- @folder-actions --}}
                {{-- @upload-ui --}}
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
                    {{-- @folder-card-actions --}}
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
                    {{-- @media-actions --}}
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

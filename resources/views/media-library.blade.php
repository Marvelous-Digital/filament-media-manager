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

        @if (count($selected) > 0)
            <div class="fmm-bulk-bar">
                <span>{{ count($selected) }} selected</span>
                {{ $this->bulkMoveAction }}
                {{ $this->bulkDeleteAction }}
                <x-filament::link tag="button" wire:click="$set('selected', [])">Clear</x-filament::link>
            </div>
        @endif

        @include('filament-media-manager::partials.media-grid', ['mode' => 'manage'])
    </div>
</x-filament-panels::page>

<div class="fmm-library"
    x-on:dragover.prevent
    x-on:drop.prevent="
        const uploadEl = document.querySelector('.fmm-picker-upload');
        if (uploadEl) Alpine.$data(uploadEl).handleFiles($event.dataTransfer.files);
    "
>
    <div class="fmm-toolbar">
        <nav class="fmm-breadcrumbs">
            <button type="button" wire:click="openFolder(null)" @class(['fmm-crumb', 'fmm-crumb-current' => ! $currentFolder])>Media</button>
            @foreach ($breadcrumbFolders as $crumb)
                <span class="fmm-crumb-sep">/</span>
                <button type="button" wire:click="openFolder({{ $crumb->id }})" @class(['fmm-crumb', 'fmm-crumb-current' => $crumb->id === $folderId])>{{ $crumb->name }}</button>
            @endforeach
        </nav>

        <div class="fmm-toolbar-actions">
            <input type="search" wire:model.live.debounce.400ms="search" placeholder="Search this folder…" class="fmm-search fi-input" />
            <div x-data="{
                    uploading: false,
                    handleFiles(files) {
                        if (! files.length) return;
                        this.uploading = true;
                        $wire.uploadMultiple('uploads', files,
                            () => { this.uploading = false; },
                            () => { this.uploading = false; },
                        );
                    },
                }"
                x-on:dragover.prevent
                x-on:drop.prevent.stop="handleFiles($event.dataTransfer.files)"
                class="fmm-picker-upload"
            >
                <input type="file" multiple accept="image/*" class="fmm-upload-input" x-ref="input" x-on:change="handleFiles($event.target.files); $event.target.value = null;" />
                <x-filament::button icon="heroicon-o-arrow-up-tray" x-on:click="$refs.input.click()" x-show="! uploading">Upload</x-filament::button>
                <span x-show="uploading" x-cloak>Uploading…</span>
            </div>
        </div>
    </div>

    @include('filament-media-manager::partials.media-grid', ['mode' => 'select'])
</div>

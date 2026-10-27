@php
    $statePath = $getStatePath();
    $key = $getKey();
    $items = $field->getVerifiedState()['items'];
    $thumbnails = $field->getThumbnails();
@endphp

<x-dynamic-component :component="$getFieldWrapperView()" :field="$field">
    <div
        class="fmm-images-picker"
        wire:key="fmm-images-picker-{{ $statePath }}"
        x-data="{
            state: $wire.{{ $applyStateBindingModifiers("\$entangle('{$statePath}')") }},
            uploading: false,
            init() {
                window.addEventListener('image-picker:selected', (e) => {
                    if (e.detail?.statePath !== @js($statePath)) return;
                    this.call('selectImage', { target: e.detail.target, mediaId: e.detail.mediaId });
                });
            },
            call(method, args = {}) {
                return $wire.callSchemaComponentMethod(@js($key), method, args);
            },
            pick() {
                $wire.mountAction('select', { statePath: @js($statePath) }, { schemaComponent: @js($key) });
            },
            item(key) {
                return this.state?.items?.find((item) => item.key === key) ?? {};
            },
            async upload(files) {
                files = Array.from(files ?? []);
                if (! files.length) return;
                this.uploading = true;
                try {
                    for (const file of files) {
                        await new Promise((resolve) => $wire.upload(
                            @js('componentFileAttachments.' . $statePath),
                            file,
                            () => this.call('storeUploadedImage', { target: 'append' }).finally(resolve),
                            resolve,
                        ));
                    }
                } finally {
                    this.uploading = false;
                }
            },
        }"
    >
        <input type="file" multiple accept="image/*" class="fmm-upload-input" x-ref="upload" x-on:change="upload($event.target.files); $event.target.value = null" />

        @if (count($items))
            <ul
                class="fmm-images-grid"
                x-sortable
                data-sortable-animation-duration="300"
                x-on:end.stop="call('reorderImages', { keys: $event.target.sortable.toArray() })"
            >
                @foreach ($items as $item)
                    <li
                        class="fmm-images-item"
                        wire:key="fmm-images-{{ $statePath }}-{{ $item['key'] }}"
                        wire:ignore.self
                        x-sortable-item="{{ $item['key'] }}"
                    >
                        <div class="fmm-images-thumb" x-sortable-handle title="{{ __('Drag to reorder') }}">
                            <img src="{{ $thumbnails[$item['media_id']] ?? '' }}" alt="{{ $item['alt'] }}" draggable="false" />
                            <x-filament::icon-button
                                class="fmm-images-remove"
                                icon="heroicon-o-trash"
                                color="danger"
                                size="sm"
                                :label="__('Remove')"
                                x-on:click.stop="call('removeImage', { key: @js($item['key']) })"
                            />
                        </div>
                        <input
                            type="text"
                            class="fi-input fmm-alt-input"
                            x-model="item(@js($item['key'])).alt"
                            placeholder="{{ __('Alt text') }}"
                            aria-label="{{ __('Alt text') }}"
                        />
                    </li>
                @endforeach
            </ul>
        @else
            <p class="fmm-images-empty">{{ __('No images yet.') }}</p>
        @endif

        <div class="fmm-picker-buttons">
            <x-filament::button icon="heroicon-o-photo" color="gray" x-on:click="pick()">
                {{ __('Add images') }}
            </x-filament::button>
            <x-filament::button icon="heroicon-o-arrow-up-tray" color="gray" x-on:click="$refs.upload.click()" x-bind:disabled="uploading">
                {{ __('Upload') }}
            </x-filament::button>
            <span class="fmm-picker-uploading" x-cloak x-show="uploading">{{ __('Uploading…') }}</span>
        </div>
    </div>
</x-dynamic-component>

@php
    use Illuminate\Support\Number;

    $statePath = $getStatePath();
    $key = $getKey();
    $media = $field->getSelectedMedia();
    $hasValue = filled($field->getState());
    $format = $media ? strtoupper(pathinfo($media->file_name, PATHINFO_EXTENSION)) : null;
@endphp

<x-dynamic-component :component="$getFieldWrapperView()" :field="$field">
    <div
        class="fmm-document-picker"
        wire:key="fmm-document-picker-{{ $statePath }}"
        {{-- Alpine removes .window listeners with the element, so a rebuilt field never listens twice. --}}
        x-on:image-picker:selected.window="selected($event.detail)"
        x-on:image-picker:close.window="closed($event.detail)"
        x-data="{
            selected(detail) {
                if (detail?.statePath !== @js($statePath)) return;
                $wire.callSchemaComponentMethod(@js($key), 'selectDocument', { mediaId: detail.mediaId });
            },
            closed(detail) {
                if (detail?.statePath !== @js($statePath)) return;
                $wire.unmountAction();
            },
            pick() {
                $wire.mountAction('select', { statePath: @js($statePath) }, { schemaComponent: @js($key) });
            },
        }"
    >
        @if (! $hasValue)
            <div class="fmm-picker-buttons">
                <x-filament::button icon="heroicon-o-document" color="gray" x-on:click="pick()">
                    {{ __('filament-media-manager::media.picker.pick_from_library') }}
                </x-filament::button>
            </div>
        @else
            <div class="fmm-document-card">
                @if ($media)
                    <x-filament::icon icon="heroicon-o-document-text" class="fmm-document-icon" />
                    <div class="fmm-document-meta">
                        <span class="fmm-document-name">{{ $media->file_name }}</span>
                        <span class="fmm-document-info">{{ $format }} · {{ Number::fileSize($media->size) }}</span>
                    </div>
                @else
                    <span class="fmm-document-missing">{{ __('filament-media-manager::media.picker.file_missing') }}</span>
                @endif
                <div class="fmm-picker-actions">
                    <x-filament::button size="sm" color="gray" icon="heroicon-o-document" x-on:click="pick()">
                        {{ __('filament-media-manager::media.picker.change') }}
                    </x-filament::button>
                    <x-filament::button size="sm" color="danger" icon="heroicon-o-trash" x-on:click="$wire.callSchemaComponentMethod(@js($key), 'removeDocument')">
                        {{ __('filament-media-manager::media.picker.remove') }}
                    </x-filament::button>
                </div>
            </div>
        @endif
    </div>
</x-dynamic-component>

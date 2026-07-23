@php
    use MarvelousDigital\FilamentMediaManager\Enums\ConversionStatus;
    use MarvelousDigital\FilamentMediaManager\Models\MediaFieldValue;

    $statePath = $getStatePath();
    $key = $getKey();
    $value = ($id = $getState()) ? MediaFieldValue::with('conversions')->find($id) : null;
@endphp

<x-dynamic-component :component="$getFieldWrapperView()" :field="$field">
    <div
        class="fmm-image-picker"
        x-data="{
            open: false,
            init() {
                window.addEventListener('image-picker:selected', (e) => {
                    if (e.detail?.statePath !== @js($statePath)) return;
                    $wire.set(@js($statePath), e.detail.valueId);
                    this.open = false;
                });
                window.addEventListener('image-picker:close', () => { this.open = false; });
            },
        }"
        wire:key="fmm-picker-{{ $statePath }}"
    >
        @if ($value)
            <div class="fmm-picker-preview">
                <img src="{{ $value->url() }}" alt="{{ $value->alt_text }}" />
                <div class="fmm-picker-actions">
                    <x-filament::button size="sm" color="gray" icon="heroicon-o-arrow-path" x-on:click="open = true">
                        {{ __('Change') }}
                    </x-filament::button>
                    <x-filament::button size="sm" color="danger" icon="heroicon-o-trash" x-on:click="$wire.set(@js($statePath), null)">
                        {{ __('Remove') }}
                    </x-filament::button>
                </div>
            </div>

            <div class="fmm-picker-alt">
                <label for="fmm-alt-{{ $value->id }}">{{ __('Alt text') }}</label>
                <input
                    id="fmm-alt-{{ $value->id }}"
                    type="text"
                    class="fi-input fmm-alt-input"
                    value="{{ $value->alt_text }}"
                    placeholder="{{ __('Describe this image') }}"
                    x-on:change="$wire.mountAction('updateAlt', { alt: $event.target.value }, { schemaComponent: @js($key) })"
                />
            </div>

            @if ($value->conversions->isNotEmpty())
                <div class="fmm-focus-grid">
                    @foreach ($value->conversions as $conversion)
                        <div
                            class="fmm-focus-editor"
                            wire:key="conv-{{ $conversion->id }}"
                            x-data="{
                                fx: {{ $conversion->focus_x }},
                                fy: {{ $conversion->focus_y }},
                                dragging: false,
                                pointFromEvent(e) {
                                    const r = $refs.img.getBoundingClientRect();
                                    this.fx = Math.min(1, Math.max(0, (e.clientX - r.left) / r.width));
                                    this.fy = Math.min(1, Math.max(0, (e.clientY - r.top) / r.height));
                                },
                                start(e) {
                                    this.dragging = true;
                                    this.pointFromEvent(e);
                                },
                                move(e) {
                                    if (! this.dragging) return;
                                    this.pointFromEvent(e);
                                },
                                end() {
                                    if (! this.dragging) return;
                                    this.dragging = false;
                                    this.save();
                                },
                                save() {
                                    $wire.mountAction('updateFocus', {
                                        conversion: {{ $conversion->id }},
                                        x: this.fx,
                                        y: this.fy,
                                    }, { schemaComponent: @js($key) });
                                },
                            }"
                        >
                            <span class="fmm-focus-label">
                                {{ $conversion->variation }} ({{ $conversion->width }}&times;{{ $conversion->height }})
                            </span>

                            <div
                                class="fmm-focus-image"
                                x-on:pointerdown.prevent="start($event); $event.target.setPointerCapture?.($event.pointerId)"
                                x-on:pointermove="move($event)"
                                x-on:pointerup="end()"
                                x-on:pointercancel="end()"
                            >
                                <img x-ref="img" src="{{ $value->url() }}" alt="" draggable="false" />
                                <span class="fmm-focus-marker" x-bind:style="`left:${fx * 100}%;top:${fy * 100}%`"></span>
                            </div>

                            <div class="fmm-focus-preview" style="aspect-ratio: {{ $conversion->width }} / {{ $conversion->height }}">
                                <img
                                    src="{{ $value->url() }}"
                                    alt=""
                                    x-bind:style="`object-position:${fx * 100}% ${fy * 100}%`"
                                />
                            </div>

                            @if ($conversion->status === ConversionStatus::Failed)
                                <x-filament::button
                                    size="sm"
                                    color="warning"
                                    icon="heroicon-o-arrow-path"
                                    x-on:click="$wire.mountAction('retryConversion', { conversion: {{ $conversion->id }} }, { schemaComponent: @js($key) })"
                                >
                                    {{ __('Retry') }}
                                </x-filament::button>
                            @elseif ($conversion->status !== ConversionStatus::Completed)
                                <span class="fmm-focus-pending">{{ __('Generating…') }}</span>
                            @endif
                        </div>
                    @endforeach
                </div>
            @endif
        @else
            <x-filament::button icon="heroicon-o-photo" color="gray" x-on:click="open = true">
                {{ __('Pick image') }}
            </x-filament::button>
        @endif

        <input type="hidden" {{ $applyStateBindingModifiers('wire:model') }}="{{ $statePath }}" />

        <template x-teleport="body">
            <x-filament::modal :id="'fmm-image-picker-' . $statePath" width="5xl" x-model="open">
                <x-slot name="heading">{{ __('Select an image') }}</x-slot>

                <template x-if="open">
                    <div>
                        @livewire(
                            'filament-media-manager::media-picker-browser',
                            ['variations' => $getVariations(), 'statePath' => $statePath],
                            key($statePath . '-browser')
                        )
                    </div>
                </template>
            </x-filament::modal>
        </template>
    </div>
</x-dynamic-component>

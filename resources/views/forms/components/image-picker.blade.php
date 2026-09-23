@php
    use MarvelousDigital\FilamentMediaManager\Enums\ConversionStatus;
    use MarvelousDigital\FilamentMediaManager\Models\MediaFieldValue;

    $statePath = $getStatePath();
    $key = $getKey();
    $value = ($id = $getState()) ? MediaFieldValue::with('conversions')->find($id) : null;
    $isGenerating = $value?->conversions->contains(fn ($conversion): bool => $conversion->status === ConversionStatus::Pending) ?? false;
@endphp

<x-dynamic-component :component="$getFieldWrapperView()" :field="$field">
    <div
        class="fmm-image-picker"
        x-data="{
            init() {
                window.addEventListener('image-picker:selected', (e) => {
                    if (e.detail?.statePath !== @js($statePath)) return;
                    this.$wire.set(@js($statePath), e.detail.valueId);
                    this.$wire.unmountAction();
                });
                window.addEventListener('image-picker:close', () => this.$wire.unmountAction());
            },
            open() {
                this.$wire.mountAction('select', {
                    statePath: @js($statePath),
                    variations: @js($getVariations()),
                }, { schemaComponent: @js($key) });
            },
        }"
        wire:key="fmm-picker-{{ $statePath }}"
        @if ($isGenerating) wire:poll.3s @endif
    >
        @if ($value)
            <div class="fmm-picker-preview">
                <img src="{{ $value->url() }}" alt="{{ $value->alt_text }}" />
                <div class="fmm-picker-actions">
                    <x-filament::button size="sm" color="gray" icon="heroicon-o-arrow-path" x-on:click="open()">
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
                                zoom: {{ $conversion->zoom }},
                                maxSafeZoom: @js($conversion->maxSafeZoom()),
                                sourceW: {{ (int) ($value->source->width ?? 0) }},
                                sourceH: {{ (int) ($value->source->height ?? 0) }},
                                targetW: {{ $conversion->width }},
                                targetH: {{ $conversion->height }},
                                dragging: false,
                                get overZoomed() {
                                    return this.maxSafeZoom !== null && this.zoom > this.maxSafeZoom + 0.001;
                                },
                                cropBox() {
                                    const sw = this.sourceW, sh = this.sourceH;
                                    const ta = this.targetW / this.targetH, sa = sw / sh;
                                    let cw, ch;
                                    if (sa > ta) { ch = sh; cw = Math.round(sh * ta); }
                                    else { cw = sw; ch = Math.round(sw / ta); }
                                    const z = Math.max(1, this.zoom);
                                    cw = Math.round(cw / z);
                                    ch = Math.round(ch / z);
                                    const clamp = (v, min, max) => Math.max(min, Math.min(max, v));
                                    const x = clamp(Math.round(this.fx * sw - cw / 2), 0, sw - cw);
                                    const y = clamp(Math.round(this.fy * sh - ch / 2), 0, sh - ch);
                                    return { x, y, width: cw, height: ch };
                                },
                                previewStyle() {
                                    if (! this.sourceW || ! this.sourceH) {
                                        return `object-fit:cover;object-position:${this.fx * 100}% ${this.fy * 100}%`;
                                    }
                                    const b = this.cropBox();
                                    return `position:absolute;max-width:none;height:auto;` +
                                        `width:${this.sourceW / b.width * 100}%;` +
                                        `left:${-b.x / b.width * 100}%;` +
                                        `top:${-b.y / b.height * 100}%`;
                                },
                                cropRectStyle() {
                                    if (! this.sourceW || ! this.sourceH) { return 'display:none'; }
                                    const b = this.cropBox();
                                    return `left:${b.x / this.sourceW * 100}%;top:${b.y / this.sourceH * 100}%;` +
                                        `width:${b.width / this.sourceW * 100}%;height:${b.height / this.sourceH * 100}%`;
                                },
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
                                        zoom: this.zoom,
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
                                <span class="fmm-focus-crop-rect" x-bind:style="cropRectStyle()"></span>
                                <span class="fmm-focus-marker" x-bind:style="`left:${fx * 100}%;top:${fy * 100}%`"></span>
                            </div>

                            <div class="fmm-focus-preview" style="aspect-ratio: {{ $conversion->width }} / {{ $conversion->height }}">
                                <img
                                    src="{{ $value->url() }}"
                                    alt=""
                                    x-bind:style="previewStyle()"
                                />
                            </div>

                            <div class="fmm-zoom">
                                <label class="fmm-zoom-label" for="fmm-zoom-{{ $conversion->id }}">
                                    {{ __('Zoom') }}
                                    <span class="fmm-zoom-value" x-text="`${zoom.toFixed(1)}×`"></span>
                                </label>
                                <input
                                    id="fmm-zoom-{{ $conversion->id }}"
                                    type="range"
                                    class="fmm-zoom-slider"
                                    min="1"
                                    max="5"
                                    step="0.1"
                                    x-model.number="zoom"
                                    x-on:change="save()"
                                />
                                <p class="fmm-zoom-warning" x-cloak x-show="overZoomed">
                                    {{ __('Zoomed past the source resolution — the image will be upscaled and may look soft.') }}
                                </p>
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
            <x-filament::button icon="heroicon-o-photo" color="gray" x-on:click="open()">
                {{ __('Pick image') }}
            </x-filament::button>
        @endif

        <input type="hidden" {{ $applyStateBindingModifiers('wire:model') }}="{{ $statePath }}" />
    </div>
</x-dynamic-component>

@php
    use MarvelousDigital\FilamentMediaManager\Enums\ConversionStatus;
    use MarvelousDigital\FilamentMediaManager\Support\Ratio;

    $statePath = $getStatePath();
    $key = $getKey();
    $state = $getState();
    $variations = $getVariations();
    $hasImage = is_array($state) && filled($state['media_id'] ?? null);
    $media = $field->getMediaPayload();
    $saved = $field->getSavedValue();
    $isGenerating = $saved?->conversions->contains(fn ($conversion): bool => $conversion->status === ConversionStatus::Pending) ?? false;
@endphp

<x-dynamic-component :component="$getFieldWrapperView()" :field="$field">
    <div
        class="fmm-image-picker"
        wire:key="fmm-picker-{{ $statePath }}"
        @if ($isGenerating) wire:poll.3s @endif
        x-data="{
            state: $wire.{{ $applyStateBindingModifiers("\$entangle('{$statePath}')") }},
            media: @js($media),
            targets: @js($variations),
            open: null,
            uploading: false,
            init() {
                window.addEventListener('image-picker:selected', (e) => {
                    if (e.detail?.statePath !== @js($statePath)) return;
                    this.call('selectImage', { target: e.detail.target, mediaId: e.detail.mediaId });
                    $wire.unmountAction();
                });
                window.addEventListener('image-picker:close', () => $wire.unmountAction());
            },
            call(method, args = {}) {
                return $wire.callSchemaComponentMethod(@js($key), method, args);
            },
            pick(target) {
                $wire.mountAction('select', { statePath: @js($statePath), target }, { schemaComponent: @js($key) });
            },
            upload(target, files) {
                const file = files?.[0];
                if (! file) return;
                this.uploading = true;
                $wire.upload(
                    @js('componentFileAttachments.' . $statePath),
                    file,
                    () => this.call('storeUploadedImage', { target }).finally(() => { this.uploading = false; }),
                    () => { this.uploading = false; },
                );
            },
            touch() {
                this.state.dirty = true;
            },
            sourceOf(name) {
                const variant = this.state?.variants?.[name];
                return this.media[variant?.media_id ?? this.state?.media_id] ?? null;
            },
            hasSize(name) {
                const source = this.sourceOf(name);
                return !! (source?.width && source?.height);
            },
            cropBox(name) {
                const source = this.sourceOf(name);
                const variant = this.state.variants[name];
                const [tw, th] = this.targets[name];
                const sw = source.width, sh = source.height, ta = tw / th;
                let cw, ch;
                if (sw / sh > ta) { ch = sh; cw = Math.round(sh * ta); }
                else { cw = sw; ch = Math.round(sw / ta); }
                const z = Math.max(1, variant.zoom);
                cw = Math.round(cw / z);
                ch = Math.round(ch / z);
                const clamp = (v, min, max) => Math.max(min, Math.min(max, v));
                return {
                    x: clamp(Math.round(variant.focus_x * sw - cw / 2), 0, sw - cw),
                    y: clamp(Math.round(variant.focus_y * sh - ch / 2), 0, sh - ch),
                    width: cw,
                    height: ch,
                };
            },
            previewStyle(name) {
                const variant = this.state.variants[name];
                if (! this.hasSize(name)) {
                    return `width:100%;height:100%;object-fit:cover;object-position:${variant.focus_x * 100}% ${variant.focus_y * 100}%`;
                }
                const b = this.cropBox(name), source = this.sourceOf(name);
                return `position:absolute;max-width:none;height:auto;` +
                    `width:${source.width / b.width * 100}%;` +
                    `left:${-b.x / b.width * 100}%;` +
                    `top:${-b.y / b.height * 100}%`;
            },
            cropRectStyle(name) {
                if (! this.hasSize(name)) return 'display:none';
                const b = this.cropBox(name), source = this.sourceOf(name);
                return `left:${b.x / source.width * 100}%;top:${b.y / source.height * 100}%;` +
                    `width:${b.width / source.width * 100}%;height:${b.height / source.height * 100}%`;
            },
            overZoomed(name) {
                if (! this.hasSize(name)) return false;
                const source = this.sourceOf(name);
                const [tw, th] = this.targets[name];
                const ta = tw / th;
                const coverWidth = source.width / source.height > ta ? Math.round(source.height * ta) : source.width;
                return this.state.variants[name].zoom > coverWidth / tw + 0.001;
            },
            setFocus(name, event) {
                const rect = this.$refs['img-' + name].getBoundingClientRect();
                const variant = this.state.variants[name];
                variant.focus_x = Math.min(1, Math.max(0, (event.clientX - rect.left) / rect.width));
                variant.focus_y = Math.min(1, Math.max(0, (event.clientY - rect.top) / rect.height));
                this.touch();
            },
        }"
    >
        <input type="file" accept="image/*" class="fmm-upload-input" x-ref="upload-main" x-on:change="upload('main', $event.target.files); $event.target.value = null" />

        @if (! $hasImage)
            <div class="fmm-picker-empty">
                <div class="fmm-picker-buttons">
                    <x-filament::button icon="heroicon-o-arrow-up-tray" color="gray" x-on:click="$refs['upload-main'].click()" x-bind:disabled="uploading">
                        {{ __('Upload') }}
                    </x-filament::button>
                    <x-filament::button icon="heroicon-o-photo" color="gray" x-on:click="pick('main')">
                        {{ __('Pick from library') }}
                    </x-filament::button>
                    <span class="fmm-picker-uploading" x-cloak x-show="uploading">{{ __('Uploading…') }}</span>
                </div>

                <p class="fmm-formats-label">{{ __('Required formats') }}</p>
                <div class="fmm-formats">
                    @foreach ($variations as $name => [$width, $height])
                        <div class="fmm-format" wire:key="fmm-format-{{ $statePath }}-{{ $name }}">
                            <span class="fmm-format-box" style="aspect-ratio: {{ $width }} / {{ $height }}"></span>
                            <span class="fmm-format-name">{{ $name }} · {{ Ratio::reduce($width, $height) }}</span>
                            <span class="fmm-format-size">{{ $width }}×{{ $height }}</span>
                        </div>
                    @endforeach
                </div>
            </div>
        @else
            <div class="fmm-picker-preview">
                <img src="{{ $media[$state['media_id']]['url'] ?? '' }}" alt="{{ $state['alt'] }}" />
                <div class="fmm-picker-actions">
                    <x-filament::button size="sm" color="gray" icon="heroicon-o-photo" x-on:click="pick('main')">
                        {{ __('Change') }}
                    </x-filament::button>
                    <x-filament::button size="sm" color="gray" icon="heroicon-o-arrow-up-tray" x-on:click="$refs['upload-main'].click()" x-bind:disabled="uploading">
                        {{ __('Upload new') }}
                    </x-filament::button>
                    <x-filament::button size="sm" color="danger" icon="heroicon-o-trash" x-on:click="call('removeImage')">
                        {{ __('Remove') }}
                    </x-filament::button>
                    <span class="fmm-picker-uploading" x-cloak x-show="uploading">{{ __('Uploading…') }}</span>
                </div>
            </div>

            <div class="fmm-picker-alt">
                <label for="fmm-alt-{{ $statePath }}">{{ __('Alt text') }}</label>
                <input id="fmm-alt-{{ $statePath }}" type="text" class="fi-input fmm-alt-input" x-model="state.alt" x-on:input="touch()" placeholder="{{ __('Describe this image') }}" />
            </div>

            <p class="fmm-unsaved" x-cloak x-show="state?.dirty">
                {{ __('Not saved yet — image changes go live when you save.') }}
            </p>

            <div class="fmm-variants">
                @foreach ($variations as $name => [$width, $height])
                    @php
                        $variant = $state['variants'][$name] ?? [];
                        $isOverridden = filled($variant['media_id'] ?? null);
                        $conversion = $saved?->conversion($name);
                    @endphp

                    <div class="fmm-variant" wire:key="fmm-variant-{{ $statePath }}-{{ $name }}">
                        <div class="fmm-variant-header">
                            <span class="fmm-variant-name">{{ $name }} · {{ Ratio::reduce($width, $height) }}</span>
                            @if ($isOverridden)
                                <span class="fmm-variant-badge">{{ __('Own image') }}</span>
                            @endif
                        </div>

                        <div class="fmm-variant-thumb" style="aspect-ratio: {{ $width }} / {{ $height }}">
                            <img x-bind:src="sourceOf(@js($name))?.url" x-bind:style="previewStyle(@js($name))" alt="" />
                        </div>

                        <div class="fmm-variant-status">
                            @if ($conversion?->status === ConversionStatus::Failed)
                                <x-filament::button
                                    size="xs"
                                    color="warning"
                                    icon="heroicon-o-arrow-path"
                                    x-on:click="$wire.mountAction('retryConversion', { conversion: {{ $conversion->id }} }, { schemaComponent: @js($key) })"
                                >
                                    {{ __('Retry') }}
                                </x-filament::button>
                            @elseif ($conversion?->status === ConversionStatus::Pending)
                                <span class="fmm-variant-pending">{{ __('Generating…') }}</span>
                            @elseif ($conversion?->status === ConversionStatus::Completed)
                                <span class="fmm-variant-ready">{{ __('Ready') }}</span>
                            @endif
                        </div>

                        <div class="fmm-variant-actions">
                            <x-filament::button size="xs" color="gray" x-on:click="open = open === @js($name) ? null : @js($name)">
                                {{ __('Reframe') }}
                            </x-filament::button>
                            <x-filament::button size="xs" color="gray" x-on:click="pick(@js($name))">
                                {{ __('Use different image') }}
                            </x-filament::button>
                            <x-filament::button size="xs" color="gray" icon="heroicon-o-arrow-up-tray" x-on:click="$refs[@js('upload-' . $name)].click()" x-bind:disabled="uploading" :label-sr-only="true">
                                {{ __('Upload for this format') }}
                            </x-filament::button>
                            @if ($isOverridden)
                                <x-filament::button size="xs" color="gray" x-on:click="call('useMainImage', { variation: @js($name) })">
                                    {{ __('Use main image') }}
                                </x-filament::button>
                            @endif
                            <input type="file" accept="image/*" class="fmm-upload-input" x-ref="upload-{{ $name }}" x-on:change="upload(@js($name), $event.target.files); $event.target.value = null" />
                        </div>

                        @if ($isOverridden)
                            <div class="fmm-picker-alt">
                                <label for="fmm-alt-{{ $statePath }}-{{ $name }}">{{ __('Alt text for this format') }}</label>
                                <input id="fmm-alt-{{ $statePath }}-{{ $name }}" type="text" class="fi-input fmm-alt-input" x-model="state.variants[@js($name)].alt" x-on:input="touch()" />
                            </div>
                        @endif

                        <div class="fmm-reframe" x-cloak x-show="open === @js($name)">
                            <div
                                class="fmm-focus-image"
                                x-data="{ dragging: false }"
                                x-on:pointerdown.prevent="dragging = true; $event.target.setPointerCapture?.($event.pointerId); setFocus(@js($name), $event)"
                                x-on:pointermove="dragging && setFocus(@js($name), $event)"
                                x-on:pointerup="dragging = false"
                                x-on:pointercancel="dragging = false"
                            >
                                <img x-ref="img-{{ $name }}" x-bind:src="sourceOf(@js($name))?.url" alt="" draggable="false" />
                                <span class="fmm-focus-crop-rect" x-bind:style="cropRectStyle(@js($name))"></span>
                                <span class="fmm-focus-marker" x-bind:style="`left:${state.variants[@js($name)].focus_x * 100}%;top:${state.variants[@js($name)].focus_y * 100}%`"></span>
                            </div>

                            <div class="fmm-zoom">
                                <label class="fmm-zoom-label" for="fmm-zoom-{{ $statePath }}-{{ $name }}">
                                    {{ __('Zoom') }}
                                    <span class="fmm-zoom-value" x-text="`${Number(state.variants[@js($name)].zoom).toFixed(1)}×`"></span>
                                </label>
                                <input
                                    id="fmm-zoom-{{ $statePath }}-{{ $name }}"
                                    type="range"
                                    class="fmm-zoom-slider"
                                    min="1"
                                    max="5"
                                    step="0.1"
                                    x-model.number="state.variants[@js($name)].zoom"
                                    x-on:input="touch()"
                                />
                                <p class="fmm-zoom-warning" x-cloak x-show="overZoomed(@js($name))">
                                    {{ __('Zoomed past the source resolution — the image will be upscaled and may look soft.') }}
                                </p>
                            </div>
                        </div>
                    </div>
                @endforeach
            </div>
        @endif
    </div>
</x-dynamic-component>

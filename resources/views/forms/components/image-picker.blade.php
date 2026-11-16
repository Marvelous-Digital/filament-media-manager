@php
    use MarvelousDigital\FilamentMediaManager\Enums\ConversionStatus;
    use Illuminate\Support\Js;
    use MarvelousDigital\FilamentMediaManager\Support\Ratio;

    $statePath = $getStatePath();
    $key = $getKey();
    $state = $field->getVerifiedState();
    $variations = $getVariations();
    $hasImage = filled($state['media_id'] ?? null);
    $media = $field->getMediaPayload();
    $saved = $field->getSavedValue();
    $isGenerating = $saved?->conversions->contains(fn ($conversion): bool => $conversion->status === ConversionStatus::Pending) ?? false;
@endphp

<x-dynamic-component :component="$getFieldWrapperView()" :field="$field">
    <div
        class="fmm-image-picker"
        wire:key="fmm-picker-{{ $statePath }}"
        @if ($isGenerating) wire:poll.3s @endif
        {{-- Alpine removes .window listeners with the element, so a rebuilt field never listens twice. --}}
        x-on:image-picker:selected.window="selected($event.detail)"
        x-on:image-picker:close.window="closed($event.detail)"
        x-data="{
            state: $wire.{{ $applyStateBindingModifiers("\$entangle('{$statePath}')") }},
            // Filled by the hidden x-init below: kept out of this attribute so a re-render that changes
            // the media (another image picked, a signed URL) does not re-create the component.
            media: {},
            targets: @js($variations),
            // UI only, never saved: the variation being reframed on the stage, null for the base image.
            // Not `selected`: that name is the library pick handler below.
            active: @js(array_key_first($variations)),
            uploading: false,
            selected(detail) {
                if (detail?.statePath !== @js($statePath)) return;
                this.call('selectImage', { target: detail.target, mediaId: detail.mediaId });
            },
            closed(detail) {
                if (detail?.statePath !== @js($statePath)) return;
                $wire.unmountAction();
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
            stageSource() {
                return this.active === null ? this.media[this.state?.media_id] ?? null : this.sourceOf(this.active);
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
            // A variation follows the base focus point until it is moved on its own or uses its own image.
            follows(name) {
                const variant = this.state.variants[name];
                return ! variant.custom && ! variant.media_id;
            },
            focusOf(name) {
                return name === null ? this.state : this.state.variants[name];
            },
            setFocus(name, event) {
                const rect = this.$refs.stage.getBoundingClientRect();
                const x = Math.min(1, Math.max(0, (event.clientX - rect.left) / rect.width));
                const y = Math.min(1, Math.max(0, (event.clientY - rect.top) / rect.height));
                if (name === null) {
                    this.state.focus_x = x;
                    this.state.focus_y = y;
                    for (const variant of Object.keys(this.targets)) {
                        if (this.follows(variant)) Object.assign(this.state.variants[variant], { focus_x: x, focus_y: y });
                    }
                } else {
                    Object.assign(this.state.variants[name], { focus_x: x, focus_y: y, custom: true });
                }
                this.touch();
            },
            useBaseFocus(name) {
                Object.assign(this.state.variants[name], { focus_x: this.state.focus_x, focus_y: this.state.focus_y, zoom: 1, custom: false });
                this.touch();
            },
        }"
    >
        <span hidden data-fmm-media x-init="media = @js($media)"></span>

        <input type="file" accept="image/*" class="fmm-upload-input" x-ref="upload-main" x-on:change="upload('main', $event.target.files); $event.target.value = null" />

        @if (! $hasImage)
            <div class="fmm-picker-empty">
                <div class="fmm-picker-buttons">
                    <x-filament::button icon="heroicon-o-arrow-up-tray" color="gray" x-on:click="$refs['upload-main'].click()" x-bind:disabled="uploading">
                        {{ __('filament-media-manager::media.picker.upload') }}
                    </x-filament::button>
                    <x-filament::button icon="heroicon-o-photo" color="gray" x-on:click="pick('main')">
                        {{ __('filament-media-manager::media.picker.pick_from_library') }}
                    </x-filament::button>
                    <span class="fmm-picker-uploading" x-cloak x-show="uploading">{{ __('filament-media-manager::media.picker.uploading') }}</span>
                </div>

                <p class="fmm-formats-label">{{ __('filament-media-manager::media.picker.required_formats') }}</p>
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
            <div class="fmm-picker-header">
                <span class="fmm-picker-uploading" x-cloak x-show="uploading">{{ __('filament-media-manager::media.picker.uploading') }}</span>
                <x-filament::dropdown placement="bottom-end">
                    <x-slot name="trigger">
                        <x-filament::icon-button icon="heroicon-m-ellipsis-horizontal" color="gray" :label="__('filament-media-manager::media.picker.more_actions')" />
                    </x-slot>

                    <x-filament::dropdown.list>
                        <x-filament::dropdown.list.item icon="heroicon-o-photo" x-on:click="pick('main')">
                            {{ __('filament-media-manager::media.picker.change_base') }}
                        </x-filament::dropdown.list.item>
                        <x-filament::dropdown.list.item icon="heroicon-o-arrow-up-tray" x-on:click="$refs['upload-main'].click()" x-bind:disabled="uploading">
                            {{ __('filament-media-manager::media.picker.upload_new_base') }}
                        </x-filament::dropdown.list.item>
                        <x-filament::dropdown.list.item icon="heroicon-o-trash" color="danger" x-on:click="call('removeImage')">
                            {{ __('filament-media-manager::media.picker.remove') }}
                        </x-filament::dropdown.list.item>
                    </x-filament::dropdown.list>
                </x-filament::dropdown>
            </div>

            @if (filled($variations))
                @php
                    $base = $media[$state['media_id']] ?? null;
                    $baseRatio = filled($base['width'] ?? null) && filled($base['height'] ?? null) ? "{$base['width']} / {$base['height']}" : '1 / 1';
                @endphp

                <div class="fmm-strip">
                    <button type="button" class="fmm-strip-item" data-fmm-thumb-base x-on:click="active = null" x-bind:aria-pressed="active === null">
                        <span class="fmm-strip-box" style="aspect-ratio: {{ $baseRatio }}">
                            <img src="{{ $base['url'] ?? '' }}" alt="" />
                        </span>
                        <span class="fmm-strip-name">{{ __('filament-media-manager::media.picker.base') }}</span>
                    </button>

                    @foreach ($variations as $name => [$width, $height])
                        @php
                            $isOverridden = filled($state['variants'][$name]['media_id'] ?? null);
                            $status = $saved?->conversion($name)?->status;
                        @endphp

                        <button
                            type="button"
                            class="fmm-strip-item"
                            data-fmm-thumb="{{ $name }}"
                            wire:key="fmm-thumb-{{ $statePath }}-{{ $name }}"
                            x-on:click="active = @js($name)"
                            x-bind:aria-pressed="active === @js($name)"
                        >
                            <span class="fmm-strip-box" style="aspect-ratio: {{ $width }} / {{ $height }}">
                                <img x-bind:src="sourceOf(@js($name))?.url" x-bind:style="previewStyle(@js($name))" alt="" />

                                @if ($isOverridden)
                                    <span class="fmm-strip-badge" title="{{ __('filament-media-manager::media.picker.own_image') }}">
                                        <x-filament::icon icon="heroicon-m-photo" class="fmm-strip-badge-icon" />
                                    </span>
                                @else
                                    <span class="fmm-strip-badge" x-cloak x-show="! follows(@js($name))" title="{{ __('filament-media-manager::media.picker.own_focus') }}">
                                        <x-filament::icon icon="heroicon-m-viewfinder-circle" class="fmm-strip-badge-icon" />
                                    </span>
                                @endif

                                @if ($status === ConversionStatus::Failed)
                                    <span class="fmm-strip-dot" title="{{ __('filament-media-manager::media.picker.conversion_failed') }}"></span>
                                @else
                                    <span class="fmm-strip-dot" x-cloak x-show="overZoomed(@js($name))" title="{{ __('filament-media-manager::media.picker.over_zoomed') }}"></span>
                                @endif

                                @if ($status === ConversionStatus::Pending)
                                    <span class="fmm-strip-spinner" title="{{ __('filament-media-manager::media.picker.generating') }}">
                                        <x-filament::loading-indicator class="fmm-strip-spinner-icon" />
                                    </span>
                                @endif
                            </span>
                            <span class="fmm-strip-name">{{ $name }}</span>
                        </button>
                    @endforeach
                </div>
            @endif

            <div
                class="fmm-stage"
                x-bind:class="{ 'fmm-stage-editing': {{ filled($variations) ? 'true' : 'false' }} }"
                x-data="{ dragging: false }"
                @if (filled($variations))
                    x-on:pointerdown.prevent="dragging = true; $event.target.setPointerCapture?.($event.pointerId); setFocus(active, $event)"
                    x-on:pointermove="dragging && setFocus(active, $event)"
                @endif
                x-on:pointerup="dragging = false"
                x-on:pointercancel="dragging = false"
            >
                <img x-ref="stage" src="{{ $media[$state['media_id']]['url'] ?? '' }}" x-bind:src="stageSource()?.url" alt="{{ $state['alt'] }}" draggable="false" />
                @if (filled($variations))
                    <span class="fmm-stage-frame" x-cloak x-show="active !== null && hasSize(active)" x-bind:style="active !== null && hasSize(active) ? cropRectStyle(active) : ''"></span>
                    <span class="fmm-focus-marker" x-cloak x-bind:style="`left:${focusOf(active).focus_x * 100}%;top:${focusOf(active).focus_y * 100}%`"></span>
                @endif
            </div>

            @if (filled($variations))
                <p class="fmm-stage-hint" x-cloak x-show="active === null">{{ __('filament-media-manager::media.picker.base_focus_hint') }}</p>
            @endif

            @foreach ($variations as $name => [$width, $height])
                @php
                    $isOverridden = filled($state['variants'][$name]['media_id'] ?? null);
                    $conversion = $saved?->conversion($name);
                    // Blade does not compile @js() inside a component's attributes, so the buttons get the JS literals from here.
                    $jsName = (string) Js::from($name);
                    $jsUploadRef = (string) Js::from('upload-' . $name);
                @endphp

                <div class="fmm-crop-toolbar" data-fmm-toolbar="{{ $name }}" wire:key="fmm-toolbar-{{ $statePath }}-{{ $name }}" x-cloak x-show="active === @js($name)">
                    <p class="fmm-crop-toolbar-title">{{ $name }} · {{ Ratio::reduce($width, $height) }} · {{ $width }}×{{ $height }}</p>
                    @unless ($isOverridden)
                        <p class="fmm-crop-toolbar-note" x-show="follows(@js($name))">{{ __('filament-media-manager::media.picker.follows_base_focus') }}</p>
                    @endunless

                    <div class="fmm-zoom">
                        <label class="fmm-zoom-label" for="fmm-zoom-{{ $statePath }}-{{ $name }}">
                            {{ __('filament-media-manager::media.picker.zoom') }}
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
                            {{ __('filament-media-manager::media.picker.zoom_warning') }}
                        </p>
                    </div>

                    <div class="fmm-crop-toolbar-actions">
                        @if ($isOverridden)
                            <x-filament::button size="xs" color="gray" icon="heroicon-o-arrow-path" x-on:click="Object.assign(state.variants[{{ $jsName }}], { focus_x: 0.5, focus_y: 0.5, zoom: 1 }); touch()">
                                {{ __('filament-media-manager::media.picker.reset') }}
                            </x-filament::button>
                        @else
                            <x-filament::button size="xs" color="gray" icon="heroicon-o-viewfinder-circle" x-show="! follows({{ $jsName }})" x-on:click="useBaseFocus({{ $jsName }})">
                                {{ __('filament-media-manager::media.picker.use_base_focus') }}
                            </x-filament::button>
                        @endif
                        <x-filament::button size="xs" color="gray" icon="heroicon-o-photo" x-on:click="pick({{ $jsName }})">
                            {{ __('filament-media-manager::media.picker.use_another_image_for', ['name' => $name]) }}
                        </x-filament::button>
                        <x-filament::button size="xs" color="gray" icon="heroicon-o-arrow-up-tray" x-on:click="$refs[{{ $jsUploadRef }}].click()" x-bind:disabled="uploading">
                            {{ __('filament-media-manager::media.picker.upload_for', ['name' => $name]) }}
                        </x-filament::button>
                        @if ($isOverridden)
                            <x-filament::button size="xs" color="gray" icon="heroicon-o-arrow-uturn-left" x-on:click="call('useMainImage', { variation: {{ $jsName }} })">
                                {{ __('filament-media-manager::media.picker.back_to_base') }}
                            </x-filament::button>
                        @endif
                        @if ($conversion?->status === ConversionStatus::Failed)
                            <x-filament::button
                                size="xs"
                                color="warning"
                                icon="heroicon-o-arrow-path"
                                x-on:click="$wire.mountAction('retryConversion', { conversion: {{ $conversion->id }} }, { schemaComponent: {{ (string) Js::from($key) }} })"
                            >
                                {{ __('filament-media-manager::media.picker.retry') }}
                            </x-filament::button>
                        @endif
                        <input type="file" accept="image/*" class="fmm-upload-input" x-ref="upload-{{ $name }}" x-on:change="upload(@js($name), $event.target.files); $event.target.value = null" />
                    </div>

                    @if ($isOverridden)
                        <div class="fmm-picker-alt">
                            <label class="fi-fo-field-label" for="fmm-alt-{{ $statePath }}-{{ $name }}">
                                <span class="fi-fo-field-label-content">{{ __('filament-media-manager::media.picker.alt_text_for', ['name' => $name]) }}</span>
                            </label>
                            <x-filament::input.wrapper>
                                <x-filament::input id="fmm-alt-{{ $statePath }}-{{ $name }}" type="text" x-model="state.variants[{{ $jsName }}].alt" x-on:input="touch()" />
                            </x-filament::input.wrapper>
                        </div>
                    @endif
                </div>
            @endforeach

            <div class="fmm-picker-alt">
                <label class="fi-fo-field-label" for="fmm-alt-{{ $statePath }}">
                    <span class="fi-fo-field-label-content">{{ __('filament-media-manager::media.picker.alt_text') }}</span>
                </label>
                <x-filament::input.wrapper>
                    <x-filament::input id="fmm-alt-{{ $statePath }}" type="text" x-model="state.alt" x-on:input="touch()" :placeholder="__('filament-media-manager::media.picker.alt_text_placeholder')" />
                </x-filament::input.wrapper>
            </div>

            <p class="fmm-unsaved" x-cloak x-show="state?.dirty">
                {{ __('filament-media-manager::media.picker.unsaved') }}
            </p>

        @endif
    </div>
</x-dynamic-component>

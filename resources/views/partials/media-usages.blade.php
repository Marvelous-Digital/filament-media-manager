@php($sections = [
    'live' => ['label' => __('filament-media-manager::media.usage.live'), 'hint' => __('filament-media-manager::media.usage.live_hint'), 'color' => 'success', 'icon' => 'heroicon-m-globe-alt'],
    'draft' => ['label' => __('filament-media-manager::media.usage.draft'), 'hint' => __('filament-media-manager::media.usage.draft_hint'), 'color' => 'warning', 'icon' => 'heroicon-m-pencil-square'],
    'history' => ['label' => __('filament-media-manager::media.usage.history'), 'hint' => empty($groups['live']) && empty($groups['draft']) ? __('filament-media-manager::media.usage.history_hint') : __('filament-media-manager::media.usage.history_kept_hint'), 'color' => 'gray', 'icon' => 'heroicon-m-clock'],
])
<section class="fmm-usages">
    <h3 class="fmm-usages-title">{{ __('filament-media-manager::media.usage.used_in') }}</h3>

    @if (collect($groups)->flatten(1)->isEmpty())
        <p class="fmm-usages-empty">
            <x-filament::icon icon="heroicon-m-check-circle" class="fmm-usages-empty-icon" />
            {{ __('filament-media-manager::media.usage.not_used') }}
        </p>
    @else
        @foreach ($sections as $source => $section)
            @continue (empty($groups[$source]))
            @php($hidden = max(0, count($groups[$source]) - 5))
            <div class="fmm-usages-group" data-source="{{ $source }}" x-data="{ all: false }">
                <div class="fmm-usages-heading">
                    <x-filament::badge :color="$section['color']" :icon="$section['icon']">{{ $section['label'] }} · {{ count($groups[$source]) }}</x-filament::badge>
                    <span class="fmm-usages-hint">{{ $section['hint'] }}</span>
                </div>
                <ul class="fmm-usages-list">
                    @foreach ($groups[$source] as $group)
                        <li class="fmm-usages-item" @if ($loop->index >= 5) x-show="all" x-cloak @endif>
                            <div class="fmm-usages-main">
                                <div class="fmm-usages-name">
                                    @if ($group['owner']->url)
                                        <a href="{{ $group['owner']->url }}" target="_blank" rel="noopener" class="fmm-usages-link">
                                            {{ $group['owner']->title }}
                                            <x-filament::icon icon="heroicon-m-arrow-top-right-on-square" class="fmm-usages-link-icon" aria-hidden="true" />
                                            <span class="fmm-sr-only">({{ __('filament-media-manager::media.usage.opens_new_tab') }})</span>
                                        </a>
                                    @else
                                        <span>{{ $group['owner']->title }}</span>
                                    @endif
                                </div>
                                @if ($group['owner']->detail)
                                    <div class="fmm-usages-detail">{{ $group['owner']->detail }}</div>
                                @endif
                            </div>
                            @if ($group['locales'] !== [])
                                <div class="fmm-usages-locales">
                                    @foreach ($group['locales'] as $locale)
                                        <x-filament::badge color="gray" size="sm">{{ mb_strtoupper($locale) }}</x-filament::badge>
                                    @endforeach
                                </div>
                            @endif
                            @if ($group['owner']->children !== [])
                                <div class="fmm-usages-pages">
                                    <div class="fmm-usages-detail">{{ trans_choice('filament-media-manager::media.usage.shown_on', count($group['owner']->children)) }}</div>
                                    <ul class="fmm-usages-children">
                                        @foreach ($group['owner']->children as $child)
                                            <li>
                                                @if ($child->url)
                                                    <a href="{{ $child->url }}" target="_blank" rel="noopener" class="fmm-usages-link">{{ $child->title }}</a>
                                                @else
                                                    {{ $child->title }}
                                                @endif
                                            </li>
                                        @endforeach
                                    </ul>
                                </div>
                            @endif
                        </li>
                    @endforeach
                </ul>
                @if ($hidden > 0)
                    <button type="button" class="fmm-usages-more" x-on:click="all = ! all">
                        <span x-show="! all">{{ trans_choice('filament-media-manager::media.usage.show_more', $hidden, ['count' => $hidden]) }}</span>
                        <span x-show="all" x-cloak>{{ __('filament-media-manager::media.usage.show_less') }}</span>
                    </button>
                @endif
            </div>
        @endforeach
    @endif
</section>

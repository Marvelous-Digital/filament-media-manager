@php($sections = [
    'live' => __('filament-media-manager::media.usage.live'),
    'draft' => __('filament-media-manager::media.usage.draft'),
    'history' => __('filament-media-manager::media.usage.history'),
])
<div class="fmm-usages">
    <h3 class="fmm-usages-title">{{ __('filament-media-manager::media.usage.used_in') }}</h3>

    @if (collect($groups)->flatten(1)->isEmpty())
        <p class="fmm-usages-empty">{{ __('filament-media-manager::media.usage.not_used') }}</p>
    @else
        @foreach ($sections as $source => $label)
            @continue (empty($groups[$source]))
            <h4 class="fmm-usages-heading">{{ $label }}</h4>
            <ul class="fmm-usages-list">
                @foreach ($groups[$source] as $group)
                    <li>
                        @if ($group['owner']->url)
                            <a href="{{ $group['owner']->url }}" target="_blank" rel="noopener">{{ $group['owner']->title }}</a>
                        @else
                            <span>{{ $group['owner']->title }}</span>
                        @endif
                        @if ($group['owner']->detail)
                            <span class="fmm-usages-detail">{{ $group['owner']->detail }}</span>
                        @endif
                        @if ($group['locales'] !== [])
                            <span class="fmm-usages-locales">{{ collect($group['locales'])->map(fn ($locale) => mb_strtoupper($locale))->implode(', ') }}</span>
                        @endif
                        @if ($group['owner']->children !== [])
                            <span class="fmm-usages-detail">{{ trans_choice('filament-media-manager::media.usage.shown_on', count($group['owner']->children)) }}</span>
                            <ul class="fmm-usages-children">
                                @foreach ($group['owner']->children as $child)
                                    <li>
                                        @if ($child->url)
                                            <a href="{{ $child->url }}" target="_blank" rel="noopener">{{ $child->title }}</a>
                                        @else
                                            {{ $child->title }}
                                        @endif
                                    </li>
                                @endforeach
                            </ul>
                        @endif
                    </li>
                @endforeach
            </ul>
        @endforeach
    @endif
</div>

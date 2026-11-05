<div class="fmm-details">
    <div class="fmm-details-preview">
        @if ($media?->type === \MarvelousDigital\FilamentMediaManager\Enums\MediaType::Image && $media->url())
            <img src="{{ $media->url() }}" alt="{{ $media->alt_text ?? $media->name }}" />
        @else
            <x-filament::icon icon="heroicon-o-document" class="fmm-type-icon" />
        @endif
    </div>
    @if ($media)
        <dl class="fmm-details-facts">
            <div><dt>{{ __('filament-media-manager::media.details.file') }}</dt><dd>{{ $media->file_name }}</dd></div>
            <div><dt>{{ __('filament-media-manager::media.details.type') }}</dt><dd>{{ $media->mime_type }}</dd></div>
            <div><dt>{{ __('filament-media-manager::media.details.size') }}</dt><dd>{{ \Illuminate\Support\Number::fileSize($media->size) }}</dd></div>
            @if ($media->width)
                <div><dt>{{ __('filament-media-manager::media.details.dimensions') }}</dt><dd>{{ $media->width }} × {{ $media->height }}</dd></div>
            @endif
            <div><dt>{{ __('filament-media-manager::media.details.uploaded') }}</dt><dd>{{ $media->created_at->diffForHumans() }}</dd></div>
        </dl>
    @endif
</div>

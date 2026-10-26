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
            <div><dt>File</dt><dd>{{ $media->file_name }}</dd></div>
            <div><dt>Type</dt><dd>{{ $media->mime_type }}</dd></div>
            <div><dt>Size</dt><dd>{{ \Illuminate\Support\Number::fileSize($media->size) }}</dd></div>
            @if ($media->width)
                <div><dt>Dimensions</dt><dd>{{ $media->width }} × {{ $media->height }}</dd></div>
            @endif
            <div><dt>Uploaded</dt><dd>{{ $media->created_at->diffForHumans() }}</dd></div>
        </dl>
    @endif
</div>

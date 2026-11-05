<div>
    @livewire(
        'filament-media-manager::media-picker-browser',
        ['statePath' => $statePath, 'target' => $target, 'mimeTypes' => $mimeTypes ?? []],
        key($statePath . '-' . $target . '-browser')
    )
</div>

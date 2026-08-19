<div>
    @livewire(
        'filament-media-manager::media-picker-browser',
        ['variations' => $variations, 'statePath' => $statePath],
        key($statePath . '-browser')
    )
</div>

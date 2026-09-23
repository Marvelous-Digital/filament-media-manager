<div>
    @livewire(
        'filament-media-manager::media-picker-browser',
        ['statePath' => $statePath, 'target' => $target],
        key($statePath . '-' . $target . '-browser')
    )
</div>

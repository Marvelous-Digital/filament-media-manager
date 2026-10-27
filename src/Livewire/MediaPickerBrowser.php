<?php

namespace MarvelousDigital\FilamentMediaManager\Livewire;

use Illuminate\Contracts\View\View;
use Livewire\Component;
use Livewire\WithFileUploads;
use MarvelousDigital\FilamentMediaManager\Concerns\BrowsesMedia;
use MarvelousDigital\FilamentMediaManager\Models\Media;

class MediaPickerBrowser extends Component
{
    use BrowsesMedia;
    use WithFileUploads;

    public string $statePath = '';

    public string $target = 'main';

    protected function imagesOnly(): bool
    {
        return true;
    }

    public function select(int $mediaId): void
    {
        if (! Media::query()->whereKey($mediaId)->exists()) {
            return;
        }

        $this->dispatch('image-picker:selected', statePath: $this->statePath, target: $this->target, mediaId: $mediaId);

        // Appending (ImagesPicker) keeps the browser open so several images can be picked in one visit.
        if ($this->target !== 'append') {
            $this->dispatch('image-picker:close');
        }
    }

    public function render(): View
    {
        return view('filament-media-manager::media-picker-browser', $this->browseData());
    }
}

<?php

namespace MarvelousDigital\FilamentMediaManager\Livewire;

use Illuminate\Contracts\View\View;
use Livewire\Component;
use Livewire\WithFileUploads;
use MarvelousDigital\FilamentMediaManager\Actions\PickMedia;
use MarvelousDigital\FilamentMediaManager\Concerns\BrowsesMedia;
use MarvelousDigital\FilamentMediaManager\Models\Media;

class MediaPickerBrowser extends Component
{
    use BrowsesMedia;
    use WithFileUploads;

    /** @var array<string, array{0: int, 1: int}> */
    public array $variations = [];

    public string $statePath = '';

    protected function imagesOnly(): bool
    {
        return true;
    }

    public function select(int $mediaId): void
    {
        $media = Media::find($mediaId);

        if (! $media) {
            return;
        }

        $value = app(PickMedia::class)->execute($media, $this->variations);

        $this->dispatch('image-picker:selected', statePath: $this->statePath, valueId: $value->id);
        $this->dispatch('image-picker:close');
    }

    public function render(): View
    {
        return view('filament-media-manager::media-picker-browser', $this->browseData());
    }
}

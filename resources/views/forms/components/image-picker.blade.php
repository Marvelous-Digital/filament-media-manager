<x-dynamic-component :component="$getFieldWrapperView()" :field="$field">
    <div class="fmm-image-picker" x-data>
        {{-- Full picker UI implemented in Task 10. --}}
        <input type="hidden" {{ $applyStateBindingModifiers('wire:model') }}="{{ $getStatePath() }}" />
    </div>
</x-dynamic-component>

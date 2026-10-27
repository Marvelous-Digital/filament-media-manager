<?php

namespace MarvelousDigital\FilamentMediaManager\Actions;

class PersistImagesPicker
{
    /**
     * Write the ImagesPicker's form state to the database and return the ids to store on the
     * parent, in order. A plain list of ids (never hydrated) passes through untouched.
     *
     * This is an internal action that trusts its caller: every `items[].id` is updated and every
     * `removed` id is deleted (with its conversions and files), whoever owns the row. Never pass
     * it state the browser could have written without running ImagesPickerState::verified()
     * first, as the ImagesPicker field does on save.
     *
     * @param  array<string, array{0: int, 1: int}>  $variations
     * @return list<int>
     */
    public function execute(mixed $state, array $variations): array
    {
        if (! is_array($state)) {
            return [];
        }

        if (! array_key_exists('items', $state)) {
            return array_values(array_map('intval', array_filter($state, is_numeric(...))));
        }

        $persist = app(PersistImagePicker::class);

        foreach ($state['removed'] ?? [] as $removal) {
            $id = is_array($removal) ? ($removal['id'] ?? null) : $removal;

            if (filled($id)) {
                $persist->execute(['id' => $id, 'media_id' => null], $variations);
            }
        }

        $ids = [];

        foreach ($state['items'] as $item) {
            $id = $persist->execute([
                'id' => $item['id'],
                'media_id' => $item['media_id'],
                'alt' => $item['alt'],
                'variants' => [],
            ], $variations);

            if ($id !== null) {
                $ids[] = $id;
            }
        }

        return $ids;
    }
}

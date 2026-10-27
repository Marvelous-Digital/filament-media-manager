<?php

namespace MarvelousDigital\FilamentMediaManager\Actions;

class PersistImagesPicker
{
    /**
     * Write the ImagesPicker's form state to the database and return the ids to store on the
     * parent, in order. A plain list of ids (never hydrated) passes through untouched.
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

        foreach ($state['removed'] ?? [] as $id) {
            $persist->execute(['id' => $id, 'media_id' => null], $variations);
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

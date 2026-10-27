<?php

namespace MarvelousDigital\FilamentMediaManager\Support;

/**
 * Proof that the server handed a MediaFieldValue id to the form. Picker state lives in Livewire
 * data the browser can rewrite, so an id is only trusted on save when its token verifies;
 * without it an editor could point a field at, rewrite or delete any other owner's row.
 */
class FieldValueToken
{
    public static function for(int $id): string
    {
        return hash_hmac('sha256', "fmm-field-value:{$id}", (string) config('app.key'));
    }

    public static function verifies(int $id, mixed $token): bool
    {
        return is_string($token) && $token !== '' && hash_equals(self::for($id), $token);
    }
}

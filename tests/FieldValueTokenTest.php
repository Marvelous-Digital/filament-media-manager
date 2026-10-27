<?php

use MarvelousDigital\FilamentMediaManager\Support\FieldValueToken;

it('signs an id with the app key', function () {
    $token = FieldValueToken::for(5);

    expect($token)->toBe(hash_hmac('sha256', 'fmm-field-value:5', config('app.key')))
        ->and(FieldValueToken::for(6))->not->toBe($token);

    config()->set('app.key', 'base64:' . base64_encode(random_bytes(32)));

    expect(FieldValueToken::for(5))->not->toBe($token);
});

it('verifies only the token for that id', function () {
    expect(FieldValueToken::verifies(5, FieldValueToken::for(5)))->toBeTrue()
        ->and(FieldValueToken::verifies(6, FieldValueToken::for(5)))->toBeFalse()
        ->and(FieldValueToken::verifies(5, null))->toBeFalse()
        ->and(FieldValueToken::verifies(5, ''))->toBeFalse()
        ->and(FieldValueToken::verifies(5, ['x']))->toBeFalse();
});

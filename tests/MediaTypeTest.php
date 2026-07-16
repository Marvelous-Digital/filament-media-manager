<?php

use MarvelousDigital\FilamentMediaManager\Enums\MediaType;

it('derives media type from mime type', function (string $mime, MediaType $expected) {
    expect(MediaType::fromMimeType($mime))->toBe($expected);
})->with([
    ['image/jpeg', MediaType::Image],
    ['image/svg+xml', MediaType::Image],
    ['video/mp4', MediaType::Video],
    ['application/pdf', MediaType::Document],
    ['text/plain', MediaType::Document],
    ['application/zip', MediaType::Other],
]);

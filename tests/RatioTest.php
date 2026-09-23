<?php

use MarvelousDigital\FilamentMediaManager\Support\Ratio;

it('reduces dimensions to their simplest ratio', function (int $width, int $height, string $expected) {
    expect(Ratio::reduce($width, $height))->toBe($expected);
})->with([
    [1500, 900, '5:3'],
    [1024, 768, '4:3'],
    [480, 640, '3:4'],
    [1200, 1200, '1:1'],
    [3000, 100, '30:1'],
    [1601, 600, '1601:600'],
]);

it('returns the raw pair for non-positive dimensions', function () {
    expect(Ratio::reduce(0, 600))->toBe('0:600');
});

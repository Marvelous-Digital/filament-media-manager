<?php

use MarvelousDigital\FilamentMediaManager\Support\CropBox;

it('crops a wide source to a square around centre', function () {
    // 1000x500 source, 1:1 target → crop 500x500, centred at x=250.
    $box = CropBox::for(1000, 500, 400, 400, 0.5, 0.5);

    expect($box)->toBe(['x' => 250, 'y' => 0, 'width' => 500, 'height' => 500]);
});

it('shifts the crop toward the focus point and clamps to bounds', function () {
    // Focus far right → crop hugs the right edge, never exceeding source width.
    $box = CropBox::for(1000, 500, 400, 400, 1.0, 0.5);

    expect($box)->toBe(['x' => 500, 'y' => 0, 'width' => 500, 'height' => 500]);
});

it('crops a tall source to a wide target', function () {
    // 600x1000 source, 3:1 target → width-limited: cw=600, ch=200.
    $box = CropBox::for(600, 1000, 1500, 500, 0.5, 0.0);

    expect($box)->toBe(['x' => 0, 'y' => 0, 'width' => 600, 'height' => 200]);
});

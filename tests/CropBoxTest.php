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

it('defaults to zoom 1.0 producing the full cover crop', function () {
    // 1000x500 source, 1:1 target, no zoom argument → same as zoom 1.0.
    expect(CropBox::for(1000, 500, 400, 400, 0.5, 0.5))
        ->toBe(CropBox::for(1000, 500, 400, 400, 0.5, 0.5, 1.0));
});

it('zooms into a smaller region at the same aspect ratio centred on focus', function () {
    // Base cover crop is 500x500 at x=250; zoom 2 halves it to 250x250, re-centred on focus.
    $box = CropBox::for(1000, 500, 400, 400, 0.5, 0.5, 2.0);

    expect($box)->toBe(['x' => 375, 'y' => 125, 'width' => 250, 'height' => 250]);
});

it('clamps a zoomed crop to the source bounds at the edge', function () {
    // Focus far right with zoom 2 → 250x250 crop hugs the right edge.
    $box = CropBox::for(1000, 500, 400, 400, 1.0, 0.5, 2.0);

    expect($box)->toBe(['x' => 750, 'y' => 125, 'width' => 250, 'height' => 250]);
});

<?php

use Illuminate\Support\Facades\Storage;
use MarvelousDigital\FilamentMediaManager\Models\Media;
use MarvelousDigital\FilamentMediaManager\Models\MediaFolder;

it('relates folders and media', function () {
    $parent = MediaFolder::create(['name' => 'Campaigns']);
    $child = MediaFolder::create(['name' => 'Summer', 'parent_id' => $parent->id]);
    $media = Media::factory()->create(['folder_id' => $child->id]);

    expect($child->parent->is($parent))->toBeTrue()
        ->and($parent->children->first()->is($child))->toBeTrue()
        ->and($child->media->first()->is($media))->toBeTrue()
        ->and($child->ancestors())->toHaveCount(1)
        ->and($child->isEmpty())->toBeFalse()
        ->and($parent->isEmpty())->toBeFalse() // has child folder
        ->and(MediaFolder::create(['name' => 'Empty'])->isEmpty())->toBeTrue();
});

it('builds depth-indented tree options', function () {
    $a = MediaFolder::create(['name' => 'A']);
    $b = MediaFolder::create(['name' => 'B', 'parent_id' => $a->id]);

    expect(MediaFolder::treeOptions())->toBe([
        $a->id => 'A',
        $b->id => '— B',
    ]);
});

it('deletes its files from disk when a media row is deleted', function () {
    Storage::fake('public');
    Storage::disk('public')->put('media/01TEST/photo.jpg', 'bytes');
    Storage::disk('public')->put('media/01TEST/thumb.webp', 'bytes');

    $media = Media::factory()->create([
        'disk' => 'public',
        'path' => 'media/01TEST/photo.jpg',
        'thumbnail_path' => 'media/01TEST/thumb.webp',
    ]);

    $media->delete();

    Storage::disk('public')->assertMissing('media/01TEST/photo.jpg');
    Storage::disk('public')->assertMissing('media/01TEST/thumb.webp');
});

it('does not fail deletion when files are already gone', function () {
    Storage::fake('public');

    $media = Media::factory()->create(['disk' => 'public', 'path' => 'media/01GONE/x.jpg']);

    expect($media->delete())->toBeTrue()
        ->and(Media::query()->count())->toBe(0);
});

it('resolves urls for public disks', function () {
    Storage::fake('public');

    $media = Media::factory()->create(['disk' => 'public', 'path' => 'media/01URL/x.jpg']);

    expect($media->url())->toContain('media/01URL/x.jpg');
});

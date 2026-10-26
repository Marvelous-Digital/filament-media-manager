# ImagePicker Ratios, Per-Variant Overrides & Apply-on-Save Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Let admins see the required formats up front, upload or pick an image, reframe each variant, and override the image (with its own focus and alt) per variant — with every image edit held in the form until Save.

**Architecture:** The field's Livewire state becomes a structured array (main image, alt, per-variant image/alt/focus/zoom, dirty flag). Pure state transitions live in `Support\ImagePickerState`; persistence lives in `Actions\PersistImagePicker`, invoked from the field's `dehydrateStateUsing` (runs on submit, after validation, inside the page save transaction) which returns the scalar id stored in the page JSON and writes the refreshed state back so later saves update in place. `GenerateConversion` crops from the variant's effective source and writes versioned files so the live site keeps the old crop until the new one is ready.

**Tech Stack:** PHP 8.4, Laravel 13, Filament 5, Livewire 4, Alpine.js, Pest, intervention/image via Laravel's `Image` facade.

**Spec:** `docs/superpowers/specs/2026-09-23-image-picker-variant-overrides-design.md`

## Global Constraints

- Two git repos: the plugin at `packages/filament-media-manager` (branch `feature/media-library`) and the host app at the repo root. Tasks 1–8 commit in the plugin repo; Task 9 commits in the host repo.
- All commands run through Sail from the host root `/Users/raphael/Documents/Dev/media-lib-test`. Shorthands used below:
  - `plugin-pest <args>` = `vendor/bin/sail exec laravel.test bash -c "cd packages/filament-media-manager && vendor/bin/pest <args>"`
  - `plugin-lint` = `vendor/bin/sail exec laravel.test bash -c "cd packages/filament-media-manager && vendor/bin/pint && vendor/bin/phpstan analyse --memory-limit=1G"`
- Every task ends with `plugin-lint` clean (host: `vendor/bin/sail bin pint --dirty`) before committing.
- Namespace root `MarvelousDigital\FilamentMediaManager\` → `src/`; factories `...\Database\Factories\` → `database/factories/`. CSS class prefix `fmm-`. Dark mode via `.dark` selectors, as in the existing stylesheet.
- Tests are Pest closures. Never delete a test without replacing its coverage in the same task.
- Commit messages: `feat:` / `refactor:` / `test:` / `docs:` prefix, ending with `Co-Authored-By: Claude Opus 5.5 (1M context) <noreply@anthropic.com>`.
- Zoom range is `[1, 5]`; focus range is `[0, 1]`.
- Validation copy: `This image is no longer in the library.`
- No new dependencies. No new config keys.

## Deviations from the spec (decided while planning)

- Form state gains a `dirty` boolean (drives the "Not saved yet" hint). It is ignored by persistence.
- After persisting, the field writes the freshly loaded state (with the new `id`, `dirty: false`) back into Livewire. Filament does not refill forms after save, so without this a second save of a first-time pick would create a duplicate row.
- Field upload uses Filament's `componentFileAttachments.{statePath}` upload slot (via the `HasFileAttachments` trait) plus a `#[ExposedLivewireMethod]` on the field. The trait's generic `saveUploadedFileAttachmentAndGetUrl` endpoint is overridden to do nothing.
- Upload-time dimensions swap width/height for EXIF orientations 5–8 when `exif_read_data` is available, matching the orientation `GenerateThumbnail` / `GenerateConversion` apply.

## Review Focus

1. **Second save after a first-time pick** must update the same row, not create another — pinned in Task 7 (`it('updates the same row on a second save')`).
2. **Validation failing elsewhere in the form** must persist nothing — pinned in Task 7 (`it('persists nothing when another field fails validation')`).
3. **Field inside a Builder block** must persist and put the id into the block data — pinned in Task 7 (`it('persists when nested inside a builder block')`).
4. **Legacy / unhydrated scalar state** (e.g. `->set()` with an int, or stale JSON) must pass through untouched rather than being wiped — pinned in Task 6 (`it('passes scalar ids through untouched')`).
5. **Source without known dimensions** (HEIC, legacy rows) must still render the editor without a crop rectangle — pinned in Task 8 (`it('renders variant cards when the source has no dimensions')`).

---

## File Structure

**Plugin — create**
- `src/Support/Ratio.php` — reduce `width×height` to `w:h`.
- `src/Support/ImagePickerState.php` — pure state shape + transitions (hydrate, sync, pick main, override variant, reset variant, remove).
- `src/Actions/PersistImagePicker.php` — state array → DB rows + queued regenerations → id.
- `tests/RatioTest.php`, `tests/ImagePickerStateTest.php`, `tests/PersistImagePickerTest.php`, `tests/MediaDimensionsTest.php`, `tests/ImagePickerFormTest.php`.

**Plugin — modify**
- `database/migrations/create_media_field_tables.php` — `media_id`, `alt_text` on `media_conversions`.
- `src/Models/MediaConversion.php` — `source()`, `effectiveSource()`, `effectiveAltText()`, `fingerprint()`, file cleanup on delete.
- `src/Models/MediaFieldValue.php` — source-independent `directory()`.
- `src/Models/Media.php` — `ensureDimensions()`, `deleting` hook reverting overrides.
- `src/Actions/UploadMedia.php` — store dimensions on create.
- `src/Jobs/GenerateConversion.php` — effective source, versioned names, swap, `afterCommit`.
- `src/Forms/Components/ImagePicker.php` — hydrate/dehydrate/validation/exposed methods.
- `src/Livewire/MediaPickerBrowser.php` + `resources/views/forms/components/image-picker-modal.blade.php` — `target`, no persistence.
- `resources/views/forms/components/image-picker.blade.php` — new UI.
- `resources/dist/filament-media-manager.css` — new styles.
- `README.md` — ImagePicker section.

**Plugin — delete (coverage moved)**
- `src/Actions/PickMedia.php`, `tests/PickMediaTest.php` → `PersistImagePickerTest`, `ImagePickerStateTest`.
- `src/Actions/UpdateConversionFocus.php`, `tests/ImagePickerFocusTest.php` → `PersistImagePickerTest` (clamping, regeneration).
- `tests/ImagePickerFieldActionsTest.php` → `ImagePickerFormTest` (retry, builder nesting, foreign-conversion refusal).

**Host — create/modify**
- `database/migrations/xxxx_add_override_to_media_conversions_table.php`
- `app/View/Components/Blocks/ImageBlock.php`, `resources/views/components/blocks/image-block.blade.php`
- `tests/Feature/ImageBlockTest.php`

---

### Task 0: Prerequisites (human)

- [ ] **Step 1:** Commit (or stash) the current uncommitted zoom work in **both** repos so each task's diff is reviewable on its own. Check with `git status` in the host root and in `packages/filament-media-manager`.
- [ ] **Step 2:** Confirm the plugin suite is green: `plugin-pest --compact` → `88 passed`.

---

### Task 1: Conversion override columns and model helpers

**Files:**
- Modify: `database/migrations/create_media_field_tables.php`
- Modify: `src/Models/MediaConversion.php`
- Modify: `src/Models/MediaFieldValue.php:61-64`
- Test: `tests/MediaConversionModelTest.php`, `tests/MediaFieldValueModelTest.php`

**Interfaces:**
- Produces: `MediaConversion::source(): BelongsTo`, `effectiveSource(): Media`, `effectiveAltText(): ?string`, `fingerprint(): string` (8 hex chars), properties `?int $media_id`, `?string $alt_text`; `MediaFieldValue::directory(): string` = `{directory}/conversions/{id}`.

- [ ] **Step 1: Write the failing tests** — append to `tests/MediaConversionModelTest.php`:

```php
it('uses the override image as the effective source when set', function () {
    $main = Media::factory()->create();
    $override = Media::factory()->create();
    $value = MediaFieldValue::factory()->for($main, 'source')->create();
    $conversion = MediaConversion::factory()->for($value, 'value')->create(['media_id' => $override->id]);

    expect($conversion->effectiveSource()->is($override))->toBeTrue();
});

it('falls back to the main image as the effective source', function () {
    $main = Media::factory()->create();
    $value = MediaFieldValue::factory()->for($main, 'source')->create();
    $conversion = MediaConversion::factory()->for($value, 'value')->create(['media_id' => null]);

    expect($conversion->effectiveSource()->is($main))->toBeTrue();
});

it('uses its own alt text only when overridden and filled', function () {
    $value = MediaFieldValue::factory()->for(Media::factory(), 'source')->create(['alt_text' => 'Main alt']);
    $override = Media::factory()->create();

    $own = MediaConversion::factory()->for($value, 'value')->create(['variation' => 'a', 'media_id' => $override->id, 'alt_text' => 'Own alt']);
    $blank = MediaConversion::factory()->for($value, 'value')->create(['variation' => 'b', 'media_id' => $override->id, 'alt_text' => null]);
    $plain = MediaConversion::factory()->for($value, 'value')->create(['variation' => 'c', 'media_id' => null, 'alt_text' => 'Ignored']);

    expect($own->effectiveAltText())->toBe('Own alt')
        ->and($blank->effectiveAltText())->toBe('Main alt')
        ->and($plain->effectiveAltText())->toBe('Main alt');
});

it('computes max safe zoom from the override image', function () {
    $main = Media::factory()->create(['width' => 100, 'height' => 100]);
    $override = Media::factory()->create(['width' => 1000, 'height' => 500]);
    $value = MediaFieldValue::factory()->for($main, 'source')->create();
    $conversion = MediaConversion::factory()->for($value, 'value')->create([
        'media_id' => $override->id, 'width' => 400, 'height' => 400,
    ]);

    expect($conversion->maxSafeZoom())->toBe(1.25);
});

it('fingerprints the settings that shape the output', function () {
    $value = MediaFieldValue::factory()->for(Media::factory(), 'source')->create();
    $conversion = MediaConversion::factory()->for($value, 'value')->create();

    $before = $conversion->fingerprint();
    $conversion->focus_x = 0.2;

    expect($before)->toHaveLength(8)
        ->and($conversion->fingerprint())->not->toBe($before);
});

it('deletes its generated file when the conversion is deleted', function () {
    Storage::fake('public');
    Storage::disk('public')->put('media/conversions/9/hero-abcd1234.webp', 'x');
    $value = MediaFieldValue::factory()->for(Media::factory()->state(['disk' => 'public']), 'source')->create();
    $conversion = MediaConversion::factory()->for($value, 'value')->create(['path' => 'media/conversions/9/hero-abcd1234.webp']);

    $conversion->delete();

    Storage::disk('public')->assertMissing('media/conversions/9/hero-abcd1234.webp');
});
```

Append to `tests/MediaFieldValueModelTest.php`:

```php
it('keeps conversions in a directory independent of the main image', function () {
    config()->set('filament-media-manager.directory', 'media');
    $value = MediaFieldValue::factory()->for(Media::factory(), 'source')->create();

    expect($value->directory())->toBe("media/conversions/{$value->id}");
});
```

- [ ] **Step 2: Run to verify failure**

Run: `plugin-pest tests/MediaConversionModelTest.php tests/MediaFieldValueModelTest.php`
Expected: FAIL — `table media_conversions has no column named media_id` / undefined method `effectiveSource`.

- [ ] **Step 3: Implement**

In `database/migrations/create_media_field_tables.php`, inside `media_conversions` after `$table->float('zoom')->default(1.0);`:

```php
            $table->foreignId('media_id')->nullable()->constrained('media')->nullOnDelete();
            $table->string('alt_text')->nullable();
```

In `src/Models/MediaFieldValue.php` replace `directory()`:

```php
    public function directory(): string
    {
        return trim(config('filament-media-manager.directory'), '/') . '/conversions/' . $this->id;
    }
```

In `src/Models/MediaConversion.php`:
- docblock: add `@property int|null $media_id`, `@property string|null $alt_text`, `@property-read Media|null $source`.
- `$fillable`: add `'media_id', 'alt_text'`.
- casts: add `'media_id' => 'integer'`.
- add imports `Illuminate\Support\Facades\Storage`, `Throwable`.
- add:

```php
    protected static function booted(): void
    {
        static::deleted(function (self $conversion): void {
            if ($conversion->path === null) {
                return;
            }

            try {
                Storage::disk($conversion->value->source->disk)->delete($conversion->path);
            } catch (Throwable) {
                // Best-effort cleanup; never block row deletion.
            }
        });
    }

    public function source(): BelongsTo
    {
        return $this->belongsTo(Media::class, 'media_id');
    }

    public function effectiveSource(): Media
    {
        return $this->source ?? $this->value->source;
    }

    public function effectiveAltText(): ?string
    {
        if ($this->media_id !== null && filled($this->alt_text)) {
            return $this->alt_text;
        }

        return $this->value->alt_text;
    }

    /**
     * Short hash of everything that shapes the generated file; used to version file names.
     */
    public function fingerprint(): string
    {
        return substr(sha1(implode('|', [
            $this->effectiveSource()->id,
            $this->width,
            $this->height,
            $this->focus_x,
            $this->focus_y,
            $this->zoom,
        ])), 0, 8);
    }
```

- change `maxSafeZoom()` to read `$source = $this->effectiveSource();` then `$source->width` / `$source->height`.

- [ ] **Step 4: Run to verify pass**

Run: `plugin-pest tests/MediaConversionModelTest.php tests/MediaFieldValueModelTest.php`
Expected: PASS.

- [ ] **Step 5: Lint and commit (plugin repo)**

```bash
plugin-lint
cd packages/filament-media-manager && git add database/migrations/create_media_field_tables.php src/Models tests/MediaConversionModelTest.php tests/MediaFieldValueModelTest.php && git commit -m "feat: per-variant override source and alt on media conversions"
```

---

### Task 2: Ratio helper and source dimensions

**Files:**
- Create: `src/Support/Ratio.php`, `tests/RatioTest.php`, `tests/MediaDimensionsTest.php`
- Modify: `src/Actions/UploadMedia.php`, `src/Models/Media.php`
- Test: `tests/UploadMediaTest.php`

**Interfaces:**
- Produces: `Ratio::reduce(int $width, int $height): string`; `Media::ensureDimensions(): void` (no-op when both set or not an image; stores measured dims otherwise).

- [ ] **Step 1: Write the failing tests**

`tests/RatioTest.php`:

```php
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
```

`tests/MediaDimensionsTest.php`:

```php
<?php

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use MarvelousDigital\FilamentMediaManager\Enums\MediaType;
use MarvelousDigital\FilamentMediaManager\Models\Media;

it('measures and stores missing dimensions', function () {
    Storage::fake('public');
    $path = UploadedFile::fake()->image('p.jpg', 640, 480)->storeAs('media/01D', 'p.jpg', ['disk' => 'public']);
    $media = Media::factory()->create(['disk' => 'public', 'path' => $path, 'width' => null, 'height' => null]);

    $media->ensureDimensions();

    expect($media->refresh()->width)->toBe(640)->and($media->height)->toBe(480);
});

it('leaves known dimensions untouched', function () {
    $media = Media::factory()->create(['width' => 10, 'height' => 20]);

    $media->ensureDimensions();

    expect($media->refresh()->width)->toBe(10)->and($media->height)->toBe(20);
});

it('ignores unreadable or non-image files', function () {
    Storage::fake('public');
    Storage::disk('public')->put('media/01X/broken.jpg', 'not-an-image');
    $broken = Media::factory()->create(['disk' => 'public', 'path' => 'media/01X/broken.jpg']);
    $video = Media::factory()->create(['type' => MediaType::Video, 'mime_type' => 'video/mp4']);

    $broken->ensureDimensions();
    $video->ensureDimensions();

    expect($broken->refresh()->width)->toBeNull()->and($video->refresh()->width)->toBeNull();
});
```

Append to `tests/UploadMediaTest.php`:

```php
it('stores image dimensions at upload time', function () {
    $media = app(UploadMedia::class)->execute(UploadedFile::fake()->image('wide.jpg', 1200, 500));

    expect($media->width)->toBe(1200)->and($media->height)->toBe(500);
});

it('leaves dimensions empty for non-images', function () {
    $media = app(UploadMedia::class)->execute(UploadedFile::fake()->create('report.pdf', 100, 'application/pdf'));

    expect($media->width)->toBeNull()->and($media->height)->toBeNull();
});
```

- [ ] **Step 2: Run to verify failure**

Run: `plugin-pest tests/RatioTest.php tests/MediaDimensionsTest.php tests/UploadMediaTest.php`
Expected: FAIL — class `Ratio` not found; `ensureDimensions` undefined; width null.

- [ ] **Step 3: Implement**

`src/Support/Ratio.php`:

```php
<?php

namespace MarvelousDigital\FilamentMediaManager\Support;

class Ratio
{
    public static function reduce(int $width, int $height): string
    {
        if ($width <= 0 || $height <= 0) {
            return "{$width}:{$height}";
        }

        $divisor = self::greatestCommonDivisor($width, $height);

        return ($width / $divisor) . ':' . ($height / $divisor);
    }

    private static function greatestCommonDivisor(int $a, int $b): int
    {
        return $b === 0 ? $a : self::greatestCommonDivisor($b, $a % $b);
    }
}
```

`src/Models/Media.php` — add:

```php
    /**
     * Measure and store width/height when they are unknown (e.g. uploads that predate
     * upload-time measuring). Silently does nothing for unreadable or non-image files.
     */
    public function ensureDimensions(): void
    {
        if (($this->width && $this->height) || $this->type !== MediaType::Image) {
            return;
        }

        try {
            $contents = Storage::disk($this->disk)->get($this->path);
            $size = is_string($contents) && $contents !== '' ? getimagesizefromstring($contents) : false;
        } catch (Throwable) {
            return;
        }

        if ($size === false) {
            return;
        }

        $this->update(['width' => $size[0], 'height' => $size[1]]);
    }
```

`src/Actions/UploadMedia.php` — add `'width' => $width, 'height' => $height,` to `Media::create([...])` with, before it:

```php
        [$width, $height] = $this->dimensions($file, $type);
```

and add (import `Throwable`):

```php
    /**
     * @return array{0: int|null, 1: int|null}
     */
    protected function dimensions(UploadedFile $file, MediaType $type): array
    {
        if ($type !== MediaType::Image) {
            return [null, null];
        }

        try {
            $size = getimagesize($file->getRealPath());
        } catch (Throwable) {
            return [null, null];
        }

        if ($size === false) {
            return [null, null];
        }

        [$width, $height] = $size;

        if ($this->isRotatedQuarterTurn($file)) {
            return [$height, $width];
        }

        return [$width, $height];
    }

    /**
     * EXIF orientations 5–8 are displayed rotated by 90°; conversions apply orient(), so
     * report the displayed dimensions.
     */
    protected function isRotatedQuarterTurn(UploadedFile $file): bool
    {
        if (! function_exists('exif_read_data')) {
            return false;
        }

        try {
            $exif = exif_read_data($file->getRealPath());
        } catch (Throwable) {
            return false;
        }

        return is_array($exif) && in_array((int) ($exif['Orientation'] ?? 1), [5, 6, 7, 8], true);
    }
```

- [ ] **Step 4: Run to verify pass**

Run: `plugin-pest tests/RatioTest.php tests/MediaDimensionsTest.php tests/UploadMediaTest.php`
Expected: PASS.

- [ ] **Step 5: Lint and commit**

```bash
plugin-lint
cd packages/filament-media-manager && git add src/Support/Ratio.php src/Models/Media.php src/Actions/UploadMedia.php tests/RatioTest.php tests/MediaDimensionsTest.php tests/UploadMediaTest.php && git commit -m "feat: ratio helper and image dimensions at upload"
```

---

### Task 3: GenerateConversion — effective source, versioned files, after-commit dispatch

**Files:**
- Modify: `src/Jobs/GenerateConversion.php`
- Test: `tests/GenerateConversionTest.php`

**Interfaces:**
- Consumes: `MediaConversion::effectiveSource()`, `fingerprint()` (Task 1).
- Produces: `GenerateConversion::dispatchFor(MediaConversion $conversion): void` — runs after the surrounding DB transaction commits (queued or sync). Output path `{value->directory()}/{variation}-{fingerprint}.{format}` on the main source's disk.

- [ ] **Step 1: Write the failing tests** — in `tests/GenerateConversionTest.php` replace the path assertion of the first test with:

```php
        ->and($conversion->path)->toBe($conversion->value->directory() . '/thumb-' . $conversion->fingerprint() . '.webp');
```

and append:

```php
it('crops from the override image when one is set', function () {
    $conversion = makeConversion(1200, 800, 400, 400);
    $overridePath = UploadedFile::fake()->image('tall.jpg', 300, 900)->storeAs('media/01OVR', 'tall.jpg', ['disk' => 'public']);
    $override = Media::factory()->create(['disk' => 'public', 'path' => $overridePath, 'mime_type' => 'image/jpeg']);
    $conversion->update(['media_id' => $override->id, 'width' => 300, 'height' => 900]);

    (new GenerateConversion($conversion))->handle();

    [$w, $h] = getimagesize(Storage::disk('public')->path($conversion->refresh()->path));
    expect($conversion->status)->toBe(ConversionStatus::Completed)
        ->and($conversion->path)->toContain('thumb-' . $conversion->fingerprint())
        ->and([$w, $h])->toBe([300, 900]);
});

it('replaces the previous file only after the new one is stored', function () {
    $conversion = makeConversion();
    (new GenerateConversion($conversion))->handle();
    $firstPath = $conversion->refresh()->path;

    $conversion->update(['focus_x' => 0.1]);
    (new GenerateConversion($conversion))->handle();

    $conversion->refresh();
    expect($conversion->path)->not->toBe($firstPath);
    Storage::disk('public')->assertExists($conversion->path);
    Storage::disk('public')->assertMissing($firstPath);
});

it('keeps the previous file when regeneration fails', function () {
    $conversion = makeConversion();
    (new GenerateConversion($conversion))->handle();
    $livePath = $conversion->refresh()->path;

    Storage::disk('public')->put($conversion->value->source->path, 'corrupted');
    $conversion->update(['focus_x' => 0.1]);
    (new GenerateConversion($conversion))->handle();

    expect($conversion->refresh()->status)->toBe(ConversionStatus::Failed)
        ->and($conversion->path)->toBe($livePath);
    Storage::disk('public')->assertExists($livePath);
});

it('does nothing when the conversion was deleted before the job ran', function () {
    $conversion = makeConversion();
    $job = new GenerateConversion($conversion);
    MediaConversion::query()->whereKey($conversion->id)->delete();

    $job->handle();

    expect(MediaConversion::query()->count())->toBe(0);
});
```

- [ ] **Step 2: Run to verify failure**

Run: `plugin-pest tests/GenerateConversionTest.php`
Expected: FAIL — path mismatch (`thumb.webp`), override crop 400×400.

- [ ] **Step 3: Implement** — replace `src/Jobs/GenerateConversion.php` body:

```php
class GenerateConversion implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public bool $deleteWhenMissingModels = true;

    public function __construct(public MediaConversion $conversion) {}

    /**
     * Dispatch after the surrounding transaction commits so a rolled-back form save never
     * generates files. Outside a transaction this runs immediately.
     */
    public static function dispatchFor(MediaConversion $conversion): void
    {
        if (config('filament-media-manager.thumbnail.queue')) {
            static::dispatch($conversion)
                ->onQueue(config('filament-media-manager.thumbnail.queue_name'))
                ->afterCommit();

            return;
        }

        DB::afterCommit(fn () => static::dispatchSync($conversion));
    }

    public function handle(): void
    {
        $conversion = $this->conversion->fresh(['value.source', 'source']);

        if ($conversion === null) {
            return;
        }

        $source = $conversion->effectiveSource();
        $disk = $conversion->value->source->disk;

        try {
            $image = Image::fromStorage($source->path, $source->disk)->orient();
            [$sw, $sh] = $image->dimensions();

            $box = CropBox::for(
                $sw,
                $sh,
                $conversion->width,
                $conversion->height,
                $conversion->focus_x,
                $conversion->focus_y,
                $conversion->zoom,
            );

            $format = config('filament-media-manager.thumbnail.format', 'webp');

            $path = $image
                ->crop($box['width'], $box['height'], $box['x'], $box['y'])
                ->resize($conversion->width, $conversion->height)
                ->optimize(format: $format, quality: config('filament-media-manager.thumbnail.quality', 70))
                ->storeAs(
                    path: $conversion->value->directory(),
                    name: $conversion->variation . '-' . $conversion->fingerprint() . '.' . $format,
                    disk: $disk,
                );

            if ($path === false) {
                throw new RuntimeException("Could not store conversion [{$conversion->id}].");
            }

            $previousPath = $conversion->path;

            $conversion->update(['path' => $path, 'status' => ConversionStatus::Completed]);

            if ($previousPath !== null && $previousPath !== $path) {
                Storage::disk($disk)->delete($previousPath);
            }
        } catch (Throwable $exception) {
            $conversion->update(['status' => ConversionStatus::Failed]);
            report($exception);
        }
    }
}
```

Imports to add: `Illuminate\Support\Facades\DB`, `Illuminate\Support\Facades\Storage`.

- [ ] **Step 4: Run to verify pass**

Run: `plugin-pest tests/GenerateConversionTest.php`
Expected: PASS (all, including the existing queued/sync dispatch tests).

- [ ] **Step 5: Lint and commit**

```bash
plugin-lint
cd packages/filament-media-manager && git add src/Jobs/GenerateConversion.php tests/GenerateConversionTest.php && git commit -m "feat: generate conversions from effective source with versioned files"
```

---

### Task 4: Revert overrides when their library image is deleted

**Files:**
- Modify: `src/Models/Media.php` (`booted()`)
- Test: `tests/ModelsTest.php` (append)

**Interfaces:**
- Consumes: `GenerateConversion::dispatchFor()` (Task 3).

- [ ] **Step 1: Write the failing test** — append to `tests/ModelsTest.php` (add imports for `Queue`, `ConversionStatus`, `GenerateConversion`, `MediaConversion`, `MediaFieldValue` if missing):

```php
it('sends overridden variants back to the main image when the override is deleted', function () {
    Queue::fake();
    $main = Media::factory()->create();
    $override = Media::factory()->create();
    $value = MediaFieldValue::factory()->for($main, 'source')->create();
    $conversion = MediaConversion::factory()->for($value, 'value')->create([
        'media_id' => $override->id, 'alt_text' => 'Own', 'status' => ConversionStatus::Completed,
    ]);

    $override->delete();

    $conversion->refresh();
    expect($conversion->media_id)->toBeNull()
        ->and($conversion->alt_text)->toBeNull()
        ->and($conversion->status)->toBe(ConversionStatus::Pending);
    Queue::assertPushed(GenerateConversion::class, fn (GenerateConversion $job) => $job->conversion->is($conversion));
});
```

- [ ] **Step 2: Run to verify failure**

Run: `plugin-pest tests/ModelsTest.php`
Expected: FAIL — status still `Completed` / job not pushed.

- [ ] **Step 3: Implement** — in `Media::booted()` add before the existing `deleted` listener:

```php
        static::deleting(function (self $media): void {
            MediaConversion::query()
                ->where('media_id', $media->id)
                ->each(function (MediaConversion $conversion): void {
                    $conversion->update([
                        'media_id' => null,
                        'alt_text' => null,
                        'status' => ConversionStatus::Pending,
                    ]);

                    GenerateConversion::dispatchFor($conversion);
                });
        });
```

Imports: `ConversionStatus`, `GenerateConversion`.

- [ ] **Step 4: Run to verify pass**

Run: `plugin-pest tests/ModelsTest.php`
Expected: PASS.

- [ ] **Step 5: Lint and commit**

```bash
plugin-lint
cd packages/filament-media-manager && git add src/Models/Media.php tests/ModelsTest.php && git commit -m "feat: revert variant overrides when their image is deleted"
```

---

### Task 5: ImagePickerState — state shape and transitions

**Files:**
- Create: `src/Support/ImagePickerState.php`, `tests/ImagePickerStateTest.php`

**Interfaces:**
- Consumes: `MediaConversion` columns (Task 1).
- Produces (all static, `$variations` is `array<string, array{0: int, 1: int}>`):
  - `defaultVariant(): array{media_id: null, alt: null, focus_x: float, focus_y: float, zoom: float}`
  - `fromStoredValue(mixed $stored, array $variations): ?array` — array → `syncVariants`; numeric id → load row → `fromValue`; missing row / anything else → `null`.
  - `fromValue(MediaFieldValue $value, array $variations): array`
  - `syncVariants(array $state, array $variations): array`
  - `withMainImage(?array $state, Media $media, array $variations): array`
  - `withVariantImage(array $state, string $variation, Media $media): array`
  - `withoutVariantImage(array $state, string $variation): array`
  - `withoutImage(?array $state): ?array`
- State shape: `array{id: int|null, media_id: int|null, alt: string|null, dirty: bool, variants: array<string, array{media_id: int|null, alt: string|null, focus_x: float, focus_y: float, zoom: float}>}`

- [ ] **Step 1: Write the failing tests** — `tests/ImagePickerStateTest.php`:

```php
<?php

use MarvelousDigital\FilamentMediaManager\Models\Media;
use MarvelousDigital\FilamentMediaManager\Models\MediaConversion;
use MarvelousDigital\FilamentMediaManager\Models\MediaFieldValue;
use MarvelousDigital\FilamentMediaManager\Support\ImagePickerState;

$variations = ['desktop' => [1500, 900], 'mobile' => [480, 640]];

it('hydrates a saved value and syncs declared variations', function () use ($variations) {
    $override = Media::factory()->create();
    $value = MediaFieldValue::factory()->for(Media::factory(), 'source')->create(['alt_text' => 'Main']);
    MediaConversion::factory()->for($value, 'value')->create([
        'variation' => 'mobile', 'media_id' => $override->id, 'alt_text' => 'Own',
        'focus_x' => 0.2, 'focus_y' => 0.3, 'zoom' => 2.0,
    ]);
    MediaConversion::factory()->for($value, 'value')->create(['variation' => 'retired']);

    $state = ImagePickerState::fromStoredValue($value->id, $variations);

    expect($state)->toMatchArray(['id' => $value->id, 'media_id' => $value->media_id, 'alt' => 'Main', 'dirty' => false])
        ->and(array_keys($state['variants']))->toBe(['desktop', 'mobile'])
        ->and($state['variants']['desktop'])->toBe(ImagePickerState::defaultVariant())
        ->and($state['variants']['mobile'])->toBe([
            'media_id' => $override->id, 'alt' => 'Own', 'focus_x' => 0.2, 'focus_y' => 0.3, 'zoom' => 2.0,
        ]);
});

it('returns null for empty or missing stored values', function () use ($variations) {
    expect(ImagePickerState::fromStoredValue(null, $variations))->toBeNull()
        ->and(ImagePickerState::fromStoredValue(999999, $variations))->toBeNull()
        ->and(ImagePickerState::fromStoredValue('nonsense', $variations))->toBeNull();
});

it('keeps already-hydrated state and resyncs its variations', function () use ($variations) {
    $state = ['id' => 5, 'media_id' => 7, 'alt' => null, 'dirty' => true, 'variants' => [
        'desktop' => ['media_id' => null, 'alt' => null, 'focus_x' => 0.1, 'focus_y' => 0.1, 'zoom' => 1.0],
        'retired' => ImagePickerState::defaultVariant(),
    ]];

    $synced = ImagePickerState::fromStoredValue($state, $variations);

    expect($synced['dirty'])->toBeTrue()
        ->and(array_keys($synced['variants']))->toBe(['desktop', 'mobile'])
        ->and($synced['variants']['desktop']['focus_x'])->toBe(0.1);
});

it('picks a main image, copying alt and resetting non-overridden variants', function () use ($variations) {
    $override = Media::factory()->create();
    $newMain = Media::factory()->create(['alt_text' => 'New main']);
    $state = ['id' => 5, 'media_id' => 7, 'alt' => 'Old', 'dirty' => false, 'variants' => [
        'desktop' => ['media_id' => null, 'alt' => null, 'focus_x' => 0.1, 'focus_y' => 0.9, 'zoom' => 3.0],
        'mobile' => ['media_id' => $override->id, 'alt' => 'Own', 'focus_x' => 0.2, 'focus_y' => 0.2, 'zoom' => 2.0],
    ]];

    $next = ImagePickerState::withMainImage($state, $newMain, $variations);

    expect($next)->toMatchArray(['id' => 5, 'media_id' => $newMain->id, 'alt' => 'New main', 'dirty' => true])
        ->and($next['variants']['desktop'])->toBe(ImagePickerState::defaultVariant())
        ->and($next['variants']['mobile'])->toBe($state['variants']['mobile']);
});

it('picks a main image into an empty field', function () use ($variations) {
    $media = Media::factory()->create(['alt_text' => 'Cat']);

    $state = ImagePickerState::withMainImage(null, $media, $variations);

    expect($state)->toMatchArray(['id' => null, 'media_id' => $media->id, 'alt' => 'Cat', 'dirty' => true])
        ->and(array_keys($state['variants']))->toBe(['desktop', 'mobile']);
});

it('overrides and resets a single variant', function () use ($variations) {
    $main = Media::factory()->create();
    $override = Media::factory()->create(['alt_text' => 'Speaker']);
    $state = ImagePickerState::withMainImage(null, $main, $variations);

    $overridden = ImagePickerState::withVariantImage($state, 'mobile', $override);
    $reset = ImagePickerState::withoutVariantImage($overridden, 'mobile');

    expect($overridden['variants']['mobile'])->toMatchArray(['media_id' => $override->id, 'alt' => 'Speaker', 'zoom' => 1.0])
        ->and($overridden['variants']['desktop'])->toBe(ImagePickerState::defaultVariant())
        ->and($reset['variants']['mobile'])->toBe(ImagePickerState::defaultVariant())
        ->and($reset['dirty'])->toBeTrue();
});

it('ignores overrides for undeclared variants', function () use ($variations) {
    $state = ImagePickerState::withMainImage(null, Media::factory()->create(), $variations);

    expect(ImagePickerState::withVariantImage($state, 'nope', Media::factory()->create()))->toBe($state);
});

it('removes an unsaved image entirely and marks a saved one for deletion', function () {
    expect(ImagePickerState::withoutImage(null))->toBeNull()
        ->and(ImagePickerState::withoutImage(['id' => null, 'media_id' => 3]))->toBeNull()
        ->and(ImagePickerState::withoutImage(['id' => 5, 'media_id' => 3]))
        ->toBe(['id' => 5, 'media_id' => null, 'alt' => null, 'dirty' => true, 'variants' => []]);
});
```

- [ ] **Step 2: Run to verify failure**

Run: `plugin-pest tests/ImagePickerStateTest.php`
Expected: FAIL — class `ImagePickerState` not found.

- [ ] **Step 3: Implement** — `src/Support/ImagePickerState.php`:

```php
<?php

namespace MarvelousDigital\FilamentMediaManager\Support;

use MarvelousDigital\FilamentMediaManager\Models\Media;
use MarvelousDigital\FilamentMediaManager\Models\MediaFieldValue;

/**
 * Pure transitions for the ImagePicker's in-form state. Nothing here writes to the database.
 *
 * @phpstan-type Variant array{media_id: int|null, alt: string|null, focus_x: float, focus_y: float, zoom: float}
 * @phpstan-type State array{id: int|null, media_id: int|null, alt: string|null, dirty: bool, variants: array<string, Variant>}
 */
class ImagePickerState
{
    /**
     * @return Variant
     */
    public static function defaultVariant(): array
    {
        return ['media_id' => null, 'alt' => null, 'focus_x' => 0.5, 'focus_y' => 0.5, 'zoom' => 1.0];
    }

    /**
     * @param  array<string, array{0: int, 1: int}>  $variations
     * @return State|null
     */
    public static function fromStoredValue(mixed $stored, array $variations): ?array
    {
        if (is_array($stored)) {
            return self::syncVariants($stored, $variations);
        }

        if (! is_numeric($stored)) {
            return null;
        }

        $value = MediaFieldValue::query()->with('conversions')->find((int) $stored);

        return $value ? self::fromValue($value, $variations) : null;
    }

    /**
     * @param  array<string, array{0: int, 1: int}>  $variations
     * @return State
     */
    public static function fromValue(MediaFieldValue $value, array $variations): array
    {
        $variants = [];

        foreach (array_keys($variations) as $name) {
            $conversion = $value->conversion($name);

            $variants[$name] = $conversion === null ? self::defaultVariant() : [
                'media_id' => $conversion->media_id,
                'alt' => $conversion->alt_text,
                'focus_x' => $conversion->focus_x,
                'focus_y' => $conversion->focus_y,
                'zoom' => $conversion->zoom,
            ];
        }

        return [
            'id' => $value->id,
            'media_id' => $value->media_id,
            'alt' => $value->alt_text,
            'dirty' => false,
            'variants' => $variants,
        ];
    }

    /**
     * @param  array<string, mixed>  $state
     * @param  array<string, array{0: int, 1: int}>  $variations
     * @return State
     */
    public static function syncVariants(array $state, array $variations): array
    {
        $variants = [];

        foreach (array_keys($variations) as $name) {
            $variants[$name] = [...self::defaultVariant(), ...($state['variants'][$name] ?? [])];
        }

        return [
            'id' => $state['id'] ?? null,
            'media_id' => $state['media_id'] ?? null,
            'alt' => $state['alt'] ?? null,
            'dirty' => (bool) ($state['dirty'] ?? false),
            'variants' => $variants,
        ];
    }

    /**
     * @param  array<string, mixed>|null  $state
     * @param  array<string, array{0: int, 1: int}>  $variations
     * @return State
     */
    public static function withMainImage(?array $state, Media $media, array $variations): array
    {
        $state = self::syncVariants($state ?? [], $variations);

        foreach ($state['variants'] as $name => $variant) {
            if ($variant['media_id'] === null) {
                $state['variants'][$name] = self::defaultVariant();
            }
        }

        return [...$state, 'media_id' => $media->id, 'alt' => $media->alt_text, 'dirty' => true];
    }

    /**
     * @param  State  $state
     * @return State
     */
    public static function withVariantImage(array $state, string $variation, Media $media): array
    {
        if (! array_key_exists($variation, $state['variants'])) {
            return $state;
        }

        $state['variants'][$variation] = [...self::defaultVariant(), 'media_id' => $media->id, 'alt' => $media->alt_text];
        $state['dirty'] = true;

        return $state;
    }

    /**
     * @param  State  $state
     * @return State
     */
    public static function withoutVariantImage(array $state, string $variation): array
    {
        if (! array_key_exists($variation, $state['variants'])) {
            return $state;
        }

        $state['variants'][$variation] = self::defaultVariant();
        $state['dirty'] = true;

        return $state;
    }

    /**
     * An image that was never saved simply disappears; a saved one keeps its id so the
     * row can be deleted on save.
     *
     * @param  array<string, mixed>|null  $state
     * @return State|null
     */
    public static function withoutImage(?array $state): ?array
    {
        if (blank($state['id'] ?? null)) {
            return null;
        }

        return ['id' => (int) $state['id'], 'media_id' => null, 'alt' => null, 'dirty' => true, 'variants' => []];
    }
}
```

- [ ] **Step 4: Run to verify pass**

Run: `plugin-pest tests/ImagePickerStateTest.php`
Expected: PASS.

- [ ] **Step 5: Lint and commit**

```bash
plugin-lint
cd packages/filament-media-manager && git add src/Support/ImagePickerState.php tests/ImagePickerStateTest.php && git commit -m "feat: ImagePickerState for in-form image picker state"
```

---

### Task 6: PersistImagePicker action

**Files:**
- Create: `src/Actions/PersistImagePicker.php`, `tests/PersistImagePickerTest.php`
- Delete: `src/Actions/PickMedia.php`, `tests/PickMediaTest.php`, `src/Actions/UpdateConversionFocus.php`, `tests/ImagePickerFocusTest.php` — **only in Task 7**, once the field stops using them. Coverage for alt copying, seeding, clamping and regeneration lands here first.

**Interfaces:**
- Consumes: `ImagePickerState` (Task 5), `GenerateConversion::dispatchFor()` (Task 3).
- Produces: `PersistImagePicker::execute(mixed $state, array $variations): ?int`.

- [ ] **Step 1: Write the failing tests** — `tests/PersistImagePickerTest.php`:

```php
<?php

use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use MarvelousDigital\FilamentMediaManager\Actions\PersistImagePicker;
use MarvelousDigital\FilamentMediaManager\Enums\ConversionStatus;
use MarvelousDigital\FilamentMediaManager\Jobs\GenerateConversion;
use MarvelousDigital\FilamentMediaManager\Models\Media;
use MarvelousDigital\FilamentMediaManager\Models\MediaConversion;
use MarvelousDigital\FilamentMediaManager\Models\MediaFieldValue;
use MarvelousDigital\FilamentMediaManager\Support\ImagePickerState;

beforeEach(fn () => Queue::fake());

$variations = ['desktop' => [1500, 900], 'mobile' => [480, 640]];

function persist(mixed $state, array $variations): ?int
{
    return app(PersistImagePicker::class)->execute($state, $variations);
}

it('creates a value with one pending conversion per variation', function () use ($variations) {
    $media = Media::factory()->create(['alt_text' => 'Cat']);

    $id = persist(ImagePickerState::withMainImage(null, $media, $variations), $variations);

    $value = MediaFieldValue::with('conversions')->find($id);
    expect($value->media_id)->toBe($media->id)
        ->and($value->alt_text)->toBe('Cat')
        ->and($value->conversions)->toHaveCount(2)
        ->and($value->conversion('desktop')->width)->toBe(1500)
        ->and($value->conversion('desktop')->status)->toBe(ConversionStatus::Pending);
    Queue::assertPushed(GenerateConversion::class, 2);
});

it('updates the existing row in place and only regenerates changed variants', function () use ($variations) {
    $media = Media::factory()->create();
    $id = persist(ImagePickerState::withMainImage(null, $media, $variations), $variations);
    MediaConversion::query()->update(['status' => ConversionStatus::Completed]);
    Queue::fake();

    $state = ImagePickerState::fromStoredValue($id, $variations);
    $state['alt'] = 'Edited';
    $state['variants']['mobile']['focus_x'] = 0.2;

    expect(persist($state, $variations))->toBe($id);

    $value = MediaFieldValue::with('conversions')->find($id);
    expect(MediaFieldValue::query()->count())->toBe(1)
        ->and($value->alt_text)->toBe('Edited')
        ->and($value->conversion('mobile')->status)->toBe(ConversionStatus::Pending)
        ->and($value->conversion('desktop')->status)->toBe(ConversionStatus::Completed);
    Queue::assertPushed(GenerateConversion::class, 1);
});

it('does not regenerate when only an override alt changes', function () use ($variations) {
    $override = Media::factory()->create();
    $state = ImagePickerState::withVariantImage(ImagePickerState::withMainImage(null, Media::factory()->create(), $variations), 'mobile', $override);
    $id = persist($state, $variations);
    Queue::fake();

    $state = ImagePickerState::fromStoredValue($id, $variations);
    $state['variants']['mobile']['alt'] = 'Speaker';
    persist($state, $variations);

    expect(MediaFieldValue::find($id)->conversions()->where('variation', 'mobile')->value('alt_text'))->toBe('Speaker');
    Queue::assertNothingPushed();
});

it('regenerates only non-overridden variants when the main image changes', function () use ($variations) {
    $override = Media::factory()->create();
    $state = ImagePickerState::withVariantImage(ImagePickerState::withMainImage(null, Media::factory()->create(), $variations), 'mobile', $override);
    $id = persist($state, $variations);
    Queue::fake();

    $next = ImagePickerState::withMainImage(ImagePickerState::fromStoredValue($id, $variations), Media::factory()->create(), $variations);
    persist($next, $variations);

    Queue::assertPushed(GenerateConversion::class, 1);
    Queue::assertPushed(GenerateConversion::class, fn (GenerateConversion $job) => $job->conversion->variation === 'desktop');
});

it('stores override image and alt, and drops alt without an override', function () use ($variations) {
    $override = Media::factory()->create(['alt_text' => 'Speaker']);
    $state = ImagePickerState::withVariantImage(ImagePickerState::withMainImage(null, Media::factory()->create(), $variations), 'mobile', $override);
    $state['variants']['desktop']['alt'] = 'Should be dropped';

    $value = MediaFieldValue::with('conversions')->find(persist($state, $variations));

    expect($value->conversion('mobile')->media_id)->toBe($override->id)
        ->and($value->conversion('mobile')->alt_text)->toBe('Speaker')
        ->and($value->conversion('desktop')->alt_text)->toBeNull();
});

it('clamps focus and zoom', function () use ($variations) {
    $state = ImagePickerState::withMainImage(null, Media::factory()->create(), $variations);
    $state['variants']['desktop'] = [...$state['variants']['desktop'], 'focus_x' => -1, 'focus_y' => 5, 'zoom' => 12];
    $state['variants']['mobile']['zoom'] = 0.2;

    $value = MediaFieldValue::with('conversions')->find(persist($state, $variations));

    expect($value->conversion('desktop')->focus_x)->toBe(0.0)
        ->and($value->conversion('desktop')->focus_y)->toBe(1.0)
        ->and($value->conversion('desktop')->zoom)->toBe(5.0)
        ->and($value->conversion('mobile')->zoom)->toBe(1.0);
});

it('deletes conversions for variations that are no longer declared', function () use ($variations) {
    Storage::fake('public');
    $id = persist(ImagePickerState::withMainImage(null, Media::factory()->create(), $variations), $variations);
    $retiredPath = MediaFieldValue::find($id)->directory() . '/mobile-old.webp';
    Storage::disk('public')->put($retiredPath, 'x');
    MediaConversion::query()->where('variation', 'mobile')->update(['path' => $retiredPath]);

    persist(ImagePickerState::fromStoredValue($id, ['desktop' => [1500, 900]]), ['desktop' => [1500, 900]]);

    expect(MediaConversion::query()->pluck('variation')->all())->toBe(['desktop']);
    Storage::disk('public')->assertMissing($retiredPath);
});

it('regenerates a variant whose declared size changed', function () use ($variations) {
    $id = persist(ImagePickerState::withMainImage(null, Media::factory()->create(), $variations), $variations);
    MediaConversion::query()->update(['status' => ConversionStatus::Completed]);
    Queue::fake();

    $resized = [...$variations, 'mobile' => [600, 800]];
    persist(ImagePickerState::fromStoredValue($id, $resized), $resized);

    expect(MediaConversion::query()->where('variation', 'mobile')->value('width'))->toBe(600);
    Queue::assertPushed(GenerateConversion::class, 1);
});

it('deletes the saved row when the image was removed', function () use ($variations) {
    $id = persist(ImagePickerState::withMainImage(null, Media::factory()->create(), $variations), $variations);

    $result = persist(ImagePickerState::withoutImage(ImagePickerState::fromStoredValue($id, $variations)), $variations);

    expect($result)->toBeNull()
        ->and(MediaFieldValue::query()->count())->toBe(0)
        ->and(MediaConversion::query()->count())->toBe(0);
});

it('persists nothing for an empty field', function () use ($variations) {
    expect(persist(null, $variations))->toBeNull()
        ->and(MediaFieldValue::query()->count())->toBe(0);
});

it('passes scalar ids through untouched', function () use ($variations) {
    expect(persist(42, $variations))->toBe(42)
        ->and(persist('42', $variations))->toBe(42)
        ->and(MediaFieldValue::query()->count())->toBe(0);
});

it('recreates the row when the saved id no longer exists', function () use ($variations) {
    $state = [...ImagePickerState::withMainImage(null, Media::factory()->create(), $variations), 'id' => 999999];

    $id = persist($state, $variations);

    expect($id)->not->toBe(999999)
        ->and(MediaFieldValue::query()->whereKey($id)->exists())->toBeTrue();
});
```

- [ ] **Step 2: Run to verify failure**

Run: `plugin-pest tests/PersistImagePickerTest.php`
Expected: FAIL — class `PersistImagePicker` not found.

- [ ] **Step 3: Implement** — `src/Actions/PersistImagePicker.php`:

```php
<?php

namespace MarvelousDigital\FilamentMediaManager\Actions;

use Illuminate\Support\Facades\Auth;
use MarvelousDigital\FilamentMediaManager\Enums\ConversionStatus;
use MarvelousDigital\FilamentMediaManager\Jobs\GenerateConversion;
use MarvelousDigital\FilamentMediaManager\Models\MediaConversion;
use MarvelousDigital\FilamentMediaManager\Models\MediaFieldValue;
use MarvelousDigital\FilamentMediaManager\Support\ImagePickerState;

class PersistImagePicker
{
    /** Columns whose change requires regenerating the conversion file. */
    private const SHAPING_COLUMNS = ['media_id', 'width', 'height', 'focus_x', 'focus_y', 'zoom'];

    /**
     * Write the ImagePicker's form state to the database and return the id to store on the
     * parent. Scalar state (never hydrated) passes through untouched.
     *
     * @param  array<string, array{0: int, 1: int}>  $variations
     */
    public function execute(mixed $state, array $variations): ?int
    {
        if (! is_array($state)) {
            return is_numeric($state) ? (int) $state : null;
        }

        $existing = filled($state['id'] ?? null)
            ? MediaFieldValue::query()->with('conversions')->find((int) $state['id'])
            : null;

        if (blank($state['media_id'] ?? null)) {
            $existing?->delete();

            return null;
        }

        $value = $existing ?? new MediaFieldValue(['created_by' => Auth::id()]);
        $mainImageChanged = $value->exists && $value->media_id !== (int) $state['media_id'];

        $value->fill([
            'media_id' => (int) $state['media_id'],
            'alt_text' => $state['alt'] ?? null,
        ])->save();

        $conversions = $value->conversions->keyBy('variation');

        foreach ($variations as $name => [$width, $height]) {
            $variant = [...ImagePickerState::defaultVariant(), ...($state['variants'][$name] ?? [])];
            $overrideId = filled($variant['media_id']) ? (int) $variant['media_id'] : null;

            /** @var MediaConversion $conversion */
            $conversion = $conversions->get($name) ?? $value->conversions()->make(['variation' => $name]);

            $conversion->fill([
                'media_id' => $overrideId,
                'alt_text' => $overrideId !== null ? ($variant['alt'] ?? null) : null,
                'width' => $width,
                'height' => $height,
                'focus_x' => $this->clamp((float) $variant['focus_x'], 0.0, 1.0),
                'focus_y' => $this->clamp((float) $variant['focus_y'], 0.0, 1.0),
                'zoom' => $this->clamp((float) $variant['zoom'], 1.0, 5.0),
            ]);

            $needsRegeneration = ! $conversion->exists
                || $conversion->isDirty(self::SHAPING_COLUMNS)
                || ($mainImageChanged && $overrideId === null);

            if ($needsRegeneration) {
                $conversion->status = ConversionStatus::Pending;
            }

            $conversion->save();

            if ($needsRegeneration) {
                GenerateConversion::dispatchFor($conversion);
            }
        }

        $conversions->except(array_keys($variations))->each->delete();

        return $value->id;
    }

    private function clamp(float $value, float $min, float $max): float
    {
        return max($min, min($max, $value));
    }
}
```

- [ ] **Step 4: Run to verify pass**

Run: `plugin-pest tests/PersistImagePickerTest.php`
Expected: PASS.

- [ ] **Step 5: Lint and commit**

```bash
plugin-lint
cd packages/filament-media-manager && git add src/Actions/PersistImagePicker.php tests/PersistImagePickerTest.php && git commit -m "feat: PersistImagePicker writes picker state on save"
```

---

### Task 7: ImagePicker field — hydrate, save, validation, picking and upload

**Files:**
- Modify: `src/Forms/Components/ImagePicker.php`, `src/Livewire/MediaPickerBrowser.php`, `resources/views/forms/components/image-picker-modal.blade.php`
- Create: `tests/ImagePickerFormTest.php`
- Modify: `tests/MediaPickerBrowserTest.php`
- Delete: `src/Actions/PickMedia.php`, `tests/PickMediaTest.php`, `src/Actions/UpdateConversionFocus.php`, `tests/ImagePickerFocusTest.php`, `tests/ImagePickerFieldActionsTest.php` (coverage now in `PersistImagePickerTest`, `ImagePickerStateTest`, `ImagePickerFormTest`)
- Modify: `README.md` (ImagePicker section)

**Interfaces:**
- Consumes: `ImagePickerState`, `PersistImagePicker`, `UploadMedia`, `Media::ensureDimensions()`.
- Produces (used by the Task 8 view):
  - Exposed Livewire methods on the field (called with `$wire.callSchemaComponentMethod(key, method, args)`): `selectImage(string $target, int $mediaId)`, `storeUploadedImage(string $target)`, `useMainImage(string $variation)`, `removeImage()`. `$target` is `'main'` or a variation name.
  - `getMediaPayload(): array<int, array{url: string|null, width: int|null, height: int|null}>` — keyed by media id for the main image and all overrides.
  - `getSavedValue(): ?MediaFieldValue` (with conversions).
  - Actions: `select` (arguments `statePath`, `target`), `retryConversion` (argument `conversion`).
  - Browser event `image-picker:selected` detail `{statePath, target, mediaId}`; `image-picker:close`.

- [ ] **Step 1: Write the failing tests** — `tests/ImagePickerFormTest.php`:

```php
<?php

use Filament\Actions\Concerns\InteractsWithActions;
use Filament\Actions\Contracts\HasActions;
use Filament\Actions\Testing\TestAction;
use Filament\Forms\Components\Builder;
use Filament\Forms\Components\Builder\Block;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Schemas\Schema;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Livewire\Component;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use MarvelousDigital\FilamentMediaManager\Enums\ConversionStatus;
use MarvelousDigital\FilamentMediaManager\Forms\Components\ImagePicker;
use MarvelousDigital\FilamentMediaManager\Jobs\GenerateConversion;
use MarvelousDigital\FilamentMediaManager\Models\Media;
use MarvelousDigital\FilamentMediaManager\Models\MediaConversion;
use MarvelousDigital\FilamentMediaManager\Models\MediaFieldValue;
use MarvelousDigital\FilamentMediaManager\Support\ImagePickerState;

class ImagePickerFormHarness extends Component implements HasActions, HasForms
{
    use InteractsWithActions;
    use InteractsWithForms;

    /** @var array<string, mixed> */
    public array $data = [];

    /** @var array<string, mixed> */
    public array $saved = [];

    public ?int $preset = null;

    public bool $withRequiredTitle = false;

    public function mount(): void
    {
        $this->form->fill(['hero' => $this->preset]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('title')->required($this->withRequiredTitle),
                ImagePicker::make('hero')->variations(['desktop' => [1500, 900], 'mobile' => [480, 640]]),
            ])
            ->statePath('data');
    }

    public function save(): void
    {
        $this->saved = $this->form->getState();
    }

    public function render(): string
    {
        return '<div>{{ $this->form }}</div>';
    }
}

class BuilderImagePickerFormHarness extends Component implements HasActions, HasForms
{
    use InteractsWithActions;
    use InteractsWithForms;

    /** @var array<string, mixed> */
    public array $data = [];

    /** @var array<string, mixed> */
    public array $saved = [];

    public function mount(): void
    {
        $this->form->fill(['blocks' => [['type' => 'image', 'data' => ['image' => null]]]]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Builder::make('blocks')->blocks([
                    Block::make('image')->schema([
                        ImagePicker::make('image')->variations(['hero' => [1600, 600]]),
                    ]),
                ]),
            ])
            ->statePath('data');
    }

    public function save(): void
    {
        $this->saved = $this->form->getState();
    }

    public function render(): string
    {
        return '<div>{{ $this->form }}</div>';
    }
}

function heroKey(Testable $livewire): string
{
    return collect($livewire->instance()->form->getFlatFields(withHidden: true))
        ->first(fn ($field): bool => $field instanceof ImagePicker)
        ->getKey();
}

function callPicker(Testable $livewire, string $method, array $arguments = []): Testable
{
    return $livewire->call('callSchemaComponentMethod', heroKey($livewire), $method, $arguments);
}

beforeEach(fn () => Queue::fake());

it('hydrates a saved id into structured state', function () {
    $value = MediaFieldValue::factory()->for(Media::factory(), 'source')->create(['alt_text' => 'Main']);

    Livewire::test(ImagePickerFormHarness::class, ['preset' => $value->id])
        ->assertSet('data.hero.id', $value->id)
        ->assertSet('data.hero.alt', 'Main')
        ->assertSet('data.hero.dirty', false)
        ->assertSet('data.hero.variants.mobile.zoom', 1.0);
});

it('selects a library image into state without touching the database', function () {
    $media = Media::factory()->create(['alt_text' => 'Cat']);

    $livewire = Livewire::test(ImagePickerFormHarness::class);
    callPicker($livewire, 'selectImage', ['target' => 'main', 'mediaId' => $media->id]);

    $livewire->assertSet('data.hero.media_id', $media->id)
        ->assertSet('data.hero.alt', 'Cat')
        ->assertSet('data.hero.dirty', true);
    expect(MediaFieldValue::query()->count())->toBe(0);
});

it('overrides and resets a single variant', function () {
    $main = Media::factory()->create();
    $override = Media::factory()->create(['alt_text' => 'Speaker']);

    $livewire = Livewire::test(ImagePickerFormHarness::class);
    callPicker($livewire, 'selectImage', ['target' => 'main', 'mediaId' => $main->id]);
    callPicker($livewire, 'selectImage', ['target' => 'mobile', 'mediaId' => $override->id]);

    $livewire->assertSet('data.hero.variants.mobile.media_id', $override->id)
        ->assertSet('data.hero.variants.mobile.alt', 'Speaker')
        ->assertSet('data.hero.variants.desktop.media_id', null);

    callPicker($livewire, 'useMainImage', ['variation' => 'mobile']);

    $livewire->assertSet('data.hero.variants.mobile.media_id', null);
});

it('uploads straight into the field and selects the new image', function () {
    Storage::fake('public');

    $livewire = Livewire::test(ImagePickerFormHarness::class)
        ->set('componentFileAttachments.data.hero', UploadedFile::fake()->image('new.jpg', 800, 600));
    callPicker($livewire, 'storeUploadedImage', ['target' => 'main']);

    $media = Media::query()->sole();
    $livewire->assertSet('data.hero.media_id', $media->id)
        ->assertSet('componentFileAttachments.data.hero', null);
    expect($media->width)->toBe(800);
});

it('persists on save and writes the new id back into state', function () {
    $media = Media::factory()->create();

    $livewire = Livewire::test(ImagePickerFormHarness::class);
    callPicker($livewire, 'selectImage', ['target' => 'main', 'mediaId' => $media->id]);
    $livewire->set('data.hero.variants.desktop.focus_x', 0.25)->call('save');

    $value = MediaFieldValue::with('conversions')->sole();
    $livewire->assertSet('saved.hero', $value->id)
        ->assertSet('data.hero.id', $value->id)
        ->assertSet('data.hero.dirty', false);
    expect($value->conversion('desktop')->focus_x)->toBe(0.25);
});

it('updates the same row on a second save', function () {
    $media = Media::factory()->create();

    $livewire = Livewire::test(ImagePickerFormHarness::class);
    callPicker($livewire, 'selectImage', ['target' => 'main', 'mediaId' => $media->id]);
    $livewire->call('save')
        ->set('data.hero.alt', 'Second pass')
        ->call('save');

    expect(MediaFieldValue::query()->count())->toBe(1)
        ->and(MediaFieldValue::query()->sole()->alt_text)->toBe('Second pass');
});

it('persists nothing when another field fails validation', function () {
    $media = Media::factory()->create();

    $livewire = Livewire::test(ImagePickerFormHarness::class, ['withRequiredTitle' => true]);
    callPicker($livewire, 'selectImage', ['target' => 'main', 'mediaId' => $media->id]);
    $livewire->call('save')->assertHasErrors(['data.title']);

    expect(MediaFieldValue::query()->count())->toBe(0);
    Queue::assertNothingPushed();
});

it('fails validation when a picked image was deleted before saving', function () {
    $media = Media::factory()->create();

    $livewire = Livewire::test(ImagePickerFormHarness::class);
    callPicker($livewire, 'selectImage', ['target' => 'main', 'mediaId' => $media->id]);
    $media->delete();

    $livewire->call('save')->assertHasErrors(['data.hero']);
    expect(MediaFieldValue::query()->count())->toBe(0);
});

it('removes a saved image on save', function () {
    $value = MediaFieldValue::factory()->for(Media::factory(), 'source')->create();

    $livewire = Livewire::test(ImagePickerFormHarness::class, ['preset' => $value->id]);
    callPicker($livewire, 'removeImage');
    $livewire->call('save')->assertSet('saved.hero', null);

    expect(MediaFieldValue::query()->count())->toBe(0);
});

it('persists when nested inside a builder block', function () {
    $media = Media::factory()->create();

    $livewire = Livewire::test(BuilderImagePickerFormHarness::class);
    $itemKey = array_key_first($livewire->get('data.blocks'));
    $livewire->set("data.blocks.{$itemKey}.data.image", ImagePickerState::withMainImage(null, $media, ['hero' => [1600, 600]]))
        ->call('save');

    $value = MediaFieldValue::query()->sole();
    expect(data_get($livewire->get('saved'), 'blocks.0.data.image'))->toBe($value->id);
});

it('re-dispatches generation via retryConversion for its own conversion only', function () {
    $own = MediaFieldValue::factory()->for(Media::factory(), 'source')->create();
    $ownConversion = MediaConversion::factory()->for($own, 'value')->create(['variation' => 'desktop', 'status' => ConversionStatus::Failed]);
    $foreign = MediaConversion::factory()->for(MediaFieldValue::factory()->for(Media::factory(), 'source'), 'value')->create(['status' => ConversionStatus::Failed]);

    $livewire = Livewire::test(ImagePickerFormHarness::class, ['preset' => $own->id]);
    $livewire->callAction(TestAction::make('retryConversion')->schemaComponent(heroKey($livewire)), arguments: ['conversion' => $foreign->id]);
    $livewire->callAction(TestAction::make('retryConversion')->schemaComponent(heroKey($livewire)), arguments: ['conversion' => $ownConversion->id]);

    expect($ownConversion->refresh()->status)->toBe(ConversionStatus::Pending)
        ->and($foreign->refresh()->status)->toBe(ConversionStatus::Failed);
    Queue::assertPushed(GenerateConversion::class, 1);
});
```

Replace the second test in `tests/MediaPickerBrowserTest.php` with:

```php
it('dispatches the selection with its target and persists nothing', function () {
    $media = Media::factory()->create(['type' => MediaType::Image]);

    Livewire::test(MediaPickerBrowser::class, ['statePath' => 'data.hero', 'target' => 'mobile'])
        ->call('select', $media->id)
        ->assertDispatched('image-picker:selected', statePath: 'data.hero', target: 'mobile', mediaId: $media->id)
        ->assertDispatched('image-picker:close');

    expect(MediaFieldValue::query()->count())->toBe(0);
});
```

and in its first test change the mount arguments to `['statePath' => 'data.hero_image_id', 'target' => 'main']`.

- [ ] **Step 2: Run to verify failure**

Run: `plugin-pest tests/ImagePickerFormTest.php tests/MediaPickerBrowserTest.php`
Expected: FAIL — `data.hero.id` not set (state is scalar), `selectImage` returns null (not exposed).

- [ ] **Step 3: Implement the field** — replace `src/Forms/Components/ImagePicker.php`:

```php
<?php

namespace MarvelousDigital\FilamentMediaManager\Forms\Components;

use Closure;
use Filament\Actions\Action;
use Filament\Forms\Components\Concerns\HasFileAttachments;
use Filament\Forms\Components\Field;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Component;
use Filament\Support\Components\Attributes\ExposedLivewireMethod;
use Filament\Support\Enums\Width;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\ValidationException;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use MarvelousDigital\FilamentMediaManager\Actions\PersistImagePicker;
use MarvelousDigital\FilamentMediaManager\Actions\UploadMedia;
use MarvelousDigital\FilamentMediaManager\Enums\ConversionStatus;
use MarvelousDigital\FilamentMediaManager\Enums\MediaType;
use MarvelousDigital\FilamentMediaManager\Jobs\GenerateConversion;
use MarvelousDigital\FilamentMediaManager\Models\Media;
use MarvelousDigital\FilamentMediaManager\Models\MediaConversion;
use MarvelousDigital\FilamentMediaManager\Models\MediaFieldValue;
use MarvelousDigital\FilamentMediaManager\Support\ImagePickerState;

class ImagePicker extends Field
{
    use HasFileAttachments;

    protected string $view = 'filament-media-manager::forms.components.image-picker';

    /** @var array<string, array{0: int, 1: int}> */
    protected array $variations = [];

    protected ?string $pickerDisk = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->afterStateHydrated(static function (ImagePicker $component, mixed $state): void {
            $component->state(ImagePickerState::fromStoredValue($state, $component->getVariations()));
        });

        $this->dehydrateStateUsing(static function (ImagePicker $component, mixed $state): ?int {
            $id = app(PersistImagePicker::class)->execute($state, $component->getVariations());

            // Filament does not refill forms after saving; write the persisted state back so a
            // later save updates this row instead of creating another.
            $component->state(ImagePickerState::fromStoredValue($id, $component->getVariations()));

            return $id;
        });

        $this->rule(static fn (ImagePicker $component): Closure => static function (string $attribute, mixed $value, Closure $fail) use ($component): void {
            if (! is_array($value)) {
                return;
            }

            if (blank($value['media_id'] ?? null)) {
                if ($component->isRequired()) {
                    $fail(__('validation.required', ['attribute' => $component->getValidationAttribute()]));
                }

                return;
            }

            $mediaIds = array_unique(array_filter([
                $value['media_id'],
                ...array_column($value['variants'] ?? [], 'media_id'),
            ]));

            if (Media::query()->whereKey($mediaIds)->count() !== count($mediaIds)) {
                $fail(__('This image is no longer in the library.'));
            }
        });

        $this->registerActions([
            fn (): Action => Action::make('select')
                ->modalHeading(__('Select an image'))
                ->modalWidth(Width::FiveExtraLarge)
                ->modalContent(fn (array $arguments): View => view('filament-media-manager::forms.components.image-picker-modal', [
                    'statePath' => $arguments['statePath'] ?? '',
                    'target' => $arguments['target'] ?? 'main',
                ]))
                ->modalSubmitAction(false)
                ->modalCancelAction(false),
            fn (): Action => Action::make('retryConversion')
                ->action(function (array $arguments, Component $component): void {
                    $conversion = MediaConversion::find((int) ($arguments['conversion'] ?? 0));
                    $valueId = (int) ($component->getState()['id'] ?? 0);

                    if (! $conversion || $valueId === 0 || $conversion->media_field_value_id !== $valueId) {
                        return;
                    }

                    $conversion->update(['status' => ConversionStatus::Pending]);

                    GenerateConversion::dispatchFor($conversion);
                }),
        ]);
    }

    /**
     * @param  array<string, array{0: int, 1: int}>  $variations
     */
    public function variations(array $variations): static
    {
        $this->variations = $variations;

        return $this;
    }

    public function disk(string $disk): static
    {
        $this->pickerDisk = $disk;

        return $this;
    }

    /**
     * @return array<string, array{0: int, 1: int}>
     */
    public function getVariations(): array
    {
        return $this->variations !== [] ? $this->variations : ['default' => [1200, 1200]];
    }

    public function getDisk(): string
    {
        return $this->pickerDisk ?? config('filament-media-manager.disk');
    }

    #[ExposedLivewireMethod]
    public function selectImage(string $target, int $mediaId): void
    {
        $media = Media::query()->whereKey($mediaId)->where('type', MediaType::Image)->first();

        if (! $media) {
            Notification::make()->title(__('This image is no longer in the library.'))->danger()->send();

            return;
        }

        $this->applyMedia($target, $media);
    }

    #[ExposedLivewireMethod]
    public function storeUploadedImage(string $target): void
    {
        $file = $this->getUploadedFileAttachment();

        try {
            if (! $file instanceof TemporaryUploadedFile) {
                Notification::make()->title(__('Upload failed'))->body(__('The file could not be uploaded. Please try again.'))->danger()->send();

                return;
            }

            $media = app(UploadMedia::class)->execute($file);
        } catch (ValidationException $exception) {
            Notification::make()
                ->title(__('Upload failed'))
                ->body(implode(' ', collect($exception->errors())->flatten()->all()))
                ->danger()
                ->send();

            return;
        } finally {
            data_set($this->getLivewire(), "componentFileAttachments.{$this->getStatePath()}", null);
        }

        $this->applyMedia($target, $media);
    }

    #[ExposedLivewireMethod]
    public function useMainImage(string $variation): void
    {
        $state = $this->getState();

        if (is_array($state)) {
            $this->state(ImagePickerState::withoutVariantImage($state, $variation));
        }
    }

    #[ExposedLivewireMethod]
    public function removeImage(): void
    {
        $state = $this->getState();

        $this->state(ImagePickerState::withoutImage(is_array($state) ? $state : null));
    }

    /**
     * The generic attachment endpoint from HasFileAttachments is not used by this field;
     * overriding it without the ExposedLivewireMethod attribute keeps it off the wire.
     */
    public function saveUploadedFileAttachmentAndGetUrl(): ?string
    {
        return null;
    }

    public function getSavedValue(): ?MediaFieldValue
    {
        $id = $this->getState()['id'] ?? null;

        return $id ? MediaFieldValue::query()->with('conversions')->find($id) : null;
    }

    /**
     * @return array<int, array{url: string|null, width: int|null, height: int|null}>
     */
    public function getMediaPayload(): array
    {
        $state = $this->getState();

        if (! is_array($state)) {
            return [];
        }

        $mediaIds = array_filter([$state['media_id'] ?? null, ...array_column($state['variants'] ?? [], 'media_id')]);

        return Media::query()
            ->whereKey($mediaIds)
            ->get()
            ->mapWithKeys(fn (Media $media): array => [$media->id => [
                'url' => $media->url(),
                'width' => $media->width,
                'height' => $media->height,
            ]])
            ->all();
    }

    protected function applyMedia(string $target, Media $media): void
    {
        $media->ensureDimensions();

        $state = $this->getState();

        $this->state($target === 'main' || ! is_array($state)
            ? ImagePickerState::withMainImage(is_array($state) ? $state : null, $media, $this->getVariations())
            : ImagePickerState::withVariantImage($state, $target, $media));
    }
}
```

Note: the existing `dehydrated(true)` call is dropped. Fields are dehydrated by default.

- [ ] **Step 4: Update the picker browser.** In `src/Livewire/MediaPickerBrowser.php` remove the `$variations` property and the `PickMedia` import, add `public string $target = 'main';`, and replace `select()`:

```php
    public function select(int $mediaId): void
    {
        if (! Media::query()->whereKey($mediaId)->exists()) {
            return;
        }

        $this->dispatch('image-picker:selected', statePath: $this->statePath, target: $this->target, mediaId: $mediaId);
        $this->dispatch('image-picker:close');
    }
```

`resources/views/forms/components/image-picker-modal.blade.php`:

```blade
<div>
    @livewire(
        'filament-media-manager::media-picker-browser',
        ['statePath' => $statePath, 'target' => $target],
        key($statePath . '-' . $target . '-browser')
    )
</div>
```

- [ ] **Step 5: Delete superseded code and tests**

```bash
cd packages/filament-media-manager && git rm src/Actions/PickMedia.php tests/PickMediaTest.php src/Actions/UpdateConversionFocus.php tests/ImagePickerFocusTest.php tests/ImagePickerFieldActionsTest.php
```

Coverage mapping, to state in the commit body: PickMedia seeding/alt copying → `PersistImagePickerTest` "creates a value…" plus `ImagePickerStateTest` "picks a main image…". UpdateConversionFocus clamping/regeneration → `PersistImagePickerTest` "clamps…", "updates the existing row…". Field actions retry/foreign refusal/builder nesting → `ImagePickerFormTest`.

- [ ] **Step 6: Run to verify pass**

Run: `plugin-pest tests/ImagePickerFormTest.php tests/MediaPickerBrowserTest.php tests/ImagePickerFieldTest.php`
Expected: PASS. If `getFlatFields(withHidden: true)` doesn't return the field's key in this Filament build, check `vendor/filament/schemas/src/Concerns/HasComponents.php:251` for the signature and adjust `heroKey()`. Don't change the field to fit the test.

The view tests (`ImagePickerViewTest`) may fail until Task 8. That's expected.

- [ ] **Step 7: Update the README ImagePicker section** (`README.md`, lines 124–169). Replace the paragraph starting "Picking an image creates…" with:

```markdown
The field shows the formats it needs (as ratios) before anything is picked. Admins upload a new image or pick one from the library, reframe each variation with a focus point and zoom, and can give any variation its own image (with its own focus, zoom and alt text). **Nothing is written until the form is saved**; cancelling discards all image edits. On save the field stores a `MediaFieldValue` id, updating the existing record in place, and regenerates only the variations whose framing changed. The previous crop keeps being served until its replacement is ready. Generation uses the `thumbnail.*` config knobs (`thumbnail.queue`, `thumbnail.queue_name`, `thumbnail.format`, `thumbnail.quality`), so run a queue worker in production or set `thumbnail.queue` to `false`.
```

and replace the consuming snippet with:

```php
$post->heroImage?->conversion('hero')?->url();               // a named conversion
$post->heroImage?->conversion('hero')?->effectiveAltText();  // variation alt (own image) or main alt
$post->heroImage?->alt_text;                                  // main alt text
$post->heroImage?->url();                                     // the original, unconverted main image
```

- [ ] **Step 8: Lint, run the whole plugin suite, commit**

```bash
plugin-lint
plugin-pest --compact
```

Expected: everything passes except possibly `tests/ImagePickerViewTest.php` (fixed in Task 8). If other tests fail, fix them before committing.

```bash
cd packages/filament-media-manager && git add -A src tests resources/views/forms/components/image-picker-modal.blade.php README.md && git commit -m "feat: ImagePicker holds edits in form state and persists on save"
```

---

### Task 8: Field UI — required formats, variant cards, reframe, upload

**Files:**
- Modify: `resources/views/forms/components/image-picker.blade.php` (full rewrite)
- Modify: `resources/dist/filament-media-manager.css`
- Test: `tests/ImagePickerViewTest.php`

**Interfaces:**
- Consumes: field methods and actions from Task 7; `Ratio::reduce()` (Task 2).

- [ ] **Step 1: Write the failing tests** — replace `tests/ImagePickerViewTest.php`:

```php
<?php

use Filament\Actions\Concerns\InteractsWithActions;
use Filament\Actions\Contracts\HasActions;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Schemas\Schema;
use Livewire\Component;
use Livewire\Livewire;
use MarvelousDigital\FilamentMediaManager\Enums\ConversionStatus;
use MarvelousDigital\FilamentMediaManager\Forms\Components\ImagePicker;
use MarvelousDigital\FilamentMediaManager\Models\Media;
use MarvelousDigital\FilamentMediaManager\Models\MediaConversion;
use MarvelousDigital\FilamentMediaManager\Models\MediaFieldValue;

class RenderHarness extends Component implements HasActions, HasForms
{
    use InteractsWithActions;
    use InteractsWithForms;

    public array $data = [];

    public ?int $preset = null;

    public function mount(): void
    {
        $this->form->fill(['hero_image_id' => $this->preset]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            ImagePicker::make('hero_image_id')->variations(['desktop' => [1500, 900], 'mobile' => [480, 640]]),
        ])->statePath('data');
    }

    public function render(): string
    {
        return '<div>{{ $this->form }}</div>';
    }
}

it('shows the required formats as ratios before anything is picked', function () {
    Livewire::test(RenderHarness::class)
        ->assertOk()
        ->assertSee('Upload')
        ->assertSee('Pick from library')
        ->assertSee('Required formats')
        ->assertSee('desktop · 5:3')
        ->assertSee('mobile · 3:4')
        ->assertSee('1500×900');
});

it('renders a card per variant with reframe and override actions', function () {
    $value = MediaFieldValue::factory()->for(Media::factory()->state(['width' => 2000, 'height' => 1000]), 'source')->create(['alt_text' => 'hi']);
    MediaConversion::factory()->for($value, 'value')->create(['variation' => 'desktop', 'status' => ConversionStatus::Failed]);

    Livewire::test(RenderHarness::class, ['preset' => $value->id])
        ->assertOk()
        ->assertSee('Alt text')
        ->assertSee('Reframe')
        ->assertSee('Use different image')
        ->assertSee('Zoom')
        ->assertSee('Retry');
});

it('shows own-image controls only for overridden variants', function () {
    $value = MediaFieldValue::factory()->for(Media::factory(), 'source')->create();
    MediaConversion::factory()->for($value, 'value')->create(['variation' => 'mobile', 'media_id' => Media::factory()->create()->id]);

    Livewire::test(RenderHarness::class, ['preset' => $value->id])
        ->assertSee('Own image')
        ->assertSee('Use main image');
});

it('renders variant cards when the source has no dimensions', function () {
    $value = MediaFieldValue::factory()->for(Media::factory()->state(['width' => null, 'height' => null]), 'source')->create();

    Livewire::test(RenderHarness::class, ['preset' => $value->id])
        ->assertOk()
        ->assertSee('Reframe');
});

it('polls while a conversion is still generating', function () {
    $value = MediaFieldValue::factory()->for(Media::factory(), 'source')->create();
    MediaConversion::factory()->for($value, 'value')->create(['variation' => 'desktop', 'status' => ConversionStatus::Pending]);

    Livewire::test(RenderHarness::class, ['preset' => $value->id])
        ->assertSee('Generating')
        ->assertSee('wire:poll', escape: false);
});

it('stops polling once every conversion is completed', function () {
    $value = MediaFieldValue::factory()->for(Media::factory(), 'source')->create();
    MediaConversion::factory()->for($value, 'value')->create(['variation' => 'desktop', 'status' => ConversionStatus::Completed]);
    MediaConversion::factory()->for($value, 'value')->create(['variation' => 'mobile', 'status' => ConversionStatus::Completed]);

    Livewire::test(RenderHarness::class, ['preset' => $value->id])
        ->assertDontSee('Generating')
        ->assertDontSee('wire:poll', escape: false);
});
```

- [ ] **Step 2: Run to verify failure**

Run: `plugin-pest tests/ImagePickerViewTest.php`
Expected: FAIL — `Required formats` not found; the old view reads `$value` from a scalar state.

- [ ] **Step 3: Rewrite the view** — `resources/views/forms/components/image-picker.blade.php`:

```blade
@php
    use MarvelousDigital\FilamentMediaManager\Enums\ConversionStatus;
    use MarvelousDigital\FilamentMediaManager\Support\Ratio;

    $statePath = $getStatePath();
    $key = $getKey();
    $state = $getState();
    $variations = $getVariations();
    $hasImage = is_array($state) && filled($state['media_id'] ?? null);
    $media = $field->getMediaPayload();
    $saved = $field->getSavedValue();
    $isGenerating = $saved?->conversions->contains(fn ($conversion): bool => $conversion->status === ConversionStatus::Pending) ?? false;
@endphp

<x-dynamic-component :component="$getFieldWrapperView()" :field="$field">
    <div
        class="fmm-image-picker"
        wire:key="fmm-picker-{{ $statePath }}"
        @if ($isGenerating) wire:poll.3s @endif
        x-data="{
            state: $wire.{{ $applyStateBindingModifiers("\$entangle('{$statePath}')") }},
            media: @js($media),
            targets: @js($variations),
            open: null,
            uploading: false,
            init() {
                window.addEventListener('image-picker:selected', (e) => {
                    if (e.detail?.statePath !== @js($statePath)) return;
                    this.call('selectImage', { target: e.detail.target, mediaId: e.detail.mediaId });
                    $wire.unmountAction();
                });
                window.addEventListener('image-picker:close', () => $wire.unmountAction());
            },
            call(method, args = {}) {
                return $wire.callSchemaComponentMethod(@js($key), method, args);
            },
            pick(target) {
                $wire.mountAction('select', { statePath: @js($statePath), target }, { schemaComponent: @js($key) });
            },
            upload(target, files) {
                const file = files?.[0];
                if (! file) return;
                this.uploading = true;
                $wire.upload(
                    @js('componentFileAttachments.' . $statePath),
                    file,
                    () => this.call('storeUploadedImage', { target }).finally(() => { this.uploading = false; }),
                    () => { this.uploading = false; },
                );
            },
            touch() {
                this.state.dirty = true;
            },
            sourceOf(name) {
                const variant = this.state?.variants?.[name];
                return this.media[variant?.media_id ?? this.state?.media_id] ?? null;
            },
            hasSize(name) {
                const source = this.sourceOf(name);
                return !! (source?.width && source?.height);
            },
            cropBox(name) {
                const source = this.sourceOf(name);
                const variant = this.state.variants[name];
                const [tw, th] = this.targets[name];
                const sw = source.width, sh = source.height, ta = tw / th;
                let cw, ch;
                if (sw / sh > ta) { ch = sh; cw = Math.round(sh * ta); }
                else { cw = sw; ch = Math.round(sw / ta); }
                const z = Math.max(1, variant.zoom);
                cw = Math.round(cw / z);
                ch = Math.round(ch / z);
                const clamp = (v, min, max) => Math.max(min, Math.min(max, v));
                return {
                    x: clamp(Math.round(variant.focus_x * sw - cw / 2), 0, sw - cw),
                    y: clamp(Math.round(variant.focus_y * sh - ch / 2), 0, sh - ch),
                    width: cw,
                    height: ch,
                };
            },
            previewStyle(name) {
                const variant = this.state.variants[name];
                if (! this.hasSize(name)) {
                    return `width:100%;height:100%;object-fit:cover;object-position:${variant.focus_x * 100}% ${variant.focus_y * 100}%`;
                }
                const b = this.cropBox(name), source = this.sourceOf(name);
                return `position:absolute;max-width:none;height:auto;` +
                    `width:${source.width / b.width * 100}%;` +
                    `left:${-b.x / b.width * 100}%;` +
                    `top:${-b.y / b.height * 100}%`;
            },
            cropRectStyle(name) {
                if (! this.hasSize(name)) return 'display:none';
                const b = this.cropBox(name), source = this.sourceOf(name);
                return `left:${b.x / source.width * 100}%;top:${b.y / source.height * 100}%;` +
                    `width:${b.width / source.width * 100}%;height:${b.height / source.height * 100}%`;
            },
            overZoomed(name) {
                if (! this.hasSize(name)) return false;
                const source = this.sourceOf(name);
                const [tw, th] = this.targets[name];
                const ta = tw / th;
                const coverWidth = source.width / source.height > ta ? Math.round(source.height * ta) : source.width;
                return this.state.variants[name].zoom > coverWidth / tw + 0.001;
            },
            setFocus(name, event) {
                const rect = this.$refs['img-' + name].getBoundingClientRect();
                const variant = this.state.variants[name];
                variant.focus_x = Math.min(1, Math.max(0, (event.clientX - rect.left) / rect.width));
                variant.focus_y = Math.min(1, Math.max(0, (event.clientY - rect.top) / rect.height));
                this.touch();
            },
        }"
    >
        <input type="file" accept="image/*" class="fmm-upload-input" x-ref="upload-main" x-on:change="upload('main', $event.target.files); $event.target.value = null" />

        @if (! $hasImage)
            <div class="fmm-picker-empty">
                <div class="fmm-picker-buttons">
                    <x-filament::button icon="heroicon-o-arrow-up-tray" color="gray" x-on:click="$refs['upload-main'].click()" x-bind:disabled="uploading">
                        {{ __('Upload') }}
                    </x-filament::button>
                    <x-filament::button icon="heroicon-o-photo" color="gray" x-on:click="pick('main')">
                        {{ __('Pick from library') }}
                    </x-filament::button>
                    <span class="fmm-picker-uploading" x-cloak x-show="uploading">{{ __('Uploading…') }}</span>
                </div>

                <p class="fmm-formats-label">{{ __('Required formats') }}</p>
                <div class="fmm-formats">
                    @foreach ($variations as $name => [$width, $height])
                        <div class="fmm-format" wire:key="fmm-format-{{ $statePath }}-{{ $name }}">
                            <span class="fmm-format-box" style="aspect-ratio: {{ $width }} / {{ $height }}"></span>
                            <span class="fmm-format-name">{{ $name }} · {{ Ratio::reduce($width, $height) }}</span>
                            <span class="fmm-format-size">{{ $width }}×{{ $height }}</span>
                        </div>
                    @endforeach
                </div>
            </div>
        @else
            <div class="fmm-picker-preview">
                <img src="{{ $media[$state['media_id']]['url'] ?? '' }}" alt="{{ $state['alt'] }}" />
                <div class="fmm-picker-actions">
                    <x-filament::button size="sm" color="gray" icon="heroicon-o-photo" x-on:click="pick('main')">
                        {{ __('Change') }}
                    </x-filament::button>
                    <x-filament::button size="sm" color="gray" icon="heroicon-o-arrow-up-tray" x-on:click="$refs['upload-main'].click()" x-bind:disabled="uploading">
                        {{ __('Upload new') }}
                    </x-filament::button>
                    <x-filament::button size="sm" color="danger" icon="heroicon-o-trash" x-on:click="call('removeImage')">
                        {{ __('Remove') }}
                    </x-filament::button>
                    <span class="fmm-picker-uploading" x-cloak x-show="uploading">{{ __('Uploading…') }}</span>
                </div>
            </div>

            <div class="fmm-picker-alt">
                <label for="fmm-alt-{{ $statePath }}">{{ __('Alt text') }}</label>
                <input id="fmm-alt-{{ $statePath }}" type="text" class="fi-input fmm-alt-input" x-model="state.alt" x-on:input="touch()" placeholder="{{ __('Describe this image') }}" />
            </div>

            <p class="fmm-unsaved" x-cloak x-show="state?.dirty">
                {{ __('Not saved yet — image changes go live when you save.') }}
            </p>

            <div class="fmm-variants">
                @foreach ($variations as $name => [$width, $height])
                    @php
                        $variant = $state['variants'][$name] ?? [];
                        $isOverridden = filled($variant['media_id'] ?? null);
                        $conversion = $saved?->conversion($name);
                    @endphp

                    <div class="fmm-variant" wire:key="fmm-variant-{{ $statePath }}-{{ $name }}">
                        <div class="fmm-variant-header">
                            <span class="fmm-variant-name">{{ $name }} · {{ Ratio::reduce($width, $height) }}</span>
                            @if ($isOverridden)
                                <span class="fmm-variant-badge">{{ __('Own image') }}</span>
                            @endif
                        </div>

                        <div class="fmm-variant-thumb" style="aspect-ratio: {{ $width }} / {{ $height }}">
                            <img x-bind:src="sourceOf(@js($name))?.url" x-bind:style="previewStyle(@js($name))" alt="" />
                        </div>

                        <div class="fmm-variant-status">
                            @if ($conversion?->status === ConversionStatus::Failed)
                                <x-filament::button
                                    size="xs"
                                    color="warning"
                                    icon="heroicon-o-arrow-path"
                                    x-on:click="$wire.mountAction('retryConversion', { conversion: {{ $conversion->id }} }, { schemaComponent: @js($key) })"
                                >
                                    {{ __('Retry') }}
                                </x-filament::button>
                            @elseif ($conversion?->status === ConversionStatus::Pending)
                                <span class="fmm-variant-pending">{{ __('Generating…') }}</span>
                            @elseif ($conversion?->status === ConversionStatus::Completed)
                                <span class="fmm-variant-ready">{{ __('Ready') }}</span>
                            @endif
                        </div>

                        <div class="fmm-variant-actions">
                            <x-filament::button size="xs" color="gray" x-on:click="open = open === @js($name) ? null : @js($name)">
                                {{ __('Reframe') }}
                            </x-filament::button>
                            <x-filament::button size="xs" color="gray" x-on:click="pick(@js($name))">
                                {{ __('Use different image') }}
                            </x-filament::button>
                            <x-filament::button size="xs" color="gray" icon="heroicon-o-arrow-up-tray" x-on:click="$refs[@js('upload-' . $name)].click()" x-bind:disabled="uploading" :label-sr-only="true">
                                {{ __('Upload for this format') }}
                            </x-filament::button>
                            @if ($isOverridden)
                                <x-filament::button size="xs" color="gray" x-on:click="call('useMainImage', { variation: @js($name) })">
                                    {{ __('Use main image') }}
                                </x-filament::button>
                            @endif
                            <input type="file" accept="image/*" class="fmm-upload-input" x-ref="upload-{{ $name }}" x-on:change="upload(@js($name), $event.target.files); $event.target.value = null" />
                        </div>

                        @if ($isOverridden)
                            <div class="fmm-picker-alt">
                                <label for="fmm-alt-{{ $statePath }}-{{ $name }}">{{ __('Alt text for this format') }}</label>
                                <input id="fmm-alt-{{ $statePath }}-{{ $name }}" type="text" class="fi-input fmm-alt-input" x-model="state.variants[@js($name)].alt" x-on:input="touch()" />
                            </div>
                        @endif

                        <div class="fmm-reframe" x-cloak x-show="open === @js($name)">
                            <div
                                class="fmm-focus-image"
                                x-data="{ dragging: false }"
                                x-on:pointerdown.prevent="dragging = true; $event.target.setPointerCapture?.($event.pointerId); setFocus(@js($name), $event)"
                                x-on:pointermove="dragging && setFocus(@js($name), $event)"
                                x-on:pointerup="dragging = false"
                                x-on:pointercancel="dragging = false"
                            >
                                <img x-ref="img-{{ $name }}" x-bind:src="sourceOf(@js($name))?.url" alt="" draggable="false" />
                                <span class="fmm-focus-crop-rect" x-bind:style="cropRectStyle(@js($name))"></span>
                                <span class="fmm-focus-marker" x-bind:style="`left:${state.variants[@js($name)].focus_x * 100}%;top:${state.variants[@js($name)].focus_y * 100}%`"></span>
                            </div>

                            <div class="fmm-zoom">
                                <label class="fmm-zoom-label" for="fmm-zoom-{{ $statePath }}-{{ $name }}">
                                    {{ __('Zoom') }}
                                    <span class="fmm-zoom-value" x-text="`${Number(state.variants[@js($name)].zoom).toFixed(1)}×`"></span>
                                </label>
                                <input
                                    id="fmm-zoom-{{ $statePath }}-{{ $name }}"
                                    type="range"
                                    class="fmm-zoom-slider"
                                    min="1"
                                    max="5"
                                    step="0.1"
                                    x-model.number="state.variants[@js($name)].zoom"
                                    x-on:input="touch()"
                                />
                                <p class="fmm-zoom-warning" x-cloak x-show="overZoomed(@js($name))">
                                    {{ __('Zoomed past the source resolution — the image will be upscaled and may look soft.') }}
                                </p>
                            </div>
                        </div>
                    </div>
                @endforeach
            </div>
        @endif
    </div>
</x-dynamic-component>
```

- [ ] **Step 4: Update CSS** in `resources/dist/filament-media-manager.css`. Remove the now-unused rules `.fmm-focus-grid`, `.fmm-focus-editor` (and its `.dark` variant), `.fmm-focus-label`, `.fmm-focus-preview` (and `.dark` + `img` variants), `.fmm-focus-pending`. Append:

```css
.fmm-image-picker { display: flex; flex-direction: column; gap: 0.75rem; }
.fmm-picker-empty { display: flex; flex-direction: column; gap: 0.75rem; }
.fmm-picker-buttons { display: flex; flex-wrap: wrap; align-items: center; gap: 0.5rem; }
.fmm-picker-uploading { font-size: 0.75rem; color: rgb(107 114 128); }
.fmm-formats-label { font-size: 0.8125rem; font-weight: 500; color: rgb(107 114 128); }
.fmm-formats { display: flex; flex-wrap: wrap; align-items: flex-end; gap: 1rem; }
.fmm-format { display: flex; flex-direction: column; gap: 0.25rem; width: 7rem; }
.fmm-format-box { display: block; width: 100%; max-height: 7rem; border: 2px dashed rgb(209 213 219); border-radius: 0.375rem; background: rgb(249 250 251); }
.dark .fmm-format-box { border-color: rgb(82 82 91); background: rgb(39 39 42); }
.fmm-format-name { font-size: 0.8125rem; font-weight: 500; }
.fmm-format-size { font-size: 0.6875rem; color: rgb(107 114 128); font-variant-numeric: tabular-nums; }
.fmm-unsaved { font-size: 0.75rem; color: rgb(217 119 6); }
.dark .fmm-unsaved { color: rgb(251 191 36); }
.fmm-variants { display: grid; grid-template-columns: repeat(auto-fill, minmax(14rem, 1fr)); gap: 1rem; align-items: start; }
.fmm-variant { display: flex; flex-direction: column; gap: 0.5rem; padding: 0.75rem; border-radius: 0.75rem; border: 1px solid rgb(229 231 235); }
.dark .fmm-variant { border-color: rgb(63 63 70); }
.fmm-variant-header { display: flex; align-items: center; justify-content: space-between; gap: 0.5rem; }
.fmm-variant-name { font-size: 0.8125rem; font-weight: 500; }
.fmm-variant-badge { font-size: 0.6875rem; font-weight: 500; padding: 0.125rem 0.5rem; border-radius: 9999px; background: rgb(219 234 254); color: rgb(29 78 216); }
.dark .fmm-variant-badge { background: rgba(59, 130, 246, 0.2); color: rgb(147 197 253); }
.fmm-variant-thumb { position: relative; width: 100%; overflow: hidden; border-radius: 0.5rem; background: rgb(243 244 246); }
.dark .fmm-variant-thumb { background: rgb(39 39 42); }
.fmm-variant-status { min-height: 1.25rem; font-size: 0.75rem; }
.fmm-variant-pending { color: rgb(107 114 128); }
.fmm-variant-ready { color: rgb(22 163 74); }
.dark .fmm-variant-ready { color: rgb(74 222 128); }
.fmm-variant-actions { display: flex; flex-wrap: wrap; gap: 0.375rem; }
.fmm-reframe { display: flex; flex-direction: column; gap: 0.5rem; }
```

- [ ] **Step 5: Run to verify pass**

Run: `plugin-pest tests/ImagePickerViewTest.php`
Expected: PASS.

- [ ] **Step 6: Whole suite + lint**

```bash
plugin-pest --compact
plugin-lint
```

Expected: all green.

- [ ] **Step 7: Publish assets to the host and check it by hand.** Run `vendor/bin/sail artisan filament:assets`. Then open a page with an image block (URL via Boost `get-absolute-url`) and check:
  - The empty field shows formats with ratios.
  - Upload selects the image straight away.
  - Reframe drag moves the crop rectangle and the thumbnail.
  - "Use different image" puts an Own image badge and alt field on that one card only.
  - The "Not saved yet" hint appears after edits.
  - Leaving without saving and reopening shows the old state.
  - Saving shows Generating… and then Ready.
  - Dark mode looks right.

  Check `browser-logs` for JS errors. **If deep edits (drag, zoom) aren't sent on Save, the entangle isn't picking up nested changes.** In that case replace `touch()` with a function that re-assigns the whole state (`this.state = { ...this.state, dirty: true }`), and note it in the commit.

- [ ] **Step 8: Commit (plugin)**

```bash
cd packages/filament-media-manager && git add resources/views/forms/components/image-picker.blade.php resources/dist/filament-media-manager.css tests/ImagePickerViewTest.php && git commit -m "feat: image picker UI with required formats, variant cards and overrides"
```

The host's published copy under `public/css/marvelous-digital/...` is committed in Task 9.

---

### Task 9: Host app — migration, per-variant alt, block rendering

**Files (host repo root):**
- Create: `database/migrations/<timestamp>_add_override_to_media_conversions_table.php`
- Modify: `app/View/Components/Blocks/ImageBlock.php`, `resources/views/components/blocks/image-block.blade.php`
- Create: `tests/Feature/ImageBlockTest.php`
- Modify: `public/css/marvelous-digital/filament-media-manager/filament-media-manager-styles.css` (published in Task 8, Step 7)

**Interfaces:**
- Consumes: `MediaConversion::effectiveAltText()`, `media_id` (Task 1).
- Produces: `ImageBlock::formatApi()['data']['sources'][variation]` gains `alt: string|null` and `overridden: bool`.

- [ ] **Step 1: Create the migration**

Run: `vendor/bin/sail artisan make:migration add_override_to_media_conversions_table --table=media_conversions --no-interaction`

Body:

```php
    public function up(): void
    {
        Schema::table('media_conversions', function (Blueprint $table) {
            $table->foreignId('media_id')->nullable()->after('zoom')->constrained('media')->nullOnDelete();
            $table->string('alt_text')->nullable()->after('media_id');
        });
    }

    public function down(): void
    {
        Schema::table('media_conversions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('media_id');
            $table->dropColumn('alt_text');
        });
    }
```

Run: `vendor/bin/sail artisan migrate --no-interaction` → Expected: migration runs.

- [ ] **Step 2: Write the failing test**

Run: `vendor/bin/sail artisan make:test --pest ImageBlockTest --no-interaction`, then set `tests/Feature/ImageBlockTest.php` to:

```php
<?php

use App\View\Components\Blocks\ImageBlock;
use MarvelousDigital\FilamentMediaManager\Database\Factories\MediaConversionFactory;
use MarvelousDigital\FilamentMediaManager\Database\Factories\MediaFactory;
use MarvelousDigital\FilamentMediaManager\Database\Factories\MediaFieldValueFactory;
use MarvelousDigital\FilamentMediaManager\Enums\ConversionStatus;

function imageBlockValue(bool $overrideMobile): int
{
    $value = MediaFieldValueFactory::new()->for(MediaFactory::new(), 'source')->create(['alt_text' => 'Main alt']);

    foreach (['full', 'desktop', 'tablet', 'mobile'] as $variation) {
        MediaConversionFactory::new()->for($value, 'value')->create([
            'variation' => $variation,
            'status' => ConversionStatus::Completed,
            'path' => "media/conversions/{$value->id}/{$variation}.webp",
            'media_id' => $overrideMobile && $variation === 'mobile' ? MediaFactory::new()->create()->id : null,
            'alt_text' => $overrideMobile && $variation === 'mobile' ? 'Mobile alt' : null,
        ]);
    }

    return $value->id;
}

function renderImageBlock(int $valueId): string
{
    return view('components.blocks.image-block', [
        'block' => ['type' => 'image-block', 'data' => ['image' => $valueId]],
    ])->render();
}

it('exposes per-variation alt and override flags', function () {
    $sources = (new ImageBlock(['type' => 'image-block', 'data' => ['image' => imageBlockValue(true)]]))
        ->formatApi()['data']['sources'];

    expect($sources['mobile'])->toMatchArray(['alt' => 'Mobile alt', 'overridden' => true])
        ->and($sources['desktop'])->toMatchArray(['alt' => 'Main alt', 'overridden' => false]);
});

it('renders a single picture element when no variation is overridden', function () {
    $html = renderImageBlock(imageBlockValue(false));

    expect($html)->toContain('<picture>')
        ->and(substr_count($html, '<img'))->toBe(1);
});

it('renders one image per breakpoint with its own alt when a variation is overridden', function () {
    $html = renderImageBlock(imageBlockValue(true));

    expect($html)->not->toContain('<picture>')
        ->and(substr_count($html, '<img'))->toBe(3)
        ->and($html)->toContain('alt="Mobile alt"')
        ->and($html)->toContain('alt="Main alt"');
});
```

- [ ] **Step 3: Run to verify failure**

Run: `vendor/bin/sail artisan test tests/Feature/ImageBlockTest.php`
Expected: FAIL — missing `alt` / `overridden` keys; `<picture>` always rendered.

- [ ] **Step 4: Implement**

`app/View/Components/Blocks/ImageBlock.php` — update the `formatSources()` docblock to `array<string, array{src: string|null, width: int, height: int, status: string, alt: string|null, overridden: bool}>` and add to each source entry:

```php
                'alt' => $conversion?->effectiveAltText() ?? $this->image?->alt_text,
                'overridden' => $conversion?->media_id !== null,
```

`resources/views/components/blocks/image-block.blade.php` — replace the `@if (! empty($image['url'])) ... @endif` block:

```blade
@php
    $hasOverride = collect($sources)->contains(fn (array $source): bool => $source['overridden']);
    $breakpoints = [
        'mobile' => 'h-auto w-full md:hidden',
        'tablet' => 'hidden h-auto w-full md:block lg:hidden',
        'desktop' => 'hidden h-auto w-full lg:block',
    ];
@endphp

@if (! empty($image['url']))
    @if ($hasOverride)
        @foreach ($breakpoints as $variation => $classes)
            <img
                src="{{ $sources[$variation]['src'] ?? $image['url'] }}"
                alt="{{ $sources[$variation]['alt'] ?? $image['alt'] }}"
                width="{{ $sources[$variation]['width'] }}"
                height="{{ $sources[$variation]['height'] }}"
                loading="lazy"
                decoding="async"
                class="{{ $classes }}"
            >
        @endforeach
    @else
        {{-- existing <picture> markup, unchanged --}}
    @endif
@endif
```

Put the existing `<picture>…</picture>` markup in the `@else` branch, unchanged. Remove the placeholder comment.

- [ ] **Step 5: Run to verify pass**

Run: `vendor/bin/sail artisan test tests/Feature/ImageBlockTest.php`
Expected: PASS.

- [ ] **Step 6: Lint and commit (host repo)**

```bash
vendor/bin/sail bin pint --dirty
git add database/migrations/*_add_override_to_media_conversions_table.php app/View/Components/Blocks/ImageBlock.php resources/views/components/blocks/image-block.blade.php tests/Feature/ImageBlockTest.php public/css/marvelous-digital/filament-media-manager/filament-media-manager-styles.css
git commit -m "feat: per-variant image alt and override rendering in image block"
```

- [ ] **Step 7: Flag for the human (don't change):** `ImageBlock::variations()` declares `'full' => [3000, 100]` (30:1). Ask whether `[3000, 1000]` was intended.

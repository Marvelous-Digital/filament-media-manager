# ImagePicker Field Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add a single-image `ImagePicker` Filament v5 form field that lets a contributor pick an image from the media library (or upload one and select it), creating a per-usage record with its own alt text, per-variation focus points, and generated cropped conversions.

**Architecture:** Picking creates a `MediaFieldValue` that *references* the shared original `Media` file (never copied) and owns a set of `MediaConversion` rows — one per named variation the field declares, each with its own focus point and generated crop file. Conversions are cropped around the focus point and generated via a queued `GenerateConversion` job that mirrors the existing `GenerateThumbnail` pattern. The parent model stores the `MediaFieldValue` id in a nullable FK column. The browsing UI is shared between the existing `MediaLibrary` page and the picker via a `BrowsesMedia` trait plus a shared Blade partial (no nested-Livewire refactor).

**Tech Stack:** PHP 8.2+, Laravel 13.20+, Filament 5, intervention/image 4 (via Laravel's `Image` facade), Livewire 3, Alpine.js, Pest 3/4.

## Global Constraints

- PHP `^8.2`; Laravel `^13.20`; Filament `^5.0`. (from composer.json)
- Namespace root: `MarvelousDigital\FilamentMediaManager\` → `src/`. Factories: `MarvelousDigital\FilamentMediaManager\Database\Factories\` → `database/factories/`. Tests: `MarvelousDigital\FilamentMediaManager\Tests\` → `tests/`.
- View namespace is `filament-media-manager`. Config key root is `filament-media-manager`.
- Originals are never renamed, re-encoded, or modified. Conversions are separate files; the original is referenced, never copied.
- Conversion generation reuses the existing thumbnail config knobs: `filament-media-manager.thumbnail.queue`, `.queue_name`, `.format`, `.quality`. No new config keys.
- CSS class prefix for plugin markup is `fmm-`.
- Every code step must run `vendor/bin/pint` clean and `vendor/bin/phpstan analyse --memory-limit=1G` clean before commit (the repo enforces both in CI). Run `composer test` for the relevant file.
- Commit after every task with a `feat:`/`refactor:`/`docs:` prefix.

---

## File Structure

**Create:**
- `src/Enums/ConversionStatus.php` — status enum for conversion rows.
- `src/Models/MediaFieldValue.php` — the per-usage record.
- `src/Models/MediaConversion.php` — one named crop of a value.
- `src/Support/CropBox.php` — pure crop-rectangle calculator.
- `src/Actions/PickMedia.php` — create a `MediaFieldValue` (+ seed conversions) from a `Media` + variations.
- `src/Jobs/GenerateConversion.php` — crop + encode + store one conversion.
- `src/Forms/Components/ImagePicker.php` — the Filament field.
- `src/Concerns/BrowsesMedia.php` — shared browsing state/query trait.
- `src/Livewire/MediaPickerBrowser.php` — the picker's browse/select Livewire component.
- `database/factories/MediaFieldValueFactory.php`
- `database/factories/MediaConversionFactory.php`
- `database/migrations/create_media_field_tables.php` — second published migration (plain `.php`, matching the existing migration's convention).
- `resources/views/partials/media-grid.blade.php` — shared folders+media grid.
- `resources/views/media-picker-browser.blade.php` — picker browser view.
- `resources/views/forms/components/image-picker.blade.php` — field view (empty/filled + focus editor).

**Modify:**
- `src/MediaManagerServiceProvider.php` — register the new migration name.
- `src/Pages/MediaLibrary.php` — use the `BrowsesMedia` trait; drop the browsing members it now inherits.
- `resources/views/media-library.blade.php` — `@include` the shared grid partial in `manage` mode.
- `resources/css/index.css` — styles for picker/focus editor (`fmm-` prefixed).
- `README.md`, `CHANGELOG.md` — document the field.

---

## Task 1: `ConversionStatus` enum + migration for the two tables

**Files:**
- Create: `src/Enums/ConversionStatus.php`
- Create: `database/migrations/create_media_field_tables.php.stub`
- Modify: `src/MediaManagerServiceProvider.php:22`
- Test: `tests/ConversionStatusTest.php`

**Interfaces:**
- Produces: `ConversionStatus` enum (`Pending='pending'`, `Processing='processing'`, `Completed='completed'`, `Failed='failed'`). Tables `media_field_values` and `media_conversions`.

- [ ] **Step 1: Write the failing test**

`tests/ConversionStatusTest.php`:
```php
<?php

use MarvelousDigital\FilamentMediaManager\Enums\ConversionStatus;
use MarvelousDigital\FilamentMediaManager\Models\MediaConversion;
use MarvelousDigital\FilamentMediaManager\Models\MediaFieldValue;

it('has the expected string values', function () {
    expect(ConversionStatus::Pending->value)->toBe('pending')
        ->and(ConversionStatus::Processing->value)->toBe('processing')
        ->and(ConversionStatus::Completed->value)->toBe('completed')
        ->and(ConversionStatus::Failed->value)->toBe('failed');
});

it('creates the media field tables', function () {
    expect(class_exists(MediaFieldValue::class))->toBeTrue()
        ->and(class_exists(MediaConversion::class))->toBeTrue();

    // Tables exist and are empty (migrations ran in the test harness).
    expect(MediaFieldValue::query()->count())->toBe(0)
        ->and(MediaConversion::query()->count())->toBe(0);
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/pest tests/ConversionStatusTest.php`
Expected: FAIL — `Class "MarvelousDigital\FilamentMediaManager\Enums\ConversionStatus" not found`.

- [ ] **Step 3: Create the enum**

`src/Enums/ConversionStatus.php`:
```php
<?php

namespace MarvelousDigital\FilamentMediaManager\Enums;

enum ConversionStatus: string
{
    case Pending = 'pending';
    case Processing = 'processing';
    case Completed = 'completed';
    case Failed = 'failed';
}
```

- [ ] **Step 4: Create the migration**

`database/migrations/create_media_field_tables.php`:
```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('media_field_values', function (Blueprint $table) {
            $table->id();
            $table->foreignId('media_id')->constrained('media')->cascadeOnDelete();
            $table->string('alt_text')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();
        });

        Schema::create('media_conversions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('media_field_value_id')->constrained('media_field_values')->cascadeOnDelete();
            $table->string('variation');
            $table->unsignedInteger('width');
            $table->unsignedInteger('height');
            $table->float('focus_x')->default(0.5);
            $table->float('focus_y')->default(0.5);
            $table->string('path')->nullable();
            $table->string('status')->default('pending');
            $table->timestamps();

            $table->unique(['media_field_value_id', 'variation']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('media_conversions');
        Schema::dropIfExists('media_field_values');
    }
};
```

Note: existing migrations in this package ship as `.php.stub`? Verify by listing `database/migrations/`. The existing file is `create_filament_media_manager_tables.php` (no `.stub`). Match the existing convention: **name the file `create_media_field_tables.php`** (plain `.php`, no stub), same as the existing migration.

- [ ] **Step 5: Register the migration in the service provider**

`src/MediaManagerServiceProvider.php` — change line 22:
```php
->hasMigrations(['create_filament_media_manager_tables', 'create_media_field_tables'])
```

- [ ] **Step 6: Run test to verify it passes**

Run: `vendor/bin/pest tests/ConversionStatusTest.php`
Expected: PASS. (Testbench runs package migrations automatically; if the harness needs the migration loaded, confirm `tests/TestCase.php` loads package migrations — it already does for the existing tables.)

- [ ] **Step 7: Lint, analyse, commit**

```bash
vendor/bin/pint && vendor/bin/phpstan analyse --memory-limit=1G
git add src/Enums/ConversionStatus.php database/migrations/create_media_field_tables.php src/MediaManagerServiceProvider.php tests/ConversionStatusTest.php
git commit -m "feat: add media field tables and ConversionStatus enum"
```

---

## Task 2: `MediaConversion` model + factory

**Files:**
- Create: `src/Models/MediaConversion.php`
- Create: `database/factories/MediaConversionFactory.php`
- Test: `tests/MediaConversionModelTest.php`

**Interfaces:**
- Consumes: `ConversionStatus` (Task 1); `MediaFieldValue` (Task 3 — belongsTo, defined here by class-string reference).
- Produces:
  - `MediaConversion` with `$fillable = ['media_field_value_id','variation','width','height','focus_x','focus_y','path','status']`; casts `status`→`ConversionStatus`, `width`/`height`→int, `focus_x`/`focus_y`→float.
  - `value(): BelongsTo` → `MediaFieldValue`.
  - `url(): ?string` — resolves `path` on the disk of the parent value's source media; null when `path` is null.

- [ ] **Step 1: Write the failing test**

`tests/MediaConversionModelTest.php`:
```php
<?php

use Illuminate\Support\Facades\Storage;
use MarvelousDigital\FilamentMediaManager\Enums\ConversionStatus;
use MarvelousDigital\FilamentMediaManager\Models\Media;
use MarvelousDigital\FilamentMediaManager\Models\MediaConversion;
use MarvelousDigital\FilamentMediaManager\Models\MediaFieldValue;

it('casts focus and status', function () {
    $media = Media::factory()->create();
    $value = MediaFieldValue::factory()->for($media, 'source')->create();
    $conversion = MediaConversion::factory()->for($value, 'value')->create([
        'focus_x' => 0.25,
        'status' => ConversionStatus::Pending,
    ]);

    expect($conversion->focus_x)->toBe(0.25)
        ->and($conversion->status)->toBe(ConversionStatus::Pending)
        ->and($conversion->value->is($value))->toBeTrue();
});

it('resolves a url from the source disk when a path is set', function () {
    Storage::fake('public');
    Storage::disk('public')->put('media/01V/hero.webp', 'x');

    $media = Media::factory()->create(['disk' => 'public']);
    $value = MediaFieldValue::factory()->for($media, 'source')->create();
    $conversion = MediaConversion::factory()->for($value, 'value')->create([
        'path' => 'media/01V/hero.webp',
    ]);

    expect($conversion->url())->toContain('media/01V/hero.webp');
});

it('returns null url when no path', function () {
    $media = Media::factory()->create();
    $value = MediaFieldValue::factory()->for($media, 'source')->create();
    $conversion = MediaConversion::factory()->for($value, 'value')->create(['path' => null]);

    expect($conversion->url())->toBeNull();
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/pest tests/MediaConversionModelTest.php`
Expected: FAIL — `MediaConversion` / `MediaFieldValue` not found.

- [ ] **Step 3: Create the model**

`src/Models/MediaConversion.php`:
```php
<?php

namespace MarvelousDigital\FilamentMediaManager\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use MarvelousDigital\FilamentMediaManager\Enums\ConversionStatus;

/**
 * @property int $id
 * @property int $media_field_value_id
 * @property string $variation
 * @property int $width
 * @property int $height
 * @property float $focus_x
 * @property float $focus_y
 * @property string|null $path
 * @property ConversionStatus $status
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read MediaFieldValue $value
 */
class MediaConversion extends Model
{
    use HasFactory;

    protected $fillable = [
        'media_field_value_id', 'variation', 'width', 'height',
        'focus_x', 'focus_y', 'path', 'status',
    ];

    protected function casts(): array
    {
        return [
            'status' => ConversionStatus::class,
            'width' => 'integer',
            'height' => 'integer',
            'focus_x' => 'float',
            'focus_y' => 'float',
        ];
    }

    public function value(): BelongsTo
    {
        return $this->belongsTo(MediaFieldValue::class, 'media_field_value_id');
    }

    public function url(): ?string
    {
        if ($this->path === null) {
            return null;
        }

        return $this->value?->source?->resolvePublicUrl($this->path);
    }
}
```

Note: `resolvePublicUrl()` is a small public wrapper added to `Media` in Task 3 (Step 3a) exposing the existing private `resolveUrl` logic for reuse by conversions.

- [ ] **Step 4: Create the factory**

`database/factories/MediaConversionFactory.php`:
```php
<?php

namespace MarvelousDigital\FilamentMediaManager\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use MarvelousDigital\FilamentMediaManager\Enums\ConversionStatus;
use MarvelousDigital\FilamentMediaManager\Models\MediaConversion;

class MediaConversionFactory extends Factory
{
    protected $model = MediaConversion::class;

    public function definition(): array
    {
        return [
            'variation' => 'hero',
            'width' => 1600,
            'height' => 600,
            'focus_x' => 0.5,
            'focus_y' => 0.5,
            'path' => null,
            'status' => ConversionStatus::Pending,
        ];
    }
}
```

- [ ] **Step 5: Run test — passes after Task 3's `MediaFieldValue` exists**

The test references `MediaFieldValue::factory()`. Implement Task 3's model + factory + the `Media::resolvePublicUrl` wrapper, then run:
Run: `vendor/bin/pest tests/MediaConversionModelTest.php`
Expected: PASS.

(If executing strictly task-by-task, create a minimal `MediaFieldValue` stub now and flesh it out in Task 3. Recommended: do Task 2 and Task 3 as one commit since the models are mutually referential.)

- [ ] **Step 6: Lint, analyse, commit** (fold into Task 3's commit — see note above)

---

## Task 3: `MediaFieldValue` model + factory + `Media::resolvePublicUrl`

**Files:**
- Create: `src/Models/MediaFieldValue.php`
- Create: `database/factories/MediaFieldValueFactory.php`
- Modify: `src/Models/Media.php` (add public `resolvePublicUrl`)
- Test: `tests/MediaFieldValueModelTest.php`

**Interfaces:**
- Consumes: `Media`, `MediaConversion`.
- Produces:
  - `MediaFieldValue` `$fillable = ['media_id','alt_text','created_by']`.
  - `source(): BelongsTo` → `Media` (`media_id`).
  - `conversions(): HasMany` → `MediaConversion`.
  - `conversion(string $variation): ?MediaConversion` (from the loaded/queried collection).
  - `url(): ?string` — the original via `source?->url()`.
  - `directory(): string` — `dirname(source->path) . '/conversions'` (per-usage, still under the source's ULID dir but namespaced so multiple usages don't collide → actually per-usage id: `dirname(source->path)."/conversions/{$this->id}"`).
  - `booted()` deleted hook → best-effort delete of the conversion directory only.
  - `Media::resolvePublicUrl(string $path): ?string` — public wrapper over the existing resolve logic.

- [ ] **Step 1: Write the failing test**

`tests/MediaFieldValueModelTest.php`:
```php
<?php

use Illuminate\Support\Facades\Storage;
use MarvelousDigital\FilamentMediaManager\Models\Media;
use MarvelousDigital\FilamentMediaManager\Models\MediaConversion;
use MarvelousDigital\FilamentMediaManager\Models\MediaFieldValue;

it('relates to source media and conversions', function () {
    $media = Media::factory()->create();
    $value = MediaFieldValue::factory()->for($media, 'source')->create();
    MediaConversion::factory()->for($value, 'value')->create(['variation' => 'hero']);
    MediaConversion::factory()->for($value, 'value')->create(['variation' => 'thumb']);

    expect($value->source->is($media))->toBeTrue()
        ->and($value->conversions)->toHaveCount(2)
        ->and($value->conversion('hero')?->variation)->toBe('hero')
        ->and($value->conversion('missing'))->toBeNull();
});

it('exposes the original url via source', function () {
    Storage::fake('public');
    Storage::disk('public')->put('media/01A/photo.jpg', 'x');
    $media = Media::factory()->create(['disk' => 'public', 'path' => 'media/01A/photo.jpg']);
    $value = MediaFieldValue::factory()->for($media, 'source')->create();

    expect($value->url())->toContain('media/01A/photo.jpg');
});

it('cleans up conversion files on delete but leaves the original', function () {
    Storage::fake('public');
    $media = Media::factory()->create(['disk' => 'public', 'path' => 'media/01A/photo.jpg']);
    Storage::disk('public')->put('media/01A/photo.jpg', 'original');
    $value = MediaFieldValue::factory()->for($media, 'source')->create();
    Storage::disk('public')->put($value->directory() . '/hero.webp', 'crop');

    $value->delete();

    Storage::disk('public')->assertMissing($value->directory() . '/hero.webp');
    Storage::disk('public')->assertExists('media/01A/photo.jpg');
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/pest tests/MediaFieldValueModelTest.php`
Expected: FAIL — model not found.

- [ ] **Step 3a: Add the public URL wrapper to `Media`**

In `src/Models/Media.php`, add a public method delegating to the existing private `resolveUrl`:
```php
    public function resolvePublicUrl(string $path): ?string
    {
        return $this->resolveUrl($path);
    }
```

- [ ] **Step 3b: Create the `MediaFieldValue` model**

`src/Models/MediaFieldValue.php`:
```php
<?php

namespace MarvelousDigital\FilamentMediaManager\Models;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * @property int $id
 * @property int $media_id
 * @property string|null $alt_text
 * @property int|null $created_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Media $source
 * @property-read Collection<int, MediaConversion> $conversions
 */
class MediaFieldValue extends Model
{
    use HasFactory;

    protected $fillable = ['media_id', 'alt_text', 'created_by'];

    protected static function booted(): void
    {
        static::deleted(function (self $value): void {
            try {
                Storage::disk($value->source->disk)->deleteDirectory($value->directory());
            } catch (Throwable) {
                // Best-effort cleanup; never block row deletion.
            }
        });
    }

    public function source(): BelongsTo
    {
        return $this->belongsTo(Media::class, 'media_id');
    }

    public function conversions(): HasMany
    {
        return $this->hasMany(MediaConversion::class);
    }

    public function conversion(string $variation): ?MediaConversion
    {
        return $this->conversions->firstWhere('variation', $variation);
    }

    public function url(): ?string
    {
        return $this->source?->url();
    }

    public function directory(): string
    {
        return dirname($this->source->path) . '/conversions/' . $this->id;
    }
}
```

- [ ] **Step 3c: Create the factory**

`database/factories/MediaFieldValueFactory.php`:
```php
<?php

namespace MarvelousDigital\FilamentMediaManager\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use MarvelousDigital\FilamentMediaManager\Models\Media;
use MarvelousDigital\FilamentMediaManager\Models\MediaFieldValue;

class MediaFieldValueFactory extends Factory
{
    protected $model = MediaFieldValue::class;

    public function definition(): array
    {
        return [
            'media_id' => Media::factory(),
            'alt_text' => null,
        ];
    }
}
```

- [ ] **Step 4: Run both model tests**

Run: `vendor/bin/pest tests/MediaFieldValueModelTest.php tests/MediaConversionModelTest.php`
Expected: PASS.

- [ ] **Step 5: Lint, analyse, commit**

```bash
vendor/bin/pint && vendor/bin/phpstan analyse --memory-limit=1G
git add src/Models/ database/factories/MediaConversionFactory.php database/factories/MediaFieldValueFactory.php tests/MediaConversionModelTest.php tests/MediaFieldValueModelTest.php
git commit -m "feat: add MediaFieldValue and MediaConversion models"
```

---

## Task 4: `CropBox` — pure crop-rectangle calculator

**Files:**
- Create: `src/Support/CropBox.php`
- Test: `tests/CropBoxTest.php`

**Interfaces:**
- Produces: `CropBox::for(int $sourceW, int $sourceH, int $targetW, int $targetH, float $focusX, float $focusY): array` returning `['x'=>int,'y'=>int,'width'=>int,'height'=>int]` — the crop rectangle (top-left origin) in source pixels, of the target aspect ratio, centered on the focus point and clamped to source bounds.

- [ ] **Step 1: Write the failing test**

`tests/CropBoxTest.php`:
```php
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
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/pest tests/CropBoxTest.php`
Expected: FAIL — `CropBox` not found.

- [ ] **Step 3: Implement**

`src/Support/CropBox.php`:
```php
<?php

namespace MarvelousDigital\FilamentMediaManager\Support;

class CropBox
{
    /**
     * @return array{x: int, y: int, width: int, height: int}
     */
    public static function for(
        int $sourceW,
        int $sourceH,
        int $targetW,
        int $targetH,
        float $focusX,
        float $focusY,
    ): array {
        $targetAspect = $targetW / $targetH;
        $sourceAspect = $sourceW / $sourceH;

        if ($sourceAspect > $targetAspect) {
            // Source is wider than target: height-limited.
            $cropH = $sourceH;
            $cropW = (int) round($sourceH * $targetAspect);
        } else {
            // Source is taller/narrower: width-limited.
            $cropW = $sourceW;
            $cropH = (int) round($sourceW / $targetAspect);
        }

        $x = self::clamp((int) round($focusX * $sourceW - $cropW / 2), 0, $sourceW - $cropW);
        $y = self::clamp((int) round($focusY * $sourceH - $cropH / 2), 0, $sourceH - $cropH);

        return ['x' => $x, 'y' => $y, 'width' => $cropW, 'height' => $cropH];
    }

    private static function clamp(int $value, int $min, int $max): int
    {
        return max($min, min($max, $value));
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `vendor/bin/pest tests/CropBoxTest.php`
Expected: PASS.

- [ ] **Step 5: Lint, analyse, commit**

```bash
vendor/bin/pint && vendor/bin/phpstan analyse --memory-limit=1G
git add src/Support/CropBox.php tests/CropBoxTest.php
git commit -m "feat: add CropBox focus-aware crop calculator"
```

---

## Task 5: `GenerateConversion` job

**Files:**
- Create: `src/Jobs/GenerateConversion.php`
- Test: `tests/GenerateConversionTest.php`

**Interfaces:**
- Consumes: `MediaConversion`, `ConversionStatus`, `CropBox`, `Image` facade.
- Produces:
  - `GenerateConversion::__construct(MediaConversion $conversion)`.
  - `GenerateConversion::dispatchFor(MediaConversion $conversion): void` — queued or sync per `thumbnail.queue`/`thumbnail.queue_name`.
  - `handle()` — orient source, crop via `CropBox`, resize to target, encode to `thumbnail.format` at `thumbnail.quality`, store as `{variation}.{format}` in `value->directory()`, set `path` + `status = Completed`; `Failed` on error.

- [ ] **Step 1: Write the failing test**

`tests/GenerateConversionTest.php`:
```php
<?php

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use MarvelousDigital\FilamentMediaManager\Enums\ConversionStatus;
use MarvelousDigital\FilamentMediaManager\Jobs\GenerateConversion;
use MarvelousDigital\FilamentMediaManager\Models\Media;
use MarvelousDigital\FilamentMediaManager\Models\MediaConversion;
use MarvelousDigital\FilamentMediaManager\Models\MediaFieldValue;

function makeConversion(int $w = 1200, int $h = 800, int $tw = 400, int $th = 400): MediaConversion
{
    Storage::fake('public');
    $file = UploadedFile::fake()->image('photo.jpg', $w, $h);
    $path = $file->storeAs('media/01CONV', 'photo.jpg', ['disk' => 'public']);

    $media = Media::factory()->create(['disk' => 'public', 'path' => $path, 'mime_type' => 'image/jpeg']);
    $value = MediaFieldValue::factory()->for($media, 'source')->create();

    return MediaConversion::factory()->for($value, 'value')->create([
        'variation' => 'thumb', 'width' => $tw, 'height' => $th,
        'focus_x' => 0.5, 'focus_y' => 0.5, 'status' => ConversionStatus::Pending,
    ]);
}

it('generates a cropped conversion at the target dimensions', function () {
    $conversion = makeConversion(1200, 800, 400, 400);

    (new GenerateConversion($conversion))->handle();

    $conversion->refresh();

    expect($conversion->status)->toBe(ConversionStatus::Completed)
        ->and($conversion->path)->toBe($conversion->value->directory() . '/thumb.webp');

    Storage::disk('public')->assertExists($conversion->path);

    [$w, $h] = getimagesize(Storage::disk('public')->path($conversion->path));
    expect($w)->toBe(400)->and($h)->toBe(400);
});

it('marks the conversion failed when the source is not a readable image', function () {
    Storage::fake('public');
    Storage::disk('public')->put('media/01BAD/broken.jpg', 'not-an-image');
    $media = Media::factory()->create(['disk' => 'public', 'path' => 'media/01BAD/broken.jpg']);
    $value = MediaFieldValue::factory()->for($media, 'source')->create();
    $conversion = MediaConversion::factory()->for($value, 'value')->create(['status' => ConversionStatus::Pending]);

    (new GenerateConversion($conversion))->handle();

    expect($conversion->refresh()->status)->toBe(ConversionStatus::Failed);
});

it('dispatches queued when configured', function () {
    Queue::fake();
    config()->set('filament-media-manager.thumbnail.queue', true);
    $conversion = makeConversion();

    GenerateConversion::dispatchFor($conversion);

    Queue::assertPushed(GenerateConversion::class);
});

it('runs synchronously when queueing is disabled', function () {
    config()->set('filament-media-manager.thumbnail.queue', false);
    $conversion = makeConversion();

    GenerateConversion::dispatchFor($conversion);

    expect($conversion->refresh()->status)->toBe(ConversionStatus::Completed);
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/pest tests/GenerateConversionTest.php`
Expected: FAIL — `GenerateConversion` not found.

- [ ] **Step 3: Implement the job**

`src/Jobs/GenerateConversion.php`:
```php
<?php

namespace MarvelousDigital\FilamentMediaManager\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Image;
use MarvelousDigital\FilamentMediaManager\Enums\ConversionStatus;
use MarvelousDigital\FilamentMediaManager\Models\MediaConversion;
use MarvelousDigital\FilamentMediaManager\Support\CropBox;
use Throwable;

class GenerateConversion implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function __construct(public MediaConversion $conversion) {}

    public static function dispatchFor(MediaConversion $conversion): void
    {
        if (config('filament-media-manager.thumbnail.queue')) {
            static::dispatch($conversion)->onQueue(config('filament-media-manager.thumbnail.queue_name'));
        } else {
            static::dispatchSync($conversion);
        }
    }

    public function handle(): void
    {
        $source = $this->conversion->value->source;

        try {
            $image = Image::fromStorage($source->path, $source->disk)->orient();
            [$sw, $sh] = $image->dimensions();

            $box = CropBox::for(
                $sw, $sh,
                $this->conversion->width, $this->conversion->height,
                $this->conversion->focus_x, $this->conversion->focus_y,
            );

            $format = config('filament-media-manager.thumbnail.format', 'webp');

            $path = $image
                ->crop($box['width'], $box['height'], $box['x'], $box['y'], position: 'top-left')
                ->resize($this->conversion->width, $this->conversion->height)
                ->optimize(format: $format, quality: config('filament-media-manager.thumbnail.quality', 70))
                ->storeAs(
                    path: $this->conversion->value->directory(),
                    name: $this->conversion->variation . '.' . $format,
                    disk: $source->disk,
                );

            if ($path === false) {
                throw new \RuntimeException("Could not store conversion [{$this->conversion->id}].");
            }

            $this->conversion->update(['path' => $path, 'status' => ConversionStatus::Completed]);
        } catch (Throwable $exception) {
            $this->conversion->update(['status' => ConversionStatus::Failed]);
            report($exception);
        }
    }
}
```

Note on Intervention v4 API: verify `crop(width, height, offset_x, offset_y, position: 'top-left')` and `resize(width, height)` signatures against the installed `intervention/image` version (`grep -r "public function crop" vendor/intervention/image/src`). If `resize` distorts undesirably here it is intentional — the crop already matches the target aspect, so `resize` only scales. If the installed API differs, adapt to the equivalent crop-at-offset + scale-to-exact calls; keep the CropBox math unchanged.

- [ ] **Step 4: Run test to verify it passes**

Run: `vendor/bin/pest tests/GenerateConversionTest.php`
Expected: PASS.

- [ ] **Step 5: Lint, analyse, commit**

```bash
vendor/bin/pint && vendor/bin/phpstan analyse --memory-limit=1G
git add src/Jobs/GenerateConversion.php tests/GenerateConversionTest.php
git commit -m "feat: add GenerateConversion job"
```

---

## Task 6: `PickMedia` action — create a usage record + seed conversions

**Files:**
- Create: `src/Actions/PickMedia.php`
- Test: `tests/PickMediaTest.php`

**Interfaces:**
- Consumes: `Media`, `MediaFieldValue`, `MediaConversion`, `ConversionStatus`, `GenerateConversion`.
- Produces:
  - `PickMedia::execute(Media $media, array $variations): MediaFieldValue` where `$variations` is `array<string, array{0:int,1:int}>` (name → [w,h]). Creates the value (copies `alt_text` from source, sets `created_by` = `Auth::id()`), seeds one `MediaConversion` per variation at focus 0.5/0.5 status Pending, dispatches `GenerateConversion::dispatchFor` for each.

- [ ] **Step 1: Write the failing test**

`tests/PickMediaTest.php`:
```php
<?php

use Illuminate\Support\Facades\Queue;
use MarvelousDigital\FilamentMediaManager\Actions\PickMedia;
use MarvelousDigital\FilamentMediaManager\Enums\ConversionStatus;
use MarvelousDigital\FilamentMediaManager\Jobs\GenerateConversion;
use MarvelousDigital\FilamentMediaManager\Models\Media;

it('creates a usage record copying alt text and seeding conversions', function () {
    Queue::fake();
    $media = Media::factory()->create(['alt_text' => 'A cat']);

    $value = app(PickMedia::class)->execute($media, [
        'hero' => [1600, 600],
        'thumb' => [400, 400],
    ]);

    expect($value->media_id)->toBe($media->id)
        ->and($value->alt_text)->toBe('A cat')
        ->and($value->conversions)->toHaveCount(2)
        ->and($value->conversion('hero')->width)->toBe(1600)
        ->and($value->conversion('hero')->height)->toBe(600)
        ->and($value->conversion('hero')->focus_x)->toBe(0.5)
        ->and($value->conversion('hero')->status)->toBe(ConversionStatus::Pending);

    Queue::assertPushed(GenerateConversion::class, 2);
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/pest tests/PickMediaTest.php`
Expected: FAIL — `PickMedia` not found.

- [ ] **Step 3: Implement**

`src/Actions/PickMedia.php`:
```php
<?php

namespace MarvelousDigital\FilamentMediaManager\Actions;

use Illuminate\Support\Facades\Auth;
use MarvelousDigital\FilamentMediaManager\Enums\ConversionStatus;
use MarvelousDigital\FilamentMediaManager\Jobs\GenerateConversion;
use MarvelousDigital\FilamentMediaManager\Models\Media;
use MarvelousDigital\FilamentMediaManager\Models\MediaFieldValue;

class PickMedia
{
    /**
     * @param  array<string, array{0: int, 1: int}>  $variations
     */
    public function execute(Media $media, array $variations): MediaFieldValue
    {
        $value = MediaFieldValue::create([
            'media_id' => $media->id,
            'alt_text' => $media->alt_text,
            'created_by' => Auth::id(),
        ]);

        foreach ($variations as $name => [$width, $height]) {
            $conversion = $value->conversions()->create([
                'variation' => $name,
                'width' => $width,
                'height' => $height,
                'focus_x' => 0.5,
                'focus_y' => 0.5,
                'status' => ConversionStatus::Pending,
            ]);

            GenerateConversion::dispatchFor($conversion);
        }

        return $value->load('conversions');
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `vendor/bin/pest tests/PickMediaTest.php`
Expected: PASS.

- [ ] **Step 5: Lint, analyse, commit**

```bash
vendor/bin/pint && vendor/bin/phpstan analyse --memory-limit=1G
git add src/Actions/PickMedia.php tests/PickMediaTest.php
git commit -m "feat: add PickMedia action"
```

---

## Task 7: `ImagePicker` field — config + state + dehydration

**Files:**
- Create: `src/Forms/Components/ImagePicker.php`
- Create: `resources/views/forms/components/image-picker.blade.php` (minimal placeholder for now; full UI in Task 10)
- Test: `tests/ImagePickerFieldTest.php`

**Interfaces:**
- Consumes: `Field` base, `PickMedia`, `MediaFieldValue`.
- Produces:
  - `ImagePicker::make(string $name): static`.
  - `->variations(array $variations): static` — `array<string, array{0:int,1:int}>`.
  - `->disk(string $disk): static`.
  - `getVariations(): array` — returns configured variations, or a single implicit `['default' => [$w,$h]]` default `['default' => [1200, 1200]]` when none set.
  - `getDisk(): string` — configured disk or `config('filament-media-manager.disk')`.
  - State = `media_field_values.id`; `dehydrated(true)` writes the id (or null) to the field's model attribute. (No transformation needed — the picked id is set into state directly by the UI in Task 10; dehydration passes it through.)

- [ ] **Step 1: Write the failing test**

`tests/ImagePickerFieldTest.php`:
```php
<?php

use MarvelousDigital\FilamentMediaManager\Forms\Components\ImagePicker;

it('defaults the disk to the configured media disk', function () {
    config()->set('filament-media-manager.disk', 'public');

    $field = ImagePicker::make('hero_image_id');

    expect($field->getDisk())->toBe('public');
});

it('exposes configured variations', function () {
    $field = ImagePicker::make('hero_image_id')->variations([
        'hero' => [1600, 600],
    ]);

    expect($field->getVariations())->toBe(['hero' => [1600, 600]]);
});

it('falls back to a single default variation', function () {
    $field = ImagePicker::make('hero_image_id');

    expect($field->getVariations())->toBe(['default' => [1200, 1200]]);
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/pest tests/ImagePickerFieldTest.php`
Expected: FAIL — `ImagePicker` not found.

- [ ] **Step 3: Implement the field**

Confirm the Filament v5 base namespace first: `grep -r "class TextInput" vendor/filament/forms/src/Components/` → base is `Filament\Forms\Components\Field`.

`src/Forms/Components/ImagePicker.php`:
```php
<?php

namespace MarvelousDigital\FilamentMediaManager\Forms\Components;

use Filament\Forms\Components\Field;

class ImagePicker extends Field
{
    protected string $view = 'filament-media-manager::forms.components.image-picker';

    /** @var array<string, array{0: int, 1: int}> */
    protected array $variations = [];

    protected ?string $pickerDisk = null;

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
}
```

- [ ] **Step 4: Create a minimal field view (replaced in Task 10)**

`resources/views/forms/components/image-picker.blade.php`:
```blade
<x-dynamic-component :component="$getFieldWrapperView()" :field="$field">
    <div class="fmm-image-picker" x-data>
        {{-- Full picker UI implemented in Task 10. --}}
        <input type="hidden" {{ $applyStateBindingModifiers('wire:model') }}="{{ $getStatePath() }}" />
    </div>
</x-dynamic-component>
```

- [ ] **Step 5: Run test to verify it passes**

Run: `vendor/bin/pest tests/ImagePickerFieldTest.php`
Expected: PASS.

- [ ] **Step 6: Lint, analyse, commit**

```bash
vendor/bin/pint && vendor/bin/phpstan analyse --memory-limit=1G
git add src/Forms/Components/ImagePicker.php resources/views/forms/components/image-picker.blade.php tests/ImagePickerFieldTest.php
git commit -m "feat: add ImagePicker field scaffold with variations and disk config"
```

---

## Task 8: Extract `BrowsesMedia` trait + shared grid partial; refactor `MediaLibrary`

**Goal:** Move the browsing state/query out of `MediaLibrary` into a reusable trait, and the folders+media grid markup into a shared partial with a `$mode` flag, WITHOUT changing observable behavior (all existing `MediaLibraryPageTest`, `MediaLibraryUploadTest`, `BulkActionsTest`, `FolderActionsTest`, `MediaActionsTest` must stay green).

**Files:**
- Create: `src/Concerns/BrowsesMedia.php`
- Create: `resources/views/partials/media-grid.blade.php`
- Modify: `src/Pages/MediaLibrary.php`
- Modify: `resources/views/media-library.blade.php`
- Test: existing page/upload/action tests (regression); no new test file required, but add `tests/BrowsesMediaTest.php` for the trait's `imagesOnly` filter.

**Interfaces:**
- Produces (trait `BrowsesMedia`):
  - Properties: `#[Url] ?int $folderId = null`, `#[Url] string $search = ''`, `int $loadedPages = 1`, `array $uploads = []`.
  - Methods moved verbatim from `MediaLibrary`: `currentFolder()`, `openFolder()`, `loadMore()`, `updatedSearch()`, `updatedUploads()`, `mediaQuery()`.
  - New hook: `protected function imagesOnly(): bool { return false; }` — when true, `mediaQuery()` adds `->where('type', MediaType::Image->value)`.
  - New helper: `protected function browseData(): array` returning `['folders','mediaItems','hasMore','currentFolder','breadcrumbFolders']` (the assembly currently in `MediaLibrary::getViewData()`).
- The `MediaLibrary` page `use`s the trait and keeps only: navigation label/icon/title, the action methods, `$selected`, `bulkDeleteAction`, `bulkMoveAction`, and a thin `getViewData()` that returns `browseData()`.

- [ ] **Step 1: Add trait regression test**

`tests/BrowsesMediaTest.php`:
```php
<?php

use MarvelousDigital\FilamentMediaManager\Enums\MediaType;
use MarvelousDigital\FilamentMediaManager\Models\Media;
use MarvelousDigital\FilamentMediaManager\Pages\MediaLibrary;

it('media library still lists all media types (imagesOnly is off)', function () {
    Media::factory()->create(['name' => 'pic', 'type' => MediaType::Image]);
    Media::factory()->create(['name' => 'clip', 'type' => MediaType::Video, 'mime_type' => 'video/mp4']);

    Livewire::test(MediaLibrary::class)
        ->assertSee('pic')
        ->assertSee('clip');
});
```
(`Livewire::test` is available via `pestphp/pest-plugin-livewire`, already a dev dep. If a panel context is required, follow the setup in `tests/MediaLibraryPageTest.php`.)

- [ ] **Step 2: Run existing suite to capture the green baseline**

Run: `vendor/bin/pest tests/MediaLibraryPageTest.php tests/MediaLibraryUploadTest.php tests/BulkActionsTest.php tests/FolderActionsTest.php tests/MediaActionsTest.php`
Expected: PASS (baseline before refactor).

- [ ] **Step 3: Create the trait**

`src/Concerns/BrowsesMedia.php` — move the members from `MediaLibrary` (see current `src/Pages/MediaLibrary.php:31-43`, `246-313`, and the `getViewData` assembly at `315-333`). Full trait:
```php
<?php

namespace MarvelousDigital\FilamentMediaManager\Concerns;

use Filament\Notifications\Notification;
use Illuminate\Contracts\Database\Eloquent\Builder;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Url;
use MarvelousDigital\FilamentMediaManager\Actions\UploadMedia;
use MarvelousDigital\FilamentMediaManager\Enums\MediaType;
use MarvelousDigital\FilamentMediaManager\Models\Media;
use MarvelousDigital\FilamentMediaManager\Models\MediaFolder;

trait BrowsesMedia
{
    #[Url]
    public ?int $folderId = null;

    #[Url]
    public string $search = '';

    public int $loadedPages = 1;

    /** @var array<UploadedFile> */
    public array $uploads = [];

    protected function imagesOnly(): bool
    {
        return false;
    }

    public function currentFolder(): ?MediaFolder
    {
        return $this->folderId ? MediaFolder::find($this->folderId) : null;
    }

    public function openFolder(?int $folderId): void
    {
        $this->folderId = $folderId;
        $this->search = '';
        $this->loadedPages = 1;
        $this->afterOpenFolder();
    }

    protected function afterOpenFolder(): void
    {
        // Overridable hook (e.g. MediaLibrary resets $selected).
    }

    public function loadMore(): void
    {
        $this->loadedPages++;
    }

    public function updatedSearch(): void
    {
        $this->loadedPages = 1;
    }

    public function updatedUploads(): void
    {
        $folder = $this->currentFolder();
        $stored = 0;

        foreach ($this->uploads as $file) {
            try {
                app(UploadMedia::class)->execute($file, $folder);
                $stored++;
            } catch (ValidationException $exception) {
                Notification::make()
                    ->title('Upload failed')
                    ->body(implode(' ', collect($exception->errors())->flatten()->all()))
                    ->danger()
                    ->send();
            }
        }

        $this->uploads = [];

        if ($stored > 0) {
            Notification::make()
                ->title($stored === 1 ? '1 file uploaded' : "{$stored} files uploaded")
                ->success()
                ->send();
        }
    }

    protected function mediaQuery(): Builder
    {
        $query = Media::query();

        $this->folderId === null
            ? $query->whereNull('folder_id')
            : $query->where('folder_id', $this->folderId);

        if ($this->imagesOnly()) {
            $query->where('type', MediaType::Image->value);
        }

        return $query
            ->when($this->search !== '', function (Builder $query) {
                $query->where(function (Builder $query) {
                    $query->where('name', 'like', "%{$this->search}%")
                        ->orWhere('alt_text', 'like', "%{$this->search}%");
                });
            })
            ->latest();
    }

    /**
     * @return array<string, mixed>
     */
    protected function browseData(): array
    {
        $limit = $this->loadedPages * config('filament-media-manager.per_page', 30);
        $mediaItems = $this->mediaQuery()->limit($limit + 1)->get();
        $hasMore = $mediaItems->count() > $limit;

        $current = $this->currentFolder();

        return [
            'folders' => MediaFolder::query()
                ->where('parent_id', $this->folderId)
                ->orderBy('name')
                ->get(),
            'mediaItems' => $mediaItems->take($limit),
            'hasMore' => $hasMore,
            'currentFolder' => $current,
            'breadcrumbFolders' => $current ? [...$current->ancestors(), $current] : [],
        ];
    }
}
```

- [ ] **Step 4: Slim down `MediaLibrary` to use the trait**

In `src/Pages/MediaLibrary.php`:
- Add `use MarvelousDigital\FilamentMediaManager\Concerns\BrowsesMedia;` and `use BrowsesMedia;` inside the class.
- Delete the now-inherited members: the `#[Url] $folderId`, `#[Url] $search`, `$loadedPages`, `$uploads` properties and the `currentFolder()`, `openFolder()`, `loadMore()`, `updatedSearch()`, `updatedUploads()`, `mediaQuery()` methods.
- Keep `WithFileUploads`, `$selected`, all action methods, and the bulk actions.
- Add the `$selected` reset via the hook:
```php
    protected function afterOpenFolder(): void
    {
        $this->selected = [];
    }
```
- Replace `getViewData()` body with:
```php
    protected function getViewData(): array
    {
        return $this->browseData();
    }
```

- [ ] **Step 5: Extract the shared grid partial**

Create `resources/views/partials/media-grid.blade.php` containing the folders+media grid currently at `media-library.blade.php:71-130` (the `.fmm-grid`, empty state, and load-more blocks). Parameterize action affordances by a `$mode` variable (default `'manage'`):
```blade
@php($mode = $mode ?? 'manage')
<div class="fmm-grid">
    @foreach ($folders as $folder)
        <div class="fmm-card fmm-folder" wire:key="folder-{{ $folder->id }}">
            <button type="button" class="fmm-card-body" wire:click="openFolder({{ $folder->id }})">
                <x-filament::icon icon="heroicon-o-folder" class="fmm-folder-icon" />
                <span class="fmm-card-name">{{ $folder->name }}</span>
            </button>
            @if ($mode === 'manage')
                <div class="fmm-card-menu">
                    <x-filament::icon-button icon="heroicon-o-pencil" color="gray" size="sm" label="Rename"
                        wire:click="mountAction('renameFolder', { folder: {{ $folder->id }} })" />
                    <x-filament::icon-button icon="heroicon-o-trash" color="danger" size="sm" label="Delete"
                        wire:click="mountAction('deleteFolder', { folder: {{ $folder->id }} })" />
                </div>
            @endif
        </div>
    @endforeach

    @foreach ($mediaItems as $media)
        <div class="fmm-card fmm-media" wire:key="media-{{ $media->id }}">
            <div class="fmm-card-body">
                <div class="fmm-thumb">
                    @if ($media->thumbnailUrl())
                        <img src="{{ $media->thumbnailUrl() }}" alt="{{ $media->alt_text ?? $media->name }}" loading="lazy" />
                    @else
                        <x-filament::icon :icon="match ($media->type->value) {
                            'video' => 'heroicon-o-film',
                            'document' => 'heroicon-o-document-text',
                            default => 'heroicon-o-document',
                        }" class="fmm-type-icon" />
                    @endif
                </div>
                <span class="fmm-card-name" title="{{ $media->file_name }}">{{ $media->name }}</span>
            </div>

            @if ($mode === 'select')
                <button type="button" class="fmm-card-overlay" wire:click="select({{ $media->id }})"
                    aria-label="Select {{ $media->name }}"></button>
            @else
                <button type="button" class="fmm-card-overlay" wire:click="mountAction('editMedia', { media: {{ $media->id }} })"
                    aria-label="Open details for {{ $media->name }}"></button>
                <label class="fmm-card-check" x-on:click.stop>
                    <input type="checkbox" value="{{ $media->id }}" wire:model.live="selected" class="fi-checkbox-input" />
                </label>
                <div class="fmm-card-menu">
                    <x-filament::icon-button icon="heroicon-o-arrow-down-tray" color="gray" size="sm" label="Download"
                        wire:click="mountAction('downloadMedia', { media: {{ $media->id }} })" />
                    @if ($media->thumbnail_status === \MarvelousDigital\FilamentMediaManager\Enums\ThumbnailStatus::Failed)
                        <x-filament::icon-button icon="heroicon-o-arrow-path" color="warning" size="sm" label="Retry thumbnail"
                            wire:click="mountAction('retryThumbnail', { media: {{ $media->id }} })" />
                    @endif
                    <x-filament::icon-button icon="heroicon-o-trash" color="danger" size="sm" label="Delete"
                        wire:click="mountAction('deleteMedia', { media: {{ $media->id }} })" />
                </div>
            @endif
        </div>
    @endforeach
</div>

@if ($folders->isEmpty() && $mediaItems->isEmpty())
    <div class="fmm-empty">
        <x-filament::icon icon="heroicon-o-photo" class="fmm-empty-icon" />
        <p>No media here yet. Upload files or create a folder to get started.</p>
    </div>
@endif

@if ($hasMore)
    <div class="fmm-load-more">
        <x-filament::button color="gray" wire:click="loadMore">Load more</x-filament::button>
    </div>
@endif
```

- [ ] **Step 6: Update the page view to include the partial**

In `resources/views/media-library.blade.php`, replace the grid/empty/load-more region (lines 71-136) with:
```blade
        @include('filament-media-manager::partials.media-grid', ['mode' => 'manage'])
```
Leave the toolbar, breadcrumbs, upload, and bulk bar exactly as they are.

- [ ] **Step 7: Run the regression suite + new trait test**

Run: `vendor/bin/pest tests/MediaLibraryPageTest.php tests/MediaLibraryUploadTest.php tests/BulkActionsTest.php tests/FolderActionsTest.php tests/MediaActionsTest.php tests/BrowsesMediaTest.php`
Expected: PASS — behavior unchanged.

- [ ] **Step 8: Lint, analyse, commit**

```bash
vendor/bin/pint && vendor/bin/phpstan analyse --memory-limit=1G
git add src/Concerns/BrowsesMedia.php src/Pages/MediaLibrary.php resources/views/partials/media-grid.blade.php resources/views/media-library.blade.php tests/BrowsesMediaTest.php
git commit -m "refactor: extract BrowsesMedia trait and shared media-grid partial"
```

---

## Task 9: `MediaPickerBrowser` Livewire component (select mode)

**Files:**
- Create: `src/Livewire/MediaPickerBrowser.php`
- Create: `resources/views/media-picker-browser.blade.php`
- Modify: `src/MediaManagerServiceProvider.php` (register the Livewire component in `packageBooted`)
- Test: `tests/MediaPickerBrowserTest.php`

**Interfaces:**
- Consumes: `BrowsesMedia` trait, `PickMedia`, `Media`.
- Produces:
  - A Livewire component `MediaPickerBrowser` using `BrowsesMedia` + `WithFileUploads`, with `imagesOnly()` returning `true`.
  - Public mount props: `array $variations = []`, `string $statePath = ''`.
  - `select(int $mediaId): void` — runs `PickMedia::execute(Media, variations)`, then `$this->dispatch('image-picker:selected', statePath: $this->statePath, valueId: $value->id)` and `$this->dispatch('image-picker:close')`.
  - Renders `filament-media-manager::media-picker-browser` (toolbar + `@include partials.media-grid` in `select` mode).

- [ ] **Step 1: Write the failing test**

`tests/MediaPickerBrowserTest.php`:
```php
<?php

use Livewire\Livewire;
use MarvelousDigital\FilamentMediaManager\Enums\MediaType;
use MarvelousDigital\FilamentMediaManager\Livewire\MediaPickerBrowser;
use MarvelousDigital\FilamentMediaManager\Models\Media;
use MarvelousDigital\FilamentMediaManager\Models\MediaFieldValue;

it('lists only images', function () {
    Media::factory()->create(['name' => 'pic', 'type' => MediaType::Image]);
    Media::factory()->create(['name' => 'clip', 'type' => MediaType::Video, 'mime_type' => 'video/mp4']);

    Livewire::test(MediaPickerBrowser::class, ['variations' => ['hero' => [1600, 600]], 'statePath' => 'data.hero_image_id'])
        ->assertSee('pic')
        ->assertDontSee('clip');
});

it('creates a usage record and dispatches selection on select', function () {
    $media = Media::factory()->create(['type' => MediaType::Image, 'alt_text' => 'Cat']);

    Livewire::test(MediaPickerBrowser::class, ['variations' => ['hero' => [1600, 600]], 'statePath' => 'data.hero_image_id'])
        ->call('select', $media->id)
        ->assertDispatched('image-picker:selected');

    $value = MediaFieldValue::query()->latest('id')->first();
    expect($value->media_id)->toBe($media->id)
        ->and($value->alt_text)->toBe('Cat')
        ->and($value->conversion('hero'))->not->toBeNull();
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/pest tests/MediaPickerBrowserTest.php`
Expected: FAIL — `MediaPickerBrowser` not found.

- [ ] **Step 3: Implement the component**

`src/Livewire/MediaPickerBrowser.php`:
```php
<?php

namespace MarvelousDigital\FilamentMediaManager\Livewire;

use Illuminate\Contracts\View\View;
use Livewire\Component;
use Livewire\WithFileUploads;
use MarvelousDigital\FilamentMediaManager\Actions\PickMedia;
use MarvelousDigital\FilamentMediaManager\Concerns\BrowsesMedia;
use MarvelousDigital\FilamentMediaManager\Models\Media;

class MediaPickerBrowser extends Component
{
    use BrowsesMedia;
    use WithFileUploads;

    /** @var array<string, array{0: int, 1: int}> */
    public array $variations = [];

    public string $statePath = '';

    protected function imagesOnly(): bool
    {
        return true;
    }

    public function select(int $mediaId): void
    {
        $media = Media::find($mediaId);

        if (! $media) {
            return;
        }

        $value = app(PickMedia::class)->execute($media, $this->variations);

        $this->dispatch('image-picker:selected', statePath: $this->statePath, valueId: $value->id);
        $this->dispatch('image-picker:close');
    }

    public function render(): View
    {
        return view('filament-media-manager::media-picker-browser', $this->browseData());
    }
}
```

- [ ] **Step 4: Create the view**

`resources/views/media-picker-browser.blade.php` — mirror the page toolbar (breadcrumbs + search + upload) then include the grid in select mode:
```blade
<div class="fmm-library"
    x-on:dragover.prevent
    x-on:drop.prevent="
        const uploadEl = document.querySelector('.fmm-picker-upload');
        if (uploadEl) Alpine.$data(uploadEl).handleFiles($event.dataTransfer.files);
    "
>
    <div class="fmm-toolbar">
        <nav class="fmm-breadcrumbs">
            <button type="button" wire:click="openFolder(null)" @class(['fmm-crumb', 'fmm-crumb-current' => ! $currentFolder])>Media</button>
            @foreach ($breadcrumbFolders as $crumb)
                <span class="fmm-crumb-sep">/</span>
                <button type="button" wire:click="openFolder({{ $crumb->id }})" @class(['fmm-crumb', 'fmm-crumb-current' => $crumb->id === $folderId])>{{ $crumb->name }}</button>
            @endforeach
        </nav>

        <div class="fmm-toolbar-actions">
            <input type="search" wire:model.live.debounce.400ms="search" placeholder="Search this folder…" class="fmm-search fi-input" />
            <div x-data="{
                    uploading: false,
                    handleFiles(files) {
                        if (! files.length) return;
                        this.uploading = true;
                        $wire.uploadMultiple('uploads', files,
                            () => { this.uploading = false; },
                            () => { this.uploading = false; },
                        );
                    },
                }"
                x-on:dragover.prevent
                x-on:drop.prevent.stop="handleFiles($event.dataTransfer.files)"
                class="fmm-picker-upload"
            >
                <input type="file" multiple accept="image/*" class="fmm-upload-input" x-ref="input" x-on:change="handleFiles($event.target.files); $event.target.value = null;" />
                <x-filament::button icon="heroicon-o-arrow-up-tray" x-on:click="$refs.input.click()" x-show="! uploading">Upload</x-filament::button>
                <span x-show="uploading" x-cloak>Uploading…</span>
            </div>
        </div>
    </div>

    @include('filament-media-manager::partials.media-grid', ['mode' => 'select'])
</div>
```

- [ ] **Step 5: Register the Livewire component**

In `src/MediaManagerServiceProvider.php` `packageBooted()`, register the component so `@livewire('...')`/`Livewire::test` resolves it:
```php
use Livewire\Livewire;
use MarvelousDigital\FilamentMediaManager\Livewire\MediaPickerBrowser;

// inside packageBooted(), after FilamentAsset::register(...):
Livewire::component('filament-media-manager::media-picker-browser', MediaPickerBrowser::class);
```

- [ ] **Step 6: Run test to verify it passes**

Run: `vendor/bin/pest tests/MediaPickerBrowserTest.php`
Expected: PASS.

- [ ] **Step 7: Lint, analyse, commit**

```bash
vendor/bin/pint && vendor/bin/phpstan analyse --memory-limit=1G
git add src/Livewire/MediaPickerBrowser.php resources/views/media-picker-browser.blade.php src/MediaManagerServiceProvider.php tests/MediaPickerBrowserTest.php
git commit -m "feat: add MediaPickerBrowser select-mode component"
```

---

## Task 10: Field UI — pick modal, filled state, alt text, per-variation focus editor, retry

This task is view/interaction-heavy (Alpine + Livewire). Backend behaviors it triggers are already tested (Tasks 5, 6, 9). Verify the interactive pieces manually in a browser.

**Files:**
- Modify: `src/Forms/Components/ImagePicker.php` (add focus-save + retry Livewire-callable helpers via the field's action or a small companion)
- Modify: `resources/views/forms/components/image-picker.blade.php` (full UI)
- Modify: `resources/css/index.css` (picker + focus editor styles, `fmm-` prefixed)
- Test: `tests/ImagePickerFocusTest.php` (focus-update behavior, tested at the model/action layer)

**Interfaces:**
- Consumes: `MediaFieldValue`, `MediaConversion`, `GenerateConversion`, `ConversionStatus`.
- Produces:
  - `UpdateConversionFocus` action (`src/Actions/UpdateConversionFocus.php`): `execute(MediaConversion $conversion, float $x, float $y): void` — clamps to [0,1], updates focus, sets status Pending, dispatches `GenerateConversion::dispatchFor`.
  - Field view: empty state (button opens modal with `@livewire(MediaPickerBrowser)`), filled state (original preview, alt text input bound to the value, Change/Remove, per-variation focus editor with draggable marker + live crop preview, per-variation retry when status Failed).
  - Selection wiring: Alpine listens for `image-picker:selected`; when `statePath` matches the field, sets `$wire.set(statePath, valueId)` and closes the modal.

- [ ] **Step 1: Write the focus-update test (backend layer)**

`tests/ImagePickerFocusTest.php`:
```php
<?php

use Illuminate\Support\Facades\Queue;
use MarvelousDigital\FilamentMediaManager\Actions\UpdateConversionFocus;
use MarvelousDigital\FilamentMediaManager\Enums\ConversionStatus;
use MarvelousDigital\FilamentMediaManager\Jobs\GenerateConversion;
use MarvelousDigital\FilamentMediaManager\Models\Media;
use MarvelousDigital\FilamentMediaManager\Models\MediaConversion;
use MarvelousDigital\FilamentMediaManager\Models\MediaFieldValue;

it('updates focus, marks pending, and re-dispatches generation', function () {
    Queue::fake();
    $media = Media::factory()->create();
    $value = MediaFieldValue::factory()->for($media, 'source')->create();
    $conversion = MediaConversion::factory()->for($value, 'value')->create([
        'status' => ConversionStatus::Completed, 'focus_x' => 0.5, 'focus_y' => 0.5,
    ]);

    app(UpdateConversionFocus::class)->execute($conversion, 0.2, 0.9);

    $conversion->refresh();
    expect($conversion->focus_x)->toBe(0.2)
        ->and($conversion->focus_y)->toBe(0.9)
        ->and($conversion->status)->toBe(ConversionStatus::Pending);

    Queue::assertPushed(GenerateConversion::class);
});

it('clamps focus values to the unit interval', function () {
    Queue::fake();
    $media = Media::factory()->create();
    $value = MediaFieldValue::factory()->for($media, 'source')->create();
    $conversion = MediaConversion::factory()->for($value, 'value')->create();

    app(UpdateConversionFocus::class)->execute($conversion, -1.0, 5.0);

    $conversion->refresh();
    expect($conversion->focus_x)->toBe(0.0)->and($conversion->focus_y)->toBe(1.0);
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/pest tests/ImagePickerFocusTest.php`
Expected: FAIL — `UpdateConversionFocus` not found.

- [ ] **Step 3: Implement `UpdateConversionFocus`**

`src/Actions/UpdateConversionFocus.php`:
```php
<?php

namespace MarvelousDigital\FilamentMediaManager\Actions;

use MarvelousDigital\FilamentMediaManager\Enums\ConversionStatus;
use MarvelousDigital\FilamentMediaManager\Jobs\GenerateConversion;
use MarvelousDigital\FilamentMediaManager\Models\MediaConversion;

class UpdateConversionFocus
{
    public function execute(MediaConversion $conversion, float $x, float $y): void
    {
        $conversion->update([
            'focus_x' => max(0.0, min(1.0, $x)),
            'focus_y' => max(0.0, min(1.0, $y)),
            'status' => ConversionStatus::Pending,
        ]);

        GenerateConversion::dispatchFor($conversion);
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `vendor/bin/pest tests/ImagePickerFocusTest.php`
Expected: PASS.

- [ ] **Step 5: Build the full field view**

Replace `resources/views/forms/components/image-picker.blade.php`. Key structure (implement with the field's `MediaFieldValue` loaded from current state):

```blade
@php
    $value = ($id = $getState()) ? \MarvelousDigital\FilamentMediaManager\Models\MediaFieldValue::with('conversions')->find($id) : null;
    $statePath = $getStatePath();
@endphp

<x-dynamic-component :component="$getFieldWrapperView()" :field="$field">
    <div
        class="fmm-image-picker"
        x-data="{
            open: false,
            init() {
                window.addEventListener('image-picker:selected', (e) => {
                    if (e.detail.statePath !== @js($statePath)) return;
                    $wire.set(@js($statePath), e.detail.valueId);
                    this.open = false;
                });
                window.addEventListener('image-picker:close', () => { this.open = false; });
            },
        }"
    >
        @if ($value)
            <div class="fmm-picker-preview">
                <img src="{{ $value->url() }}" alt="{{ $value->alt_text }}" />
                <div class="fmm-picker-actions">
                    <x-filament::button size="sm" color="gray" x-on:click="open = true">Change</x-filament::button>
                    <x-filament::button size="sm" color="danger" x-on:click="$wire.set(@js($statePath), null)">Remove</x-filament::button>
                </div>
            </div>

            {{-- Alt text: bound to the usage record via a Livewire-callable on the host, or a nested edit action.
                 Simplest robust approach: an input that calls a field action to persist. --}}
            <div class="fmm-picker-alt">
                <label>Alt text</label>
                <input type="text" class="fi-input"
                    value="{{ $value->alt_text }}"
                    x-on:change="$wire.mountFormComponentAction(@js($statePath), 'updateAlt', { alt: $event.target.value })" />
            </div>

            <div class="fmm-focus-grid">
                @foreach ($value->conversions as $conversion)
                    <div class="fmm-focus-editor" wire:key="conv-{{ $conversion->id }}"
                        x-data="{
                            fx: {{ $conversion->focus_x }},
                            fy: {{ $conversion->focus_y }},
                            setFromEvent(e) {
                                const r = $refs.img.getBoundingClientRect();
                                this.fx = Math.min(1, Math.max(0, (e.clientX - r.left) / r.width));
                                this.fy = Math.min(1, Math.max(0, (e.clientY - r.top) / r.height));
                                $wire.mountFormComponentAction(@js($statePath), 'updateFocus', { conversion: {{ $conversion->id }}, x: this.fx, y: this.fy });
                            },
                        }"
                    >
                        <span class="fmm-focus-label">{{ $conversion->variation }} ({{ $conversion->width }}×{{ $conversion->height }})</span>
                        <div class="fmm-focus-image" x-on:click="setFromEvent($event)">
                            <img x-ref="img" src="{{ $value->url() }}" alt="" />
                            <span class="fmm-focus-marker" x-bind:style="`left:${fx*100}%;top:${fy*100}%`"></span>
                        </div>
                        @if ($conversion->status === \MarvelousDigital\FilamentMediaManager\Enums\ConversionStatus::Failed)
                            <x-filament::button size="sm" color="warning"
                                x-on:click="$wire.mountFormComponentAction(@js($statePath), 'retryConversion', { conversion: {{ $conversion->id }} })">
                                Retry
                            </x-filament::button>
                        @elseif ($conversion->status !== \MarvelousDigital\FilamentMediaManager\Enums\ConversionStatus::Completed)
                            <span class="fmm-focus-pending">Generating…</span>
                        @endif
                    </div>
                @endforeach
            </div>
        @else
            <x-filament::button icon="heroicon-o-photo" color="gray" x-on:click="open = true">Pick image</x-filament::button>
        @endif

        <input type="hidden" {{ $applyStateBindingModifiers('wire:model') }}="{{ $statePath }}" />

        <template x-teleport="body">
            <x-filament::modal :id="'image-picker-' . $statePath" width="5xl" x-model="open">
                <x-slot name="heading">Select an image</x-slot>
                <template x-if="open">
                    @livewire('filament-media-manager::media-picker-browser', ['variations' => $getVariations(), 'statePath' => $statePath], key($statePath . '-browser'))
                </template>
            </x-filament::modal>
        </template>
    </div>
</x-dynamic-component>
```

Note: the `mountFormComponentAction(...)` calls require the field to register `updateAlt`, `updateFocus`, `retryConversion` actions. Register them in `ImagePicker` via `registerActions()` / `getActions()` returning `Filament\Forms\Components\Actions\Action` instances whose `action()` closures load the relevant records and call `UpdateConversionFocus` / update alt / re-dispatch. Confirm the exact Filament v5 form-component-action API with `grep -rn "mountFormComponentAction\|registerActions" vendor/filament/`. If the action-registration API differs, the fallback is a tiny dedicated Livewire companion component embedded in the field view exposing `updateAlt/updateFocus/retryConversion` methods — but prefer the native field-action path.

- [ ] **Step 6: Add the field actions**

In `src/Forms/Components/ImagePicker.php`, register three form-component actions. Sketch (adapt names/signatures to the confirmed Filament v5 API from Step 5's grep):
```php
use Filament\Forms\Components\Actions\Action;
use MarvelousDigital\FilamentMediaManager\Actions\UpdateConversionFocus;
use MarvelousDigital\FilamentMediaManager\Enums\ConversionStatus;
use MarvelousDigital\FilamentMediaManager\Jobs\GenerateConversion;
use MarvelousDigital\FilamentMediaManager\Models\MediaConversion;
use MarvelousDigital\FilamentMediaManager\Models\MediaFieldValue;

// in the field:
protected function setUp(): void
{
    parent::setUp();

    $this->registerActions([
        fn (): Action => Action::make('updateAlt')
            ->action(function (array $arguments) {
                MediaFieldValue::find($this->getState())?->update(['alt_text' => $arguments['alt'] ?? null]);
            }),
        fn (): Action => Action::make('updateFocus')
            ->action(function (array $arguments) {
                $conversion = MediaConversion::find((int) $arguments['conversion']);
                if ($conversion) {
                    app(UpdateConversionFocus::class)->execute($conversion, (float) $arguments['x'], (float) $arguments['y']);
                }
            }),
        fn (): Action => Action::make('retryConversion')
            ->action(function (array $arguments) {
                $conversion = MediaConversion::find((int) $arguments['conversion']);
                if ($conversion) {
                    $conversion->update(['status' => ConversionStatus::Pending]);
                    GenerateConversion::dispatchFor($conversion);
                }
            }),
    ]);
}
```

- [ ] **Step 7: Add styles**

Append to `resources/css/index.css` (all `fmm-` prefixed): `.fmm-image-picker`, `.fmm-picker-preview img` (bounded max-height, object-fit cover), `.fmm-picker-actions`, `.fmm-picker-alt`, `.fmm-focus-grid` (responsive grid), `.fmm-focus-editor`, `.fmm-focus-image` (position: relative), `.fmm-focus-marker` (absolute, translate(-50%,-50%), ring), `.fmm-focus-pending`. Then rebuild assets: `npm run build` (or the project's build script — check `package.json`).

- [ ] **Step 8: Run the backend focus test + full suite**

Run: `vendor/bin/pest tests/ImagePickerFocusTest.php`
Expected: PASS.
Run: `composer test`
Expected: PASS (whole suite).

- [ ] **Step 9: Manual browser verification**

In a scratch Filament resource form, add:
```php
ImagePicker::make('hero_image_id')->variations(['hero' => [1600, 600], 'thumb' => [400, 400]]);
```
Verify: Pick opens the browser modal; selecting an image closes it and shows the preview; upload-in-modal then select works; alt text edits persist; dragging/clicking a focus marker updates the crop and (with a queue worker or `thumbnail.queue=false`) regenerates; a failed conversion shows Retry. Confirm no horizontal overflow and it works in light/dark.

- [ ] **Step 10: Lint, analyse, commit**

```bash
vendor/bin/pint && vendor/bin/phpstan analyse --memory-limit=1G
git add src/Forms/Components/ImagePicker.php src/Actions/UpdateConversionFocus.php resources/views/forms/components/image-picker.blade.php resources/css/index.css resources/dist/ tests/ImagePickerFocusTest.php
git commit -m "feat: add ImagePicker field UI with focus-point editor"
```

---

## Task 11: Documentation + changelog + final verification

**Files:**
- Modify: `README.md` (new "ImagePicker field" section + roadmap update)
- Modify: `CHANGELOG.md`

- [ ] **Step 1: Document the field in the README**

Add a section covering:
- Migration: add a nullable FK column on the parent, e.g. `$table->foreignId('hero_image_id')->nullable()->constrained('media_field_values')->nullOnDelete();`
- Model: `public function heroImage(): BelongsTo { return $this->belongsTo(MediaFieldValue::class, 'hero_image_id'); }`
- Form usage with `->variations([...])`.
- Consuming: `$post->heroImage?->conversion('hero')?->url()`, `->alt_text`, `->url()`.
- Note conversions are queued (same knobs as thumbnails); a worker must run in production.
- Move the "picker form component" and "focus points / size variations" bullets out of the Roadmap (now delivered).

- [ ] **Step 2: Update the changelog**

Add an entry under the next version describing the ImagePicker field, per-variation focus points, and conversions.

- [ ] **Step 3: Full verification**

Run: `composer test`
Expected: PASS.
Run: `vendor/bin/pint --test`
Expected: no style issues.
Run: `vendor/bin/phpstan analyse --memory-limit=1G`
Expected: no errors.

- [ ] **Step 4: Commit**

```bash
git add README.md CHANGELOG.md
git commit -m "docs: document ImagePicker field and update roadmap"
```

---

## Self-Review Notes (for the executor)

- **Filament v5 API confidence:** three spots use APIs to confirm against the installed vendor code before/while implementing — the form `Field` base namespace (Task 7), the intervention/image `crop`/`resize` signatures (Task 5), and form-component action registration + `mountFormComponentAction` (Task 10). Each step says exactly which `grep` to run and gives a fallback. Do not skip those greps.
- **Task 2/3 ordering:** the two models are mutually referential; implement together and commit once (noted in Task 2 Step 5–6).
- **Regression safety:** Task 8 refactors tested code — Step 2 captures the green baseline and Step 7 re-runs the same suite. If any previously-green test fails after the refactor, the extraction changed behavior; fix before committing.
- **No new config keys** — conversions reuse thumbnail knobs, per the spec.

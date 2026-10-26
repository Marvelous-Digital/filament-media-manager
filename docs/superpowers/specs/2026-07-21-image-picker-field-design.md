# ImagePicker Field — Design

**Date:** 2026-07-21
**Status:** Approved, ready for implementation planning

## Summary

Add an `ImagePicker` Filament v5 form field that lets a contributor select an image
from the media library (or upload a new one and immediately select it). Picking does
not merely reference a `Media` row — it creates a new **usage record** that references
the shared original file, carries its own overridable metadata (alt text), and defines
per-field **variations** (named crops). Each variation has its own **focus point** set
by the contributor, and each generates its own cropped conversion file, queued the same
way library thumbnails are.

This delivers two items previously on the plugin roadmap: a picker form component, and
focus-point-driven crops / size variations.

## Goals

- A reusable `ImagePicker` field usable on any Filament resource/form.
- Picking creates a per-usage record so the same library image can be used in multiple
  places with independent alt text, focus points, and crops.
- Never duplicate the original file — the usage record references the library original.
- Per-field named variations with per-variation focus points and generated crops.
- Reuse the existing browsing and thumbnail-generation patterns rather than duplicating
  them.

## Non-goals (for this iteration)

- Multiple images per field / galleries. Single image only. `->multiple()` may come later.
- Non-image media in this field (it is images-only by name and behavior).
- Editing the source library original from within the picker (that stays in the
  Media Library page).

## Decisions (from brainstorming)

- **Value semantics:** picking creates a new usage item that copies source metadata,
  which can then be overridden. Not a bare reference to the source `Media`.
- **File handling:** reference the original file; the usage record owns its own
  conversions (separate files). Editing the library original is reflected; conversions
  are per-usage.
- **Variations:** declared per-field as named crops with target dimensions.
- **Cardinality:** single image per field for now.
- **Focus point:** per-variation (each named variation has its own focus point).
- **Parent link:** a nullable FK column on the parent (e.g. `hero_image_id`) → usage id.
- **Generation:** queued, mirroring the existing `GenerateThumbnail` job/status pattern.
- **Picker UI:** extract a shared `MediaBrowser` from the `MediaLibrary` page and reuse
  it in the picker modal (Approach A), rather than duplicating browse/upload logic.

## Data model

### Table `media_field_values` (the per-usage record)

| column       | type                | notes                                            |
|--------------|---------------------|--------------------------------------------------|
| `id`         | id                  |                                                  |
| `media_id`   | FK → `media.id`     | the shared original, never copied                |
| `alt_text`   | string, nullable    | copied from source on pick, overridable          |
| `created_by` | unsignedBigInt null |                                                  |
| timestamps   |                     |                                                  |

On delete of the source `Media`, `media_id` should be handled gracefully (see
Error handling). Deleting a usage record deletes its conversion files (best-effort,
mirroring `Media::booted()` cleanup), never the shared original.

### Table `media_conversions` (one row per variation of a value)

| column                   | type              | notes                                   |
|--------------------------|-------------------|-----------------------------------------|
| `id`                     | id                |                                         |
| `media_field_value_id`   | FK                | cascade on delete                       |
| `variation`              | string            | e.g. `hero`; unique per value           |
| `width`                  | int               | target width from field def             |
| `height`                 | int               | target height from field def            |
| `focus_x`                | float (0–1)       | default 0.5                             |
| `focus_y`                | float (0–1)       | default 0.5                             |
| `path`                   | string, nullable  | generated conversion file              |
| `status`                 | enum              | `ConversionStatus` (see Enums)          |
| timestamps               |                   |                                         |

Unique index on (`media_field_value_id`, `variation`).

### Parent model

The parent (e.g. a `Post`) gets a nullable FK column, conventionally
`{field}_id` (e.g. `hero_image_id`) → `media_field_values.id`, and a documented
`belongsTo` accessor (e.g. `heroImage()`).

## Enums

- Reuse the existing `ThumbnailStatus` pattern. Introduce `ConversionStatus`
  (`Pending`, `Processing`, `Completed`, `Failed`) rather than overloading
  `ThumbnailStatus`, so the two concerns evolve independently. (Values mirror the
  existing enum.)

## Models

- `MediaFieldValue`
  - `belongsTo(Media::class, 'media_id')` as `source`.
  - `hasMany(MediaConversion::class)` as `conversions`.
  - `conversion(string $variation): ?MediaConversion` helper.
  - `url(): ?string` — original via the source (delegates to `Media::url()`).
  - `directory()` — per-usage directory for conversion files.
  - `booted()` deleted hook → best-effort delete of conversion directory.
- `MediaConversion`
  - `belongsTo(MediaFieldValue::class)`.
  - `url(): ?string` — resolves `path` on the configured disk (same resolve logic
    as `Media::resolveUrl`).

## Field API

```php
ImagePicker::make('hero_image_id')
    ->variations([
        'hero'  => [1600, 600],
        'card'  => [800, 600],
        'thumb' => [400, 400],
    ])
    ->disk('public')            // defaults to config disk
    ->required();
```

- Extends `Filament\Forms\Components\Field`.
- `->variations(array $variations)` — map of `name => [width, height]`. If omitted,
  a single implicit variation is used so the field works with zero config.
- `->disk(string)` — defaults to `config('filament-media-manager.disk')`.
- Images-only: browse grid and inline upload filter to `image/*`.
- **State** = `media_field_values.id`.
  - Hydration: read the FK column value.
  - `dehydrateStateUsing`: ensure the usage record is persisted and return its id
    to the parent FK column. Removing clears the column to `null`.

## Field rendering + focus editor

Custom Blade view (Alpine + entangled Livewire state):

- **Empty state:** "Pick image" button → opens the `MediaBrowser` modal in select
  mode (folders + search + grid + inline upload, filtered to images). Selecting an
  image:
  1. creates a `MediaFieldValue` for the chosen `Media`,
  2. copies `alt_text` from the source,
  3. seeds one `media_conversions` row per declared variation at center focus
     (0.5, 0.5) with `status = Pending`,
  4. dispatches generation.
- **Filled state:**
  - preview of the chosen original,
  - editable **alt text** input (updates the usage record),
  - **Change / Remove** controls,
  - **per-variation focus editor:** for each variation, the source image with a
    draggable focus marker and a live crop preview at that variation's aspect ratio;
    dragging updates that row's `focus_x/focus_y`.
- Changing a focus point or swapping the image marks affected conversions `Pending`
  and re-dispatches `GenerateConversion`. A per-variation retry affordance mirrors
  the library's retry-thumbnail action.

## MediaBrowser extraction

Extract the folder-navigation + search + grid + inline-upload behavior from
`MediaLibrary` into a reusable Livewire component (`MediaBrowser`). The existing
`MediaLibrary` page renders it in "manage" mode; the picker renders it in "select"
mode (click emits the selected media id, image-type filter applied). This keeps a
single, tested browsing implementation and simplifies the page.

## Conversion generation (`GenerateConversion` job)

Mirrors `GenerateThumbnail`:

- Queued by default; honors the same config knobs (`thumbnail.queue`,
  `thumbnail.queue_name`) — or a parallel `conversions` config block if the knobs
  should diverge (default: reuse thumbnail knobs).
- Per conversion row: load the source original, compute the crop rectangle from
  `focus_x/focus_y` + target aspect ratio, resize to `width×height`, encode WebP,
  store under the usage record's directory, set `path` + `status = Completed`
  (or `Failed` on error).
- Uses Laravel's `Image` facade / intervention-image v4, consistent with the
  existing thumbnail job.

### Cropping math

Given normalized focus `(fx, fy)` and target aspect `t = width/height`, and source
`(sw, sh)`:
- Choose the largest crop rectangle of aspect `t` that fits within the source.
- Position it so the focus point stays centered where possible, clamped to source
  bounds.
- Resize the crop to `width×height`.

## Consuming the value

```php
$post->heroImage?->conversion('hero')?->url();
$post->heroImage?->alt_text;
$post->heroImage?->url(); // original
```

Documented in the README with a `belongsTo` example and a migration snippet for the
parent FK column.

## Error handling

- **Source deleted:** if the underlying `Media` is deleted, `MediaFieldValue::source`
  is null. Consumers null-check (`?->`). Conversions already generated remain valid
  files (they are independent copies) until the usage record itself is deleted.
- **Generation failure:** conversion row `status = Failed`; retry affordance available.
- **Disk without public URL:** `url()` falls back to a temporary URL, mirroring
  `Media::resolveUrl`.
- **Non-image picked:** prevented by the image-only filter; upload validation rejects
  non-images.

## Testing (Pest)

- Picking creates a `MediaFieldValue`, copies `alt_text`, and seeds one conversion
  row per declared variation at center focus.
- Changing a focus point sets the affected conversion(s) to `Pending` and dispatches
  `GenerateConversion`.
- `GenerateConversion` crops around the focus point to the target dimensions and
  marks the row `Completed`.
- Dehydration writes the usage id to the parent FK column; removing sets it null.
- Deleting a usage record cleans up conversion files but not the shared original.
- Existing arch tests continue to pass.

## Implementation phasing (for the plan, not a design change)

1. Models, enum, migrations; extract `MediaBrowser` from `MediaLibrary`.
2. `ImagePicker` field: pick → create usage → copy alt → dehydrate FK; empty/filled
   rendering; Remove/Change.
3. Per-variation focus editor + `GenerateConversion` job + retry; README + tests.

Shipped as a single branch.

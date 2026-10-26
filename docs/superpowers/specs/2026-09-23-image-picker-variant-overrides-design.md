# ImagePicker — Ratios, Per-Variant Overrides & Apply-on-Save — Design

**Date:** 2026-09-23
**Status:** Approved in conversation, pending written-spec review
**Builds on:** `2026-07-21-image-picker-field-design.md`

## Summary

Rework the `ImagePicker` field so the admin:

1. sees the required formats (ratios) before picking anything,
2. can upload a new image (auto-selected) or pick one from the library,
3. can reframe each variant (focus point + zoom),
4. can override the image for a single variant, with its own focus, zoom and alt text,

and so that **nothing is written until the form is saved** — Cancel discards all image edits.

## Goals

- Required formats visible in the empty state, as reduced ratio + pixel size.
- Direct upload from the field that selects the uploaded image immediately.
- Per-variant image override with its own framing and alt text; "Use main image" reverts.
- Changing the main image keeps variant overrides; non-overridden variants reset framing.
- Edits (image, alt, focus, zoom, overrides) held in form state and persisted on Save only.
- Change updates the existing `media_field_values` row in place — no new row per pick.
- Source image dimensions available immediately after upload.
- Variation sync: saved values follow the field's current `->variations()` declaration.
- Frontend output (`ImageBlock` / page JSON storing a single id) stays backwards compatible.

## Non-goals

- Cleanup of orphaned `media_field_values` rows from pages deleted elsewhere.
- Server-side verification of the field's state id (Filament admins are trusted; the
  frontend never submits image ids — only `GET /{locale}` and `GET /{locale}/{slug}`).
- HEIC dimension/thumbnail support (tracked separately).
- Behavior when the **main** library image is deleted (unchanged: DB cascade).
- Multiple images per field.

## Data model

### `media_conversions` — new columns

| Column     | Type                                         | Meaning                                              |
|------------|----------------------------------------------|------------------------------------------------------|
| `media_id` | nullable FK → `media`, `nullOnDelete`         | Variant's own image. Null = use the value's main image |
| `alt_text` | nullable string                              | Variant alt text. Only used when `media_id` is set; null = fall back to the value's `alt_text` |

Delivered like the `zoom` column: update the plugin's `create_media_field_tables` stub and
add a host-app migration for existing databases.

### `media_field_values`

Unchanged (`media_id` = main image, `alt_text` = main alt). Rows are updated in place.

### Conversion file location

`MediaFieldValue::directory()` currently derives from the main source path, which moves when
the main image changes. It becomes source-independent:
`{config directory}/conversions/{value id}`. Existing rows keep resolving because `path` is
stored per conversion.

### Model helpers

- `MediaConversion::source(): BelongsTo` (override `Media`) and
  `MediaConversion::effectiveSource(): Media` — override, else `value->source`.
- `MediaConversion::effectiveAltText(): ?string` — own `alt_text` when overridden and
  filled, else `value->alt_text`.
- `maxSafeZoom()` uses `effectiveSource()`.

## Form state

The page JSON continues to store only the value id (`"image": 57`). While editing, the field
state is:

```php
[
    'id' => 57,            // null until first save
    'media_id' => 12,      // main image
    'alt' => 'Team on stage',
    'variants' => [
        'desktop' => ['media_id' => null, 'alt' => null, 'focus_x' => 0.5, 'focus_y' => 0.4, 'zoom' => 1.0],
        'mobile'  => ['media_id' => 31, 'alt' => 'Speaker close-up', 'focus_x' => 0.6, 'focus_y' => 0.5, 'zoom' => 1.8],
    ],
]
```

### Hydration (`afterStateHydrated`)

- Scalar id → load row with conversions → build the array above.
- **Variation sync:** the `variants` keys are exactly the field's current `getVariations()`.
  Saved focus/zoom/override/alt are matched by variation name; newly declared variations
  start at center focus, zoom 1, no override. Conversions for undeclared variations are not
  loaded (and are deleted on save).
- Null / missing id → `null` state (empty field).

### Dehydration (`dehydrateStateUsing`)

Runs on submit after validation, inside the page's save transaction
(`EditRecord::save` / `CreateRecord::create`). `saveRelationships` is not used: it runs after
the form data is collected (after insert on Create), too late to write the new id into the
block JSON.

Delegates to `PersistImagePicker::execute(array $state, array $variations): ?int`, which:

1. **Removed** (`media_id` null) with an existing `id` → delete the row (the `deleted` hook
   removes its conversion directory) → return `null`.
2. **No row yet** → create `media_field_values`. **Existing** → update `media_id` / `alt_text`
   in place.
3. For each declared variation: upsert the conversion. Mark it `Pending` and queue
   regeneration when any of `media_id`, `focus_x`, `focus_y`, `zoom`, `width`, `height`
   changed, when the row is new, or when the effective source changed (main image changed
   and the variant has no override).
4. Delete conversions (and their files) for variations no longer declared.
5. Return the id.

Regeneration jobs are dispatched with `afterCommit()` so a rolled-back save generates nothing.

## Field UI

Inline, compact layout (chosen over modal / always-expanded editors).

### Empty state

- Buttons **Upload** and **Pick from library**.
- "Required formats": one placeholder per variation drawn at its aspect ratio, labelled with
  name, reduced ratio (`1500×900` → `5:3`, via a `Ratio::reduce()` helper using gcd) and
  pixel size in small print.

### Upload

- Opens the browser file dialog directly (no library modal), using Livewire file upload on
  the field.
- Stored through `UploadMedia` into the library root folder, then set as the target
  (main image or a variant override) in form state.
- Size / mime validation and notifications as in the library.

### Filled state

- Main preview with **Change** and **Remove**; main alt text input.
- Variant cards in a wrapping row. Each card:
  - label + ratio;
  - live cropped thumbnail rendered client-side from the effective source, focus and zoom
    (reusing the existing `cropBox()` / `previewStyle()` math);
  - status badge: ready / generating / failed + **Retry**;
  - "Own image" badge when overridden;
  - **Reframe** — expands the focus + zoom editor below the card (one open at a time);
  - **Use different image** (Upload / Pick for this variant only) / **Use main image**;
  - alt text input, only when overridden (prefilled from the picked media's library alt).
- "Not saved yet" hint while the field state differs from the last loaded state.

### Change main image

Overridden variants keep image, framing and alt. Non-overridden variants reset to focus
(0.5, 0.5), zoom 1.

### Polling

After save, `wire:poll.3s` while any saved conversion is `Pending` (as today).

## Picking

- `MediaPickerBrowser::select(int $mediaId)` no longer persists anything. It dispatches
  `image-picker:selected` with `statePath`, `target` (`main` or a variation name) and
  `mediaId`; the field updates its state.
- On select, if the `Media` has no `width`/`height`, measure and store them (legacy uploads).
- `PickMedia` is removed; its responsibilities move to `PersistImagePicker`.

## Field actions

- `select` — opens the picker modal; now also passes `target`.
- `retryConversion` — kept; operates on already-saved conversions only.
- `updateAlt`, `updateFocus` — removed; Alpine writes into field state instead.

## Source dimensions

`UploadMedia` reads width/height with `getimagesize()` on the uploaded temp file and stores
them on create. Unsupported formats leave them null; the editor keeps today's fallback
(focus only, no crop rectangle, no over-zoom warning).

## Conversion generation (`GenerateConversion`)

- Crops from `effectiveSource()`.
- Re-reads the conversion when handled; always uses current values.
- Versioned file name: `{variation}-{hash}.{format}`, hash = short hash of
  `effective media id | width | height | focus_x | focus_y | zoom`. After storing, set `path`
  and `Completed`, then delete the previous file if its path differs. The live site keeps
  serving the old file until the swap.
- Failure: `Failed`, previous `path`/file untouched, exception reported.

## Media deletion

`Media` `deleting` hook: conversions whose `media_id` is this media → set `media_id` and
`alt_text` null, `Pending`, queue regeneration from the main image (so a variant never keeps
serving a crop of a deleted image).

## Validation

- Main `media_id` / override `media_id`s must exist in `media` at save time; otherwise the
  field fails validation: "This image is no longer in the library."
- Focus clamped to [0, 1], zoom to [1, 5] server-side in `PersistImagePicker`.

## Host app (`media-lib-test`)

- Migration adding `media_id` + `alt_text` to `media_conversions`.
- `ImageBlock::formatSources()` adds `alt` (`effectiveAltText()`) per variation.
- `image-block.blade.php`: keep `<picture>` when no variant is overridden; when any
  variant has its own image, render one `<img>` per breakpoint (each with its own alt,
  `loading="lazy"`) shown/hidden with responsive classes, since `<source>` cannot carry alt.
- Double-check `'full' => [3000, 100]` in `ImageBlock::variations()` (suspected typo).

## Testing (Pest)

Plugin:
- `Ratio::reduce()`; `CropBox` unchanged behavior.
- `PersistImagePicker`: create; update in place (same id); remove deletes row + files;
  regenerates only changed variants; main change regenerates non-overridden variants only;
  deletes undeclared variations; override + alt persisted; clamps focus/zoom; rejects
  missing media.
- Hydration + variation sync: new, removed and resized variations.
- `GenerateConversion`: uses override source; versioned file name; old file kept until
  swap then deleted; failure keeps previous file.
- `UploadMedia` stores dimensions; picking legacy media without dimensions measures them.
- `Media` deleting hook reverts overrides to main image and queues regeneration.
- Field (Livewire): pick main; upload-and-select; override variant; reset to main; change
  main keeps overrides; nothing persisted before save; Cancel/discard persists nothing;
  retry still works.
- Existing tests built on `PickMedia` / `updateAlt` / `updateFocus` are rewritten for the new
  flow, not dropped.

Host app:
- `ImageBlock` sources include `alt`; block renders per-breakpoint `<img>` when overridden
  and `<picture>` otherwise.

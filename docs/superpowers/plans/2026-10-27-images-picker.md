# ImagesPicker, no-crop default and value duplication — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Give filament-media-manager what the starter-pack media driver needs: no crop when no variations are set, render helpers, a way to duplicate a stored value for another language, and a simple multi-image `ImagesPicker`.

**Architecture:** Everything builds on the existing `MediaFieldValue` / `MediaConversion` rows. `ImagesPicker` stores an ordered list of `MediaFieldValue` ids and persists each image through the existing `PersistImagePicker`, so one image in a gallery is saved exactly like one `ImagePicker` value without overrides.

**Tech Stack:** PHP 8.2+, Laravel 13, Filament 5, Livewire 4, Pest 4 + Testbench.

**Spec:** `/Users/raphael/Documents/Dev/filament-packages/packages/filament-starter-pack/docs/superpowers/specs/2026-10-27-media-driver-design.md` (Part 1). This is plan 1 of 2; plan 2 (`filament-starter-pack/docs/superpowers/plans/2026-10-27-media-driver.md`) consumes the interfaces produced here.

## Global Constraints

- Repo: `packages/filament-media-manager`. Branch `feature/images-picker` off `feature/table-names`. Do not push.
- Run tests with `vendor/bin/pest --no-coverage` (no coverage driver installed). Baseline: 143 passed.
- `ImagePicker`'s stored value stays a single `MediaFieldValue` id; its behaviour changes only where this plan says (no-crop default).
- No variations = no crop: no `MediaConversion` rows, original image, source `Media::url()`.
- `ImagesPicker` has no per-image focus, zoom or override images.
- Removing an image deletes its `MediaFieldValue` row (and conversions), as `ImagePicker` does.
- Duplicating never touches the source rows.
- Run `vendor/bin/pint` before each commit; `composer analyse` must stay clean.

## Review Focus

- A gallery whose stored ids include one deleted from the library: hydration skips it, the rest still show in order (Task 4 test `skips ids whose row no longer exists`).
- Saving an `ImagesPicker` twice in one page visit must update rows, not create a second set (Task 4 test `persists twice without duplicating rows`, mirroring the `ImagePicker` write-back).
- Reordering only (no other change) must keep the same row ids in the new order (Task 4 test `reorders without recreating rows`).
- Duplicating a value whose conversion used an override image keeps the override `media_id` and alt (Task 3 test `copies override images and alt text`).
- `urlFor()` on a variation whose conversion is still pending falls back to the source URL instead of returning null (Task 2 test).

---

### Task 1: No variations means no crop

**Files:**
- Modify: `src/Forms/Components/ImagePicker.php` (`getVariations()`)
- Modify: `resources/views/forms/components/image-picker.blade.php` (only if it assumes at least one variation)
- Test: `tests/ImagePickerFieldTest.php`, `tests/PersistImagePickerTest.php`, `tests/ImagePickerViewTest.php`

**Interfaces:**
- Produces: `ImagePicker::getVariations(): array` returns `[]` when none are configured (was `['default' => [1200, 1200]]`).

- [ ] **Step 1: Replace the fallback test**

In `tests/ImagePickerFieldTest.php` replace `falls back to a single default variation` with:

```php
it('has no variations unless configured', function () {
    expect(ImagePicker::make('hero')->getVariations())->toBe([]);
});
```

Add to `tests/PersistImagePickerTest.php`:

```php
it('creates no conversions when the field has no variations', function () {
    Queue::fake();
    $media = Media::factory()->create();

    $id = app(PersistImagePicker::class)->execute(['id' => null, 'media_id' => $media->id, 'alt' => 'A', 'variants' => []], []);

    expect(MediaFieldValue::find($id)->conversions)->toHaveCount(0);
    Queue::assertNothingPushed();
});
```

Add to `tests/ImagePickerViewTest.php` a test that renders an `ImagePicker` with no variations and a chosen image and asserts it renders without error and shows no variant cards (use the same harness/assertion style as the existing view tests).

- [ ] **Step 2: Run** `vendor/bin/pest --no-coverage --filter="no variations|has no variations"` — expect the field test to FAIL.
- [ ] **Step 3: Implement** — `getVariations()` returns `$this->variations`. Fix the view if it breaks with an empty list.
- [ ] **Step 4: Run** the full suite. Fix other tests that relied on the 1200×1200 default by giving them explicit `->variations()` (do not change what they assert).
- [ ] **Step 5: Commit** `feat: no variations means no crop`

---

### Task 2: Render helpers on `MediaFieldValue`

**Files:**
- Modify: `src/Models/MediaFieldValue.php`
- Test: `tests/MediaFieldValueModelTest.php`

**Interfaces:**
- Produces:
  - `MediaFieldValue::urlFor(?string $variation = null): ?string` — `$variation` null → `$this->url()` (source). Named variation with a conversion whose `url()` is non-null → that URL. Conversion missing, pending, failed or without a file → source `url()`.
  - `MediaFieldValue::altFor(?string $variation = null): ?string` — conversion's `effectiveAltText()` when that conversion exists, else `$this->alt_text`.

- [ ] **Step 1: Write failing tests** `returns the source url without a variation`, `returns the conversion url for a generated variation`, `falls back to the source url while the conversion is pending`, `falls back to the source url for an unknown variation`, `uses the override alt text of a variation`, `uses the value alt text without a variation`. Build rows with the existing factories; for "generated" set the conversion's `path` and `status` the way `tests/MediaConversionModelTest.php` does.
- [ ] **Step 2: Run** `--filter=MediaFieldValueModel` — FAIL (undefined method).
- [ ] **Step 3: Implement** both methods; use the loaded `conversions` relation (`conversion($name)`).
- [ ] **Step 4: Run** — PASS.
- [ ] **Step 5: Commit** `feat: url and alt helpers on field values`

---

### Task 3: Duplicate a stored value

**Files:**
- Create: `src/Actions/DuplicateMediaFieldValue.php`
- Test: `tests/DuplicateMediaFieldValueTest.php`

**Interfaces:**
- Produces: `DuplicateMediaFieldValue::execute(mixed $value, ?callable $mapAlt = null): mixed`
  - `int`/numeric string id → new id (`int`); `array` of ids → array of new ids in the same order; ids with no row are dropped; anything else (null, hydrated state arrays) → returned unchanged.
  - New `MediaFieldValue`: same `media_id`, `alt_text` (passed through `$mapAlt` when given and non-blank), `created_by = Auth::id()`.
  - One new `MediaConversion` per source conversion: same `variation`, `media_id`, `alt_text` (mapped like above), `width`, `height`, `focus_x`, `focus_y`, `zoom`; `status = Pending`, no file; `GenerateConversion::dispatchFor()` each.
  - `$mapAlt` signature: `fn (string $alt): string`.

- [ ] **Step 1: Write failing tests**
  - `duplicates a single value into a new row` — new id differs; new row's `media_id`/`alt_text` equal the source's.
  - `duplicates conversions and queues them` — `Queue::fake()`; same variations/focus/zoom; status Pending; `GenerateConversion` pushed once per conversion.
  - `copies override images and alt text` — source conversion with override `media_id` and `alt_text` keeps both.
  - `keeps the order of a list and drops missing ids` — `[$a, 999999, $b]` → two new ids, `$a`'s copy first.
  - `leaves the source rows untouched` — after duplicating then deleting the copy, the source and its conversions still exist.
  - `maps alt text through the callback` — `fn ($alt) => strtoupper($alt)` applied to value and override alt; null alt stays null.
  - `returns null and non-id values unchanged`.
- [ ] **Step 2: Run** `--filter=DuplicateMediaFieldValue` — FAIL.
- [ ] **Step 3: Implement**; load all source rows with `conversions` in one query.
- [ ] **Step 4: Run** — PASS.
- [ ] **Step 5: Commit** `feat: duplicate a field value for another owner`

---

### Task 4: `ImagesPicker` state and persistence

**Files:**
- Create: `src/Support/ImagesPickerState.php`
- Create: `src/Actions/PersistImagesPicker.php`
- Test: `tests/ImagesPickerStateTest.php`, `tests/PersistImagesPickerTest.php`

**Interfaces:**
- Produces:
  - State shape: `array{items: list<array{key: string, id: int|null, media_id: int, alt: string|null}>, removed: list<int>}`. `key` is a stable per-item string (`Str::uuid()` for new items, `"v{$id}"` for saved ones) used by the view for reordering.
  - `ImagesPickerState::fromStoredValue(mixed $stored): array` — list of ids → items in stored order, one query, missing rows skipped; already-hydrated array with `items` → returned as-is; anything else → `['items' => [], 'removed' => []]`.
  - `ImagesPickerState::withImage(array $state, Media $media): array` — appends `{key, id: null, media_id, alt: $media->alt_text}`.
  - `ImagesPickerState::withoutImage(array $state, string $key): array` — removes the item; a saved item's `id` goes to `removed`.
  - `ImagesPickerState::reordered(array $state, array $keys): array` — items in the given key order; unknown keys ignored, items not listed keep their relative order at the end.
  - `PersistImagesPicker::execute(mixed $state, array $variations): array` — returns `list<int>`. A plain list of ids (never hydrated) passes through as ints. Otherwise: each item → `PersistImagePicker::execute(['id' => $item['id'], 'media_id' => $item['media_id'], 'alt' => $item['alt'], 'variants' => []], $variations)`; each `removed` id → `PersistImagePicker::execute(['id' => $id, 'media_id' => null], $variations)` (deletes it).

- [ ] **Step 1: Write failing state tests**: `hydrates stored ids in order`, `skips ids whose row no longer exists`, `appends an image`, `removing a saved image remembers its id`, `removing a new image forgets it`, `reorders by key`.
- [ ] **Step 2: Write failing persist tests**: `creates one row per image in order`, `deletes removed rows and their conversions`, `reorders without recreating rows` (persist, hydrate, reorder, persist → same ids, new order), `persists twice without duplicating rows` (persist, rebuild state from returned ids, persist → same ids), `creates conversions for each variation`, `passes a plain list of ids through`.
- [ ] **Step 3: Run** `--filter=ImagesPicker` — FAIL.
- [ ] **Step 4: Implement** both classes.
- [ ] **Step 5: Run** — PASS.
- [ ] **Step 6: Commit** `feat: ImagesPicker state and persistence`

---

### Task 5: `ImagesPicker` field and view

**Files:**
- Create: `src/Forms/Components/ImagesPicker.php`
- Create: `resources/views/forms/components/images-picker.blade.php`
- Modify: `src/Livewire/MediaPickerBrowser.php` (stay open for `target === 'append'`)
- Test: `tests/ImagesPickerFormTest.php`, `tests/ImagesPickerViewTest.php`, `tests/MediaPickerBrowserTest.php`

**Interfaces:**
- Consumes: Task 4 (`ImagesPickerState`, `PersistImagesPicker`), existing `UploadMedia`, `MediaPickerBrowser`, `image-picker-modal` view.
- Produces: `ImagesPicker extends Field` with `variations(array $variations): static`, `getVariations(): array` (default `[]`), `disk(string)`, `getDisk()`; stored value `list<int>`.

Behaviour (mirror `ImagePicker`'s `setUp()` structure):
- `afterStateHydrated` → `ImagesPickerState::fromStoredValue()`.
- `dehydrateStateUsing` → `PersistImagesPicker::execute()`, then write `fromStoredValue($ids)` back into state (same reason as `ImagePicker`).
- Rule: `required()` fails with `validation.required` when `items` is empty; any item whose `media_id` no longer exists fails with `This image is no longer in the library.`
- Actions: reuse the `select` modal action (same view, `target` `'append'`). Exposed Livewire methods: `selectImage(string $target, int $mediaId)` appends; `storeUploadedImage(string $target)` uploads via `UploadMedia` then appends; `removeImage(string $key)`; `reorderImages(array $keys)`. Alt text is bound to `items.N.alt` in the view.
- `MediaPickerBrowser::select()` skips the `image-picker:close` dispatch when `$this->target === 'append'`, so editors can pick several images in one visit; the modal's own close button closes it.
- View: grid of thumbnails (use `Media::thumbnailUrl()`), each with an alt input and remove button; drag to reorder (Filament's `x-sortable` as used by its repeater) calling `reorderImages`; an "Add images" button opening the modal; empty-state text when no images. Listen for `image-picker:selected` the same way `image-picker.blade.php` does.

- [ ] **Step 1: Write failing form tests** using a harness like `ImagePickerFormHarness` with `ImagesPicker::make('gallery')->variations(['tile' => [800, 450]])`:
  `selects several images and saves them in order`, `uploads an image into the list`, `removes an image and deletes its row on save`, `reorders images`, `fails required with no images`, `fails when an image was deleted from the library`, `saves alt text per image`.
- [ ] **Step 2: Write failing view test** `renders a thumbnail per image and an add button`, and browser test `keeps the browser open when appending` (no `image-picker:close` for target `append`; still dispatched for `main`).
- [ ] **Step 3: Run** `--filter="ImagesPicker|MediaPickerBrowser"` — FAIL.
- [ ] **Step 4: Implement** field, view and browser change.
- [ ] **Step 5: Run** the full suite — PASS.
- [ ] **Step 6: Manual check** in a sandbox panel: add, reorder, remove, edit alt, save, reload.
- [ ] **Step 7: Commit** `feat: ImagesPicker for several images`

---

### Task 6: Docs

**Files:** Modify `README.md`, `CHANGELOG.md`.

- [ ] **Step 1:** Document `ImagesPicker`, the no-crop default (breaking: fields that relied on the implicit 1200×1200 crop must now pass `->variations()`), `urlFor()`/`altFor()`, and `DuplicateMediaFieldValue`.
- [ ] **Step 2: Commit** `docs: ImagesPicker and no-crop default`

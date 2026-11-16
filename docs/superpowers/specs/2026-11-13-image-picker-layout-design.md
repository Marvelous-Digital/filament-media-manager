# Image picker layout: one preview, variants on top

Date: 2026-11-13. Status: draft, awaiting approval.

## Why

The `ImagePicker` form field (`resources/views/forms/components/image-picker.blade.php`) renders one card per variation, each with a name, a thumbnail, a status line, four buttons, an optional alt field and a Reframe panel that opens inline. With four or more variations this becomes a long wall of repeated controls. Editors don't see at a glance what each crop looks like, and it isn't clear what they can do.

The new layout: pick one base image, see every variation's crop in a strip of small previews above one large preview, and click a variation to reframe it there.

## Goals

- One large preview (the "stage") for the whole field, with the variations shown as a strip of live crop thumbnails above it (option B of the mockups).
- Clicking a thumbnail selects that variation. The stage then shows its crop frame over its full source image, and the editor drags to move it and zooms with a slider.
- Less on screen. A variation shows its state only when something needs attention. "Ready" and "Generating…" are no longer printed under every variation.
- **Using another image for one variation stays a first-class action**, visible on the selected variation's toolbar and not hidden in a menu (user's explicit requirement). The same goes for uploading a file for that variation and going back to the base image.

## Non-goals

- The media library page and its edit slide-over (`src/Pages/MediaLibrary.php`, `media-details.blade.php`) are unchanged. The user chose to scope this to the field only.
- `ImagesPicker` (galleries) and `DocumentPicker` are unchanged. They have no variations.
- No change to the PHP API (`ImagePicker::make()->variations([...])`, `disk()`), the state shape (`ImagePickerState`), storage (`media_manager_field_values`, `media_manager_conversions`), the crop model (focus point + zoom, `CropBox`) or conversion generation. This is a view, CSS and translation-string change.

## Anti-goals

- The per-variation image disappearing or becoming hard to find.
- A layout that needs more clicks than today to reframe a variation. Today it takes 1 click (Reframe); it must stay at 1 (click the thumbnail).
- Breaking projects that already use the field: existing saved values must render and save the same.

## Layout

Top to bottom, once an image is chosen:

1. **Header row:** a row of its own at the top of the field body, under Filament's label. It stays inside the view, with no hint actions and no PHP, so it also shows when a parent hides the label (e.g. the visual editor inspector). On its right is a ⋯ menu (Filament dropdown) with:
   - Change base image (opens the library modal, target `main`),
   - Upload new base image,
   - Remove (danger).

   These replace today's Change / Upload new / Remove buttons under the preview.
2. **Variation strip:** first a **Base** entry, then one thumbnail per variation in the order they are declared.
   - **Base** shows the whole base image, uncropped, labelled "Base". Selecting it puts the base image on the stage with no frame, focus marker or drag, and shows no variation toolbar. It exists so the base image is always visible, even when the selected variation uses its own image: the base alt field and the ⋯ menu act on it (user's decision).
   - Each is a button showing the live crop (same rendering as today's `previewStyle()`) at the variation's real aspect ratio, all at the same height (about 48px). Its name sits under it.
   - The selected one has a primary-colour ring and `aria-pressed="true"`.
   - When there are more variations than fit, the strip scrolls horizontally. It does not wrap.
   - Per-thumbnail indicators, only when relevant:
     - **Own image badge:** a small icon in a corner when the variation uses its own image.
     - **Attention dot (red):** the conversion failed, or the crop is zoomed past the source resolution (`overZoomed()`). Its tooltip (`title`) says which.
     - **Generating:** a subtle spinner overlay on the thumbnail while the conversion is pending. The existing `wire:poll.3s` stays.
     - Ready shows nothing.
3. **Stage:** the full source image of the selected variation (its own image if it has one, else the base image). Its crop frame is drawn as a rectangle, with the area outside dimmed, plus the focus marker.
   - Dragging anywhere on the stage moves the focus point, as today's reframe panel does (pointer capture, clamped 0–1).
   - When the source has no known dimensions (`hasSize()` false), the frame is hidden and only the focus marker shows, as today.
4. **Selected variation toolbar**, directly under the stage:
   - The variation name, ratio and output size (e.g. "Desktop · 16:9 · 1600×900").
   - The zoom slider (1–5, step 0.1) with its value, and the over-zoom warning text when it applies.
   - Reset: focus back to 0.5/0.5, zoom back to 1.
   - **Use another image for {name}**: opens the library modal with this variation as the target.
   - **Upload for {name}**: uploads straight to this variation.
   - Only when the variation has its own image: **Back to base image** (`useMainImage`), and its own alt field ("Alt text for {name}").
   - Only when the conversion failed: **Retry** (the existing `retryConversion` action).
5. **Alt text** for the base image (unchanged field, `state.alt`).
6. The **unsaved notice**, unchanged.

The empty state (no image yet: Upload / Pick from library buttons plus the list of required formats) is unchanged.

**No variations** (`ImagePicker::make()` without `->variations()`, a supported configuration): the header row with its ⋯ menu, the stage as a plain preview of the base image (no frame, marker or drag), the base alt field and the unsaved notice. There is no strip and no toolbar. The Alpine code must never read `state.variants[...]` without a selected variation.

The selection defaults to the first variation, not Base, so the first crop is ready to reframe without a click. It is UI-only state (Alpine) and is not saved.

## Constraints

- Filament 5 + Livewire 4 + Alpine, no JS crop library (none is used today). Keep the existing Alpine helpers (`sourceOf`, `cropBox`, `previewStyle`, `cropRectStyle`, `overZoomed`, `setFocus`) and the Livewire calls (`selectImage`, `storeUploadedImage`, `useMainImage`, `removeImage`, `mountAction('select'|'retryConversion')`).
- Works in light and dark mode with Filament's colour tokens. The field must work in a narrow column (side panel, inspector): the stage scales to the container width and the strip scrolls.
- Every new or changed string goes through `filament-media-manager::media.picker.*` with both en and fr (the FSP-43 translations guard must stay green).
- The field is used by `marvelous-digital/filament-starter-pack` (MediaManagerDriver) in both the classic Builder and the visual editor inspector. It must keep working in both. The visual editor sends field views through Livewire morphs, so `wire:key`s stay stable per state path and variation.

## Tests

- `tests/ImagePickerViewTest.php` asserts today's labels ("Reframe", "Use different image", "Ready"…). Update it to the new structure: strip thumbnails per variation, toolbar actions present, Back to base image / own alt only for overridden variations, Retry only for failed, spinner/poll for pending, no "Ready" text.
- All other picker tests (field, form, state, persist) must pass unchanged. They prove the behaviour didn't move.

## Mockup

`.superpowers/brainstorm/63620-1794560941/content/image-field-layout.html` at the workbench root, option B (local file, not in the repo).

## Left to the builder

- Exact sizes, spacing and icons (Heroicons), within Filament's look.
- Whether the dimmed outside of the frame is a box-shadow or an SVG mask.
- Keyboard: thumbnails are real buttons (Tab plus Enter/Space selects). Arrow-key nudging of the crop is optional.

## Calls I made (not discussed with the user)

- The base image's Change / Upload new / Remove move into a ⋯ menu, on the field's own header row rather than Filament's label row (that keeps it view-only and visible when the label is hidden).
- With no variations, the stage is a plain base-image preview with no strip or toolbar.
- The strip thumbnail height is about 48px, with horizontal scroll and no wrap.
- Pending shows a spinner on the thumbnail instead of text; ready shows nothing.
- The first variation (not Base) is selected by default, and the selection isn't saved.
- With no variations there is no strip, so no Base entry either: the stage already shows the base image.
- No PHP or state changes.

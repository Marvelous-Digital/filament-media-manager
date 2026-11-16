# Changelog

All notable changes to `filament-media-manager` will be documented in this file.

## Unreleased

- `ImagesPicker`: the remove button works again (its handler reached the browser uncompiled), and each alt text uses Filament's input component.
- `ImagePicker` base focus point: select Base in the strip and drag to set the focus point every variation follows. Moving a variation on its own makes it custom (crosshair badge on its thumbnail); "Use base focus point" puts it back on the base point and zoom 1. Variations with their own image keep their own focus and a Reset button. New migration `update_media_field_tables_for_base_focus` (`focus_x`/`focus_y` on field values, `custom_focus` on conversions; conversions already moved off the centre or using their own image are marked custom, so no crop shifts): existing installs publish it with `--tag=filament-media-manager-migrations` and migrate. Form state gains `focus_x`/`focus_y` and a per-variant `custom` flag.
  - Translations: added `picker.use_base_focus`, `follows_base_focus`, `own_focus`, `base_focus_hint`.
- `ImagePicker` alt texts (base and per-variation override) use Filament's input and field label components.
- `ImagePicker` shows one alt text field at a time: a variation with its own image shows only its own field (pre-filled with, and placeholder showing, that image's library alt text); the base alt shows for the base and the variations using it. Translation `picker.alt_text_for` removed.
- `MediaConversion::effectiveAltText()` (and so `MediaFieldValue::altFor()`): a variation with its own image and no alt text of its own now falls back to that image's library alt text before the field's main alt text.
- `ImagePicker` "Decorative image" toggle: the image is shown with an empty alt text so screen readers skip it (`altFor()` and `effectiveAltText()` return `''` for every variation); the alt fields hide while it is on, and the typed alt texts are kept for when it is turned off. New migration `update_media_field_values_for_decorative` (`decorative` on field values); form state gains `decorative`. Translations: added `picker.decorative`, `decorative_hint`.
- `ImagePicker` field layout: one stage with a strip of live crop thumbnails (Base first) replaces the per-variation cards. Click a thumbnail to reframe it on the stage; its toolbar holds zoom, Reset, Use another image / Upload for this format, Back to base image and Retry. Failed or over-zoomed variations show a red dot, generating ones a spinner. The base image's Change / Upload new / Remove moved to a ⋯ menu. No API or stored-data change.
  - Translations: added `picker.more_actions`, `change_base`, `upload_new_base`, `base`, `conversion_failed`, `over_zoomed`, `reset`, `use_another_image_for`, `upload_for`, `back_to_base`, `alt_text_for`; removed `picker.upload_new`, `ready`, `reframe`, `use_different_image`, `upload_for_format`, `use_main_image`, `alt_text_for_format`.
- Translations: English + French; publish with `--tag=filament-media-manager-translations`. All interface text now lives in `resources/lang/{en,fr}/media.php` under the `filament-media-manager::media.*` keys, replacing the former English-sentence keys.
- `DocumentPicker` form component: pick one file of given `->mimeTypes()` from the library, stored as a `Media` id. `MediaPickerBrowser` takes `mimeTypes` (empty = images only), and now refuses to select a media outside what it lists.
- `ImagesPicker` form component: pick several images in a sortable grid with per-image alt text and optional `->variations()`, stored as an ordered array of `MediaFieldValue` ids.
- `MediaFieldValue::urlFor()` and `altFor()`: URL and alt text of a variation, falling back to the original image and main alt text.
- `DuplicateMediaFieldValue` action: copies stored field values (single id or list) and their conversions into new rows, with an optional alt-text mapping callback.
- `ImagePicker` no longer crops by default: without `->variations()` there are no variations, no conversions and the original image is used. **Breaking** for fields that relied on the implicit `default` 1200x1200 crop: the next save of such a field deletes its existing `default` conversion and file, so `->conversion('default')?->url()` returns `null` from then on. Keep the old crop with `->variations(['default' => [1200, 1200]])`, or read images with `urlFor('default')`, which falls back to the original.
- Picker saves only act on rows the server handed to the form: saved ids in the form state carry a signed token, and an id the browser made up or changed is saved as a new row instead of updating or deleting someone else's. In `ImagesPicker` state, `removed` now holds `{id, token}` entries instead of bare ids.
- Configurable table names (`table_names` config key), prefixed with `media_manager_` by default so the package no longer clashes with `spatie/laravel-medialibrary`'s `media` table. **Breaking** for existing installs: keep the old names by setting them in the published config, or rename the tables.
- The media page is served at `/{panel}/media-manager` instead of `/{panel}/media-library`, so it no longer takes over the route of `ralphjsmit/laravel-filament-media-library`'s page.

## v0.2.0 - 2026-07-23

- `ImagePicker` form component: lets a resource select a single image from the media library (or upload one and select it) without copying the underlying file, via a `MediaFieldValue` usage record referencing the shared original `Media`.
- Per-variation focus points: each declared size variation gets its own contributor-set focus point, used to crop that variation's conversion.
- Generated conversions: one `MediaConversion` per named variation declared via `->variations([...])`, generated asynchronously by a queued job reusing the existing `thumbnail.*` config knobs (queue, queue name, format, quality).

## v0.1.0 - 2026-07-17

Initial release.

- Finder-style "Media Library" Filament page with breadcrumb navigation, nested folders, search, and "Load more" pagination.
- Drag-and-drop multi-file upload with per-file success/failure isolation.
- Uploaded originals are stored untouched at `{directory}/{ulid}/{filename}`.
- Automatic WebP thumbnail generation via Laravel's `Image` facade (Laravel 13.20+), dispatched to the queue by default or run synchronously via `thumbnail.queue`, with retry from the media detail view on failure.
- Folder management: create, rename, and delete (when empty), with unique names enforced per folder level.
- Media detail slide-over: edit name and alt text, move to a different folder, download the original, delete.
- Bulk selection with move and delete actions.
- Configurable disk, directory, accepted mime types, max upload size, thumbnail settings, and grid page size via `config/filament-media-manager.php`.

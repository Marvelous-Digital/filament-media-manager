# Changelog

All notable changes to `filament-media-manager` will be documented in this file.

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

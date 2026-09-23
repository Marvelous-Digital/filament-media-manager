# Filament Media Manager

[![Latest Version on Packagist](https://img.shields.io/packagist/v/marvelous-digital/filament-media-manager.svg?style=flat-square)](https://packagist.org/packages/marvelous-digital/filament-media-manager)
[![GitHub Tests Action Status](https://img.shields.io/github/actions/workflow/status/marvelous-digital/filament-media-manager/run-tests.yml?branch=main&label=tests&style=flat-square)](https://github.com/marvelous-digital/filament-media-manager/actions?query=workflow%3Arun-tests+branch%3Amain)
[![GitHub Code Style Action Status](https://img.shields.io/github/actions/workflow/status/marvelous-digital/filament-media-manager/fix-php-code-style-issues.yml?branch=main&label=code%20style&style=flat-square)](https://github.com/marvelous-digital/filament-media-manager/actions?query=workflow%3A"Fix+PHP+code+styling"+branch%3Amain)
[![Total Downloads](https://img.shields.io/packagist/dt/marvelous-digital/filament-media-manager.svg?style=flat-square)](https://packagist.org/packages/marvelous-digital/filament-media-manager)

A media library plugin for Filament v5: a Finder-style page for uploading, browsing and organizing media in nested folders. Originals are stored untouched, and lightweight WebP thumbnails are generated automatically for browsing.

## Requirements

- PHP 8.2+
- Laravel 13.20+
- Filament 5
- GD or Imagick (used via [intervention/image](https://image.intervention.io/) ^4.0, a dependency of this package, and Laravel's first-party `Image` facade)

## Installation

Install the package via composer:

```bash
composer require marvelous-digital/filament-media-manager
```

Run the install command. It publishes the config file and the migrations, and offers to run the migrations for you:

```bash
php artisan filament-media-manager:install
```

Register the plugin on any Filament panel that should have a media library:

```php
use MarvelousDigital\FilamentMediaManager\MediaManagerPlugin;

public function panel(Panel $panel): Panel
{
    return $panel
        // ...
        ->plugin(MediaManagerPlugin::make());
}
```

Publish the plugin's Filament assets:

```bash
php artisan filament:assets
```

If you use the default `public` disk (or any other disk that needs a symlink into `public/`), make sure it's linked:

```bash
php artisan storage:link
```

The plugin adds a "Media Library" page to the panel's navigation.

## Configuration

The install command publishes `config/filament-media-manager.php`:

```php
return [

    // Filesystem disk where originals and thumbnails are stored.
    'disk' => env('FILAMENT_MEDIA_MANAGER_DISK', 'public'),

    // Base directory on the disk. Each media item lives in {directory}/{ulid}/.
    'directory' => 'media',

    // Allowed mime types for upload. null accepts any file type.
    'accepted_mime_types' => null,

    // Maximum upload size in kilobytes.
    'max_file_size' => 51200,

    'thumbnail' => [
        'width' => 600,
        'format' => 'webp',
        'quality' => 70,
        // true: dispatch GenerateThumbnail to the queue; false: run synchronously during upload.
        'queue' => true,
        // Queue name to dispatch to (null = default queue).
        'queue_name' => null,
    ],

    // Grid items loaded per "Load more" chunk.
    'per_page' => 30,
];
```

- **`disk`** — any Laravel filesystem disk. Defaults to `public` via the `FILAMENT_MEDIA_MANAGER_DISK` environment variable.
- **`directory`** — the root folder on the disk under which all media lives. Each uploaded file keeps its original filename inside a per-item ULID directory: `{directory}/{ulid}/{filename}`. Uploads are never renamed, re-encoded, or otherwise modified.
- **`accepted_mime_types`** — an array of mime types to restrict uploads to, or `null` to accept any file type. **Security note:** the default `null` accepts any file type, including HTML and SVG. On the default `public` disk, uploaded files are served from the app's own origin, so an uploaded HTML or SVG file can execute script in the context of your app. If the media library is reachable by non-trusted users, configure an explicit allowlist, e.g.:
  ```php
  'accepted_mime_types' => [
      'image/jpeg',
      'image/png',
      'image/webp',
      'video/mp4',
      'application/pdf',
  ],
  ```
- **`max_file_size`** — maximum upload size per file, in kilobytes.
- **`thumbnail.width`** — target width in pixels for generated thumbnails (height is scaled proportionally).
- **`thumbnail.format`** — image format for thumbnails; defaults to `webp`.
- **`thumbnail.quality`** — encoding quality (0-100) passed to the image encoder.
- **`thumbnail.queue`** — when `true` (the default), thumbnail generation is dispatched to the queue after upload, so the request returns immediately and the grid shows a pending state until the job finishes. Set to `false` to generate the thumbnail synchronously during the upload request instead — useful for local development without a queue worker running, or for a `sync` queue connection.
- **`thumbnail.queue_name`** — the queue name the `GenerateThumbnail` job is dispatched to. `null` uses the connection's default queue.
- **`per_page`** — number of items fetched per "Load more" click in the media grid.

## Usage

Open the "Media Library" page from the panel navigation. From there you can:

- **Browse** nested folders via a breadcrumb trail, and search media by name within the current folder.
- **Upload** files by dragging them onto the grid, or via the upload button. Multiple files can be uploaded at once; each file succeeds or fails independently, so one bad file doesn't block the rest.
- **Organize** media into folders — create, rename, and delete folders (a folder must be empty before it can be deleted), with unique names enforced per folder level.
- **Inspect and edit** any media item in a detail slide-over: rename it, edit its alt text, move it to a different folder, download the original, retry thumbnail generation if it failed, or delete it.
- **Bulk-select** multiple items in the grid to move or delete them together.

Thumbnails are generated via Laravel's built-in `Image` facade (introduced in Laravel 13.20) and encoded as WebP by default. Because generation is queued by default, make sure a queue worker is running (`php artisan queue:work`) in production, or set `thumbnail.queue` to `false` if you'd rather generate thumbnails synchronously on upload.

## ImagePicker field

Besides the Media Library page, the package ships a `ImagePicker` form component so any Filament resource can let a user pick a single image from the media library (or upload a new one and select it), without copying the underlying file.

The field shows the formats it needs (as ratios) before anything is picked. Admins upload a new image or pick one from the library, reframe each variation with a focus point and zoom, and can give any variation its own image (with its own focus, zoom and alt text). **Nothing is written until the form is saved**; cancelling discards all image edits. On save the field stores a `MediaFieldValue` id, updating the existing record in place, and regenerates only the variations whose framing changed. The previous crop keeps being served until its replacement is ready. Generation uses the `thumbnail.*` config knobs (`thumbnail.queue`, `thumbnail.queue_name`, `thumbnail.format`, `thumbnail.quality`), so run a queue worker in production or set `thumbnail.queue` to `false`.

Add a nullable foreign key on the parent model's table, pointing at `media_field_values`:

```php
$table->foreignId('hero_image_id')->nullable()->constrained('media_field_values')->nullOnDelete();
```

Define a `belongsTo` relation on the model:

```php
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use MarvelousDigital\FilamentMediaManager\Models\MediaFieldValue;

public function heroImage(): BelongsTo
{
    return $this->belongsTo(MediaFieldValue::class, 'hero_image_id');
}
```

Add the field to a form, declaring the named size variations you need (width and height in pixels):

```php
use MarvelousDigital\FilamentMediaManager\Forms\Components\ImagePicker;

ImagePicker::make('hero_image_id')
    ->variations([
        'hero'  => [1600, 600],
        'card'  => [800, 600],
        'thumb' => [400, 400],
    ]);
```

If `->variations()` is omitted, a single implicit `default` variation of `[1200, 1200]` is used. By default the field stores files on `config('filament-media-manager.disk')`; override per-field with `->disk('public')`.

Consume the picked image wherever you render the parent model:

```php
$post->heroImage?->conversion('hero')?->url();               // a named conversion
$post->heroImage?->conversion('hero')?->effectiveAltText();  // variation alt (own image) or main alt
$post->heroImage?->alt_text;                                  // main alt text
$post->heroImage?->url();                                     // the original, unconverted main image
```

## Roadmap

The following are intentionally out of scope for this first release and may be added later:

- Video thumbnail generation (video files are currently stored and typed but no thumbnail is generated for them).

## Testing

```bash
composer test
```

## Changelog

Please see [CHANGELOG](CHANGELOG.md) for more information on what has changed recently.

## Contributing

Please see [CONTRIBUTING](.github/CONTRIBUTING.md) for details.

## Security Vulnerabilities

Please review [our security policy](.github/SECURITY.md) on how to report security vulnerabilities.

## Credits

- [Raphael Baumann](https://github.com/marvelous-digital)
- [All Contributors](../../contributors)

## License

The MIT License (MIT). Please see [License File](LICENSE.md) for more information.

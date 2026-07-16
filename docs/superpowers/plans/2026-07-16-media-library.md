# Media Library Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build v1 of `marvelous-digital/filament-media-manager`: a custom Livewire media-browser page for a Filament v5 panel with nested folders, multi-file upload of untouched originals, WebP preview thumbnails via Laravel's first-party `Image` facade, and name/alt-text editing.

**Architecture:** One Filament full-page Livewire component (`MediaLibrary`) renders a Finder-style grid from two Eloquent models (`Media`, `MediaFolder`). All modals (folder CRUD, media edit, bulk ops) are Filament Actions on the page. Uploads go through Livewire temporary uploads into an `UploadMedia` action class that stores the byte-for-byte original and dispatches a `GenerateThumbnail` job (queued or sync per config).

**Tech Stack:** PHP 8.2+, Filament ^5.0, Laravel ^13.20 (`Illuminate\Support\Facades\Image`), intervention/image ^4.0 (GD driver in tests), Pest + Orchestra Testbench, Livewire 3.

**Spec:** `docs/superpowers/specs/2026-07-16-media-library-design.md`

## Global Constraints

- Package name `marvelous-digital/filament-media-manager`, namespace `MarvelousDigital\FilamentMediaManager`, class-name base `MediaManager`.
- Config file: `config/filament-media-manager.php`; view namespace `filament-media-manager`.
- Originals are stored **byte-for-byte, never re-encoded**, at `{directory}/{ulid}/{filename}`; thumbnail at `{directory}/{ulid}/thumb.webp`.
- Laravel framework floor is `^13.20` (first-party image API). Intervention floor `^4.0`.
- Tables are named `media_folders` and `media` exactly as in the spec.
- Deleting a folder is only allowed when it has no subfolders and no media (v1).
- Deleting media must never fail because files are missing on disk.
- Run all tests with `vendor/bin/pest`; lint with `vendor/bin/pint --dirty` before each commit.
- Filament v5 note: if `Action::make()->schema([...])` is not a valid method in the installed Filament version, use `->form([...])` — check `vendor/filament/actions/src/Action.php` once and use whichever exists, consistently.

---

### Task 1: Configure the package skeleton

Turn the unconfigured skeleton into `marvelous-digital/filament-media-manager`. No feature code yet; deliverable is a renamed, dependency-complete package whose (example) test suite passes.

**Files:**
- Modify: `composer.json`, `src/SkeletonServiceProvider.php` (→ rename), `src/SkeletonPlugin.php` (→ rename), `tests/TestCase.php`, `tests/Pest.php`, `config/skeleton.php` (→ rename), `phpstan.neon.dist`
- Create: `tests/Fixtures/User.php`, `tests/Fixtures/AdminPanelProvider.php`
- Delete: `configure.php`, `src/SkeletonTheme.php`, `src/Skeleton.php`, `src/Commands/`, `src/Facades/`, `src/Testing/`, `stubs/`, `database/migrations/create_skeleton_table.php.stub`, `database/factories/ModelFactory.php`, `resources/js/`, `resources/lang/`

**Interfaces:**
- Consumes: nothing (first task).
- Produces: namespace `MarvelousDigital\FilamentMediaManager`; classes `MediaManagerServiceProvider`, `MediaManagerPlugin` (with `MediaManagerPlugin::make()`); test base `MarvelousDigital\FilamentMediaManager\Tests\TestCase` with a booted `admin` panel, an authenticated `Tests\Fixtures\User`, and `Storage::fake('public')` NOT yet applied (individual tests fake disks). Config accessible as `config('filament-media-manager.*')`.

- [ ] **Step 1: Delete unused skeleton files**

```bash
cd /Users/raphael/filament-media-manager
rm -f configure.php src/SkeletonTheme.php src/Skeleton.php
rm -rf src/Commands src/Facades src/Testing stubs resources/js resources/lang
rm -f database/migrations/create_skeleton_table.php.stub database/factories/ModelFactory.php
```

- [ ] **Step 2: Rewrite composer.json**

Replace the whole file with:

```json
{
    "name": "marvelous-digital/filament-media-manager",
    "description": "A media library plugin for Filament: upload and organize high-fidelity media in nested folders.",
    "keywords": ["marvelous-digital", "laravel", "filamentphp", "filament", "filament-plugin", "filament-media-manager"],
    "homepage": "https://github.com/marvelous-digital/filament-media-manager",
    "support": {
        "issues": "https://github.com/marvelous-digital/filament-media-manager/issues",
        "source": "https://github.com/marvelous-digital/filament-media-manager"
    },
    "license": "MIT",
    "authors": [
        {
            "name": "Raphael Baumann",
            "email": "raphael.baumann@marvelous.digital",
            "role": "Developer"
        }
    ],
    "require": {
        "php": "^8.2",
        "filament/filament": "^5.0",
        "intervention/image": "^4.0",
        "laravel/framework": "^13.20",
        "spatie/laravel-package-tools": "^1.15.0"
    },
    "require-dev": {
        "larastan/larastan": "^3.0",
        "laravel/pint": "^1.0",
        "nunomaduro/collision": "^8.0",
        "orchestra/testbench": "^9.0|^10.0|^11.0",
        "pestphp/pest": "^3.7|^4.0",
        "pestphp/pest-plugin-arch": "^3.0|^4.0",
        "pestphp/pest-plugin-laravel": "^3.0|^4.0",
        "pestphp/pest-plugin-livewire": "^3.0|^4.0",
        "rector/rector": "^2.0",
        "spatie/laravel-ray": "^1.26"
    },
    "autoload": {
        "psr-4": {
            "MarvelousDigital\\FilamentMediaManager\\": "src/",
            "MarvelousDigital\\FilamentMediaManager\\Database\\Factories\\": "database/factories/"
        }
    },
    "autoload-dev": {
        "psr-4": {
            "MarvelousDigital\\FilamentMediaManager\\Tests\\": "tests/"
        }
    },
    "scripts": {
        "post-autoload-dump": "@php ./vendor/bin/testbench package:discover --ansi",
        "analyse": "phpstan analyse",
        "lint": "pint",
        "refactor": "rector",
        "test": "pest",
        "test:lint": "pint --test",
        "test:refactor": "rector --dry-run"
    },
    "extra": {
        "laravel": {
            "providers": [
                "MarvelousDigital\\FilamentMediaManager\\MediaManagerServiceProvider"
            ]
        }
    },
    "config": {
        "sort-packages": true,
        "allow-plugins": {
            "composer/package-versions-deprecated": true,
            "pestphp/pest-plugin": true,
            "phpstan/extension-installer": true
        }
    },
    "minimum-stability": "dev",
    "prefer-stable": true
}
```

Note: if `composer update` later fails resolving `laravel/framework ^13.20` against the installed Filament/testbench versions, widen the testbench constraint (add the next major) rather than lowering the framework floor — the `Image` facade requires 13.20.

- [ ] **Step 3: Create the service provider**

`git mv src/SkeletonServiceProvider.php src/MediaManagerServiceProvider.php`, then replace its contents:

```php
<?php

namespace MarvelousDigital\FilamentMediaManager;

use Filament\Support\Assets\Css;
use Filament\Support\Facades\FilamentAsset;
use Spatie\LaravelPackageTools\Commands\InstallCommand;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;

class MediaManagerServiceProvider extends PackageServiceProvider
{
    public static string $name = 'filament-media-manager';

    public static string $viewNamespace = 'filament-media-manager';

    public function configurePackage(Package $package): void
    {
        $package->name(static::$name)
            ->hasConfigFile()
            ->hasViews(static::$viewNamespace)
            ->hasMigrations(['create_filament_media_manager_tables'])
            ->hasInstallCommand(function (InstallCommand $command) {
                $command
                    ->publishConfigFile()
                    ->publishMigrations()
                    ->askToRunMigrations();
            });
    }

    public function packageBooted(): void
    {
        FilamentAsset::register([
            Css::make('filament-media-manager-styles', __DIR__ . '/../resources/dist/filament-media-manager.css'),
        ], 'marvelous-digital/filament-media-manager');
    }
}
```

Also create empty placeholders so the provider paths exist: `mkdir -p database/migrations resources/views resources/dist && touch resources/dist/filament-media-manager.css` (the migration file arrives in Task 2 — until then `hasMigrations` referencing a missing file is tolerated by package-tools; if it throws, comment the `hasMigrations` line and re-enable it in Task 2).

- [ ] **Step 4: Create the plugin class**

`git mv src/SkeletonPlugin.php src/MediaManagerPlugin.php`, then replace its contents:

```php
<?php

namespace MarvelousDigital\FilamentMediaManager;

use Filament\Contracts\Plugin;
use Filament\Panel;
use MarvelousDigital\FilamentMediaManager\Pages\MediaLibrary;

class MediaManagerPlugin implements Plugin
{
    public function getId(): string
    {
        return 'filament-media-manager';
    }

    public function register(Panel $panel): void
    {
        $panel->pages([
            MediaLibrary::class,
        ]);
    }

    public function boot(Panel $panel): void
    {
        //
    }

    public static function make(): static
    {
        return app(static::class);
    }

    public static function get(): static
    {
        /** @var static $plugin */
        $plugin = filament(app(static::class)->getId());

        return $plugin;
    }
}
```

`MediaLibrary` doesn't exist until Task 5 — create a minimal placeholder now so the package boots:

Create `src/Pages/MediaLibrary.php`:

```php
<?php

namespace MarvelousDigital\FilamentMediaManager\Pages;

use Filament\Pages\Page;

class MediaLibrary extends Page
{
    protected string $view = 'filament-media-manager::media-library';

    public static function getNavigationLabel(): string
    {
        return 'Media';
    }

    public function getTitle(): string
    {
        return 'Media';
    }
}
```

(If the installed Filament version declares `$view` differently — e.g. `protected static string $view` — match the parent class. Check `vendor/filament/filament/src/Pages/Page.php` after `composer update`.)

Create `resources/views/media-library.blade.php`:

```blade
<x-filament-panels::page>
    <div>Media library</div>
</x-filament-panels::page>
```

- [ ] **Step 5: Rename config and rewrite it with the v1 keys**

`git mv config/skeleton.php config/filament-media-manager.php` with contents:

```php
<?php

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

- [ ] **Step 6: Update tests scaffolding**

`tests/Fixtures/User.php`:

```php
<?php

namespace MarvelousDigital\FilamentMediaManager\Tests\Fixtures;

use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Foundation\Auth\User as Authenticatable;

class User extends Authenticatable implements FilamentUser
{
    protected $table = 'users';

    protected $guarded = [];

    public function canAccessPanel(Panel $panel): bool
    {
        return true;
    }
}
```

`tests/Fixtures/AdminPanelProvider.php`:

```php
<?php

namespace MarvelousDigital\FilamentMediaManager\Tests\Fixtures;

use Filament\Panel;
use Filament\PanelProvider;
use MarvelousDigital\FilamentMediaManager\MediaManagerPlugin;

class AdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('admin')
            ->path('admin')
            ->login()
            ->plugin(MediaManagerPlugin::make());
    }
}
```

Rewrite `tests/TestCase.php`:

```php
<?php

namespace MarvelousDigital\FilamentMediaManager\Tests;

use BladeUI\Heroicons\BladeHeroiconsServiceProvider;
use BladeUI\Icons\BladeIconsServiceProvider;
use Filament\Actions\ActionsServiceProvider;
use Filament\Facades\Filament;
use Filament\FilamentServiceProvider;
use Filament\Forms\FormsServiceProvider;
use Filament\Infolists\InfolistsServiceProvider;
use Filament\Notifications\NotificationsServiceProvider;
use Filament\Schemas\SchemasServiceProvider;
use Filament\Support\SupportServiceProvider;
use Filament\Tables\TablesServiceProvider;
use Filament\Widgets\WidgetsServiceProvider;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Livewire\LivewireServiceProvider;
use MarvelousDigital\FilamentMediaManager\MediaManagerServiceProvider;
use MarvelousDigital\FilamentMediaManager\Tests\Fixtures\AdminPanelProvider;
use MarvelousDigital\FilamentMediaManager\Tests\Fixtures\User;
use Orchestra\Testbench\TestCase as Orchestra;
use RyanChandler\BladeCaptureDirective\BladeCaptureDirectiveServiceProvider;

class TestCase extends Orchestra
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Factory::guessFactoryNamesUsing(
            fn (string $modelName) => 'MarvelousDigital\\FilamentMediaManager\\Database\\Factories\\' . class_basename($modelName) . 'Factory'
        );

        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    protected function actingAsUser(): User
    {
        $user = User::forceCreate([
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => bcrypt('password'),
        ]);

        $this->actingAs($user);

        return $user;
    }

    protected function getPackageProviders($app)
    {
        return [
            ActionsServiceProvider::class,
            BladeCaptureDirectiveServiceProvider::class,
            BladeHeroiconsServiceProvider::class,
            BladeIconsServiceProvider::class,
            FilamentServiceProvider::class,
            FormsServiceProvider::class,
            InfolistsServiceProvider::class,
            LivewireServiceProvider::class,
            NotificationsServiceProvider::class,
            SchemasServiceProvider::class,
            SupportServiceProvider::class,
            TablesServiceProvider::class,
            WidgetsServiceProvider::class,
            MediaManagerServiceProvider::class,
            AdminPanelProvider::class,
        ];
    }

    public function getEnvironmentSetUp($app): void
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('image.default', 'gd');
    }

    protected function defineDatabaseMigrations(): void
    {
        $this->loadLaravelMigrations();
        $this->loadMigrationsFrom(__DIR__ . '/../database/migrations');
    }
}
```

Notes for the implementer: `WithWorkbench` was removed on purpose (no workbench dir exists). If `Filament::setCurrentPanel()` expects a string in the installed version, pass `'admin'` directly. If `Filament\Infolists\InfolistsServiceProvider` or others don't exist in Filament v5, remove the missing ones from the list — keep whichever of these providers exist in `vendor/filament/*`.

Update `tests/Pest.php`:

```php
<?php

use MarvelousDigital\FilamentMediaManager\Tests\TestCase;

uses(TestCase::class)->in(__DIR__);
```

- [ ] **Step 7: Sweep remaining placeholders**

```bash
grep -rn -i -E ":author|:vendor|:package|VendorName|skeleton" --include="*.php" --include="*.json" --include="*.neon*" --include="*.xml*" . --exclude-dir=vendor --exclude-dir=node_modules --exclude-dir=docs
```

Fix every hit (e.g. `phpstan.neon.dist`, `phpunit.xml.dist`, `.github/`, `package.json`, `README.md` — for README just fix the composer/name references; full docs come in Task 10).

- [ ] **Step 8: Install and verify**

```bash
composer update
vendor/bin/pest
```

Expected: install succeeds; ExampleTest + DebugTest pass. If `Filament::getPanel('admin')` fails during setUp, debug panel registration before proceeding — every later task depends on it.

- [ ] **Step 9: Commit**

```bash
vendor/bin/pint --dirty
git add -A && git commit -m "feat: configure package as marvelous-digital/filament-media-manager"
```

---

### Task 2: Migrations, enums, models, factories

**Files:**
- Create: `database/migrations/create_filament_media_manager_tables.php`, `src/Enums/MediaType.php`, `src/Enums/ThumbnailStatus.php`, `src/Models/Media.php`, `src/Models/MediaFolder.php`, `database/factories/MediaFactory.php`, `database/factories/MediaFolderFactory.php`
- Test: `tests/MediaTypeTest.php`, `tests/ModelsTest.php`

**Interfaces:**
- Consumes: Task 1 scaffolding.
- Produces:
  - `MediaType::fromMimeType(string $mime): MediaType` (cases `Image|Video|Document|Other`, string-backed `image|video|document|other`).
  - `ThumbnailStatus` string-backed enum, cases `Pending|Completed|Failed|Skipped` (`pending|completed|failed|skipped`).
  - `Media` model: fillable all columns; casts `type` → `MediaType`, `thumbnail_status` → `ThumbnailStatus`, `size` → int; `folder()` BelongsTo; `url(): ?string`; `thumbnailUrl(): ?string`; `directory(): string`; deletes its storage directory (best-effort) on model `deleted`.
  - `MediaFolder` model: `parent()`, `children()` (HasMany), `media()` (HasMany); `isEmpty(): bool`; `ancestors(): array` (root-first list of ancestor folders, excluding self); `static treeOptions(): array<int, string>` map of id → depth-indented name for selects.

- [ ] **Step 1: Write failing enum tests** (`tests/MediaTypeTest.php`)

```php
<?php

use MarvelousDigital\FilamentMediaManager\Enums\MediaType;

it('derives media type from mime type', function (string $mime, MediaType $expected) {
    expect(MediaType::fromMimeType($mime))->toBe($expected);
})->with([
    ['image/jpeg', MediaType::Image],
    ['image/svg+xml', MediaType::Image],
    ['video/mp4', MediaType::Video],
    ['application/pdf', MediaType::Document],
    ['text/plain', MediaType::Document],
    ['application/zip', MediaType::Other],
]);
```

- [ ] **Step 2: Run** `vendor/bin/pest tests/MediaTypeTest.php` — expect FAIL (class not found).

- [ ] **Step 3: Implement enums**

`src/Enums/MediaType.php`:

```php
<?php

namespace MarvelousDigital\FilamentMediaManager\Enums;

enum MediaType: string
{
    case Image = 'image';
    case Video = 'video';
    case Document = 'document';
    case Other = 'other';

    public static function fromMimeType(string $mimeType): self
    {
        return match (true) {
            str_starts_with($mimeType, 'image/') => self::Image,
            str_starts_with($mimeType, 'video/') => self::Video,
            str_starts_with($mimeType, 'text/'),
            in_array($mimeType, [
                'application/pdf',
                'application/msword',
                'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                'application/vnd.ms-excel',
                'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                'application/vnd.ms-powerpoint',
                'application/vnd.openxmlformats-officedocument.presentationml.presentation',
                'application/rtf',
            ], true) => self::Document,
            default => self::Other,
        };
    }
}
```

`src/Enums/ThumbnailStatus.php`:

```php
<?php

namespace MarvelousDigital\FilamentMediaManager\Enums;

enum ThumbnailStatus: string
{
    case Pending = 'pending';
    case Completed = 'completed';
    case Failed = 'failed';
    case Skipped = 'skipped';
}
```

- [ ] **Step 4: Run enum tests** — expect PASS. Commit: `git add -A && git commit -m "feat: add media type and thumbnail status enums"`

- [ ] **Step 5: Write failing model tests** (`tests/ModelsTest.php`)

```php
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
```

- [ ] **Step 6: Run** `vendor/bin/pest tests/ModelsTest.php` — expect FAIL.

- [ ] **Step 7: Implement migration, models, factories**

`database/migrations/create_filament_media_manager_tables.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('media_folders', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->foreignId('parent_id')->nullable()->constrained('media_folders')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['parent_id', 'name']);
        });

        Schema::create('media', function (Blueprint $table) {
            $table->id();
            $table->foreignId('folder_id')->nullable()->constrained('media_folders')->nullOnDelete();
            $table->string('name');
            $table->string('file_name');
            $table->string('disk');
            $table->string('path');
            $table->string('mime_type');
            $table->string('type');
            $table->unsignedBigInteger('size');
            $table->unsignedInteger('width')->nullable();
            $table->unsignedInteger('height')->nullable();
            $table->string('alt_text')->nullable();
            $table->string('thumbnail_path')->nullable();
            $table->string('thumbnail_status')->default('pending');
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('media');
        Schema::dropIfExists('media_folders');
    }
};
```

`src/Models/MediaFolder.php`:

```php
<?php

namespace MarvelousDigital\FilamentMediaManager\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MediaFolder extends Model
{
    use HasFactory;

    protected $fillable = ['name', 'parent_id'];

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    public function media(): HasMany
    {
        return $this->hasMany(Media::class, 'folder_id');
    }

    public function isEmpty(): bool
    {
        return ! $this->children()->exists() && ! $this->media()->exists();
    }

    /**
     * Ancestor folders, root first, excluding this folder.
     *
     * @return array<int, self>
     */
    public function ancestors(): array
    {
        $ancestors = [];
        $current = $this->parent;

        while ($current !== null) {
            array_unshift($ancestors, $current);
            $current = $current->parent;
        }

        return $ancestors;
    }

    /**
     * All folders as a flat, depth-indented map for selects.
     *
     * @return array<int, string>
     */
    public static function treeOptions(?int $excludeSubtree = null): array
    {
        $byParent = static::query()
            ->orderBy('name')
            ->get()
            ->groupBy('parent_id');

        $options = [];

        $walk = function (?int $parentId, int $depth) use (&$walk, &$options, $byParent, $excludeSubtree): void {
            foreach ($byParent->get($parentId, collect()) as $folder) {
                if ($folder->id === $excludeSubtree) {
                    continue;
                }

                $options[$folder->id] = str_repeat('— ', $depth) . $folder->name;
                $walk($folder->id, $depth + 1);
            }
        };

        $walk(null, 0);

        return $options;
    }
}
```

Note: `groupBy('parent_id')` groups nulls under key `''` in Laravel collections — inside `$walk`, fetch with `$byParent->get($parentId ?? '', ...)` if the null key lookup fails; verify with the tree-options test.

`src/Models/Media.php`:

```php
<?php

namespace MarvelousDigital\FilamentMediaManager\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;
use MarvelousDigital\FilamentMediaManager\Enums\MediaType;
use MarvelousDigital\FilamentMediaManager\Enums\ThumbnailStatus;
use Throwable;

class Media extends Model
{
    use HasFactory;

    protected $table = 'media';

    protected $fillable = [
        'folder_id', 'name', 'file_name', 'disk', 'path', 'mime_type', 'type',
        'size', 'width', 'height', 'alt_text', 'thumbnail_path', 'thumbnail_status', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'type' => MediaType::class,
            'thumbnail_status' => ThumbnailStatus::class,
            'size' => 'integer',
            'width' => 'integer',
            'height' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::deleted(function (self $media): void {
            try {
                Storage::disk($media->disk)->deleteDirectory($media->directory());
            } catch (Throwable) {
                // File cleanup is best-effort; never block row deletion.
            }
        });
    }

    public function folder(): BelongsTo
    {
        return $this->belongsTo(MediaFolder::class, 'folder_id');
    }

    public function directory(): string
    {
        return dirname($this->path);
    }

    public function url(): ?string
    {
        return $this->resolveUrl($this->path);
    }

    public function thumbnailUrl(): ?string
    {
        if ($this->mime_type === 'image/svg+xml') {
            return $this->url();
        }

        return $this->thumbnail_path ? $this->resolveUrl($this->thumbnail_path) : null;
    }

    protected function resolveUrl(string $path): ?string
    {
        $disk = Storage::disk($this->disk);

        try {
            return $disk->url($path);
        } catch (Throwable) {
            // Disk has no public URL support; fall through.
        }

        try {
            return $disk->temporaryUrl($path, now()->addMinutes(30));
        } catch (Throwable) {
            return null;
        }
    }
}
```

`database/factories/MediaFolderFactory.php`:

```php
<?php

namespace MarvelousDigital\FilamentMediaManager\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use MarvelousDigital\FilamentMediaManager\Models\MediaFolder;

class MediaFolderFactory extends Factory
{
    protected $model = MediaFolder::class;

    public function definition(): array
    {
        return [
            'name' => $this->faker->unique()->words(2, true),
            'parent_id' => null,
        ];
    }
}
```

`database/factories/MediaFactory.php`:

```php
<?php

namespace MarvelousDigital\FilamentMediaManager\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use MarvelousDigital\FilamentMediaManager\Enums\MediaType;
use MarvelousDigital\FilamentMediaManager\Enums\ThumbnailStatus;
use MarvelousDigital\FilamentMediaManager\Models\Media;

class MediaFactory extends Factory
{
    protected $model = Media::class;

    public function definition(): array
    {
        $ulid = (string) Str::ulid();

        return [
            'folder_id' => null,
            'name' => 'photo',
            'file_name' => 'photo.jpg',
            'disk' => 'public',
            'path' => "media/{$ulid}/photo.jpg",
            'mime_type' => 'image/jpeg',
            'type' => MediaType::Image,
            'size' => 1024,
            'thumbnail_status' => ThumbnailStatus::Pending,
        ];
    }
}
```

- [ ] **Step 8: Run** `vendor/bin/pest` — expect all PASS.

- [ ] **Step 9: Commit**

```bash
vendor/bin/pint --dirty
git add -A && git commit -m "feat: add media and folder models, migration, factories"
```

---

### Task 3: GenerateThumbnail job

**Files:**
- Create: `src/Jobs/GenerateThumbnail.php`
- Test: `tests/GenerateThumbnailTest.php`

**Interfaces:**
- Consumes: `Media` model, `ThumbnailStatus`, `MediaType`, config `filament-media-manager.thumbnail.*`.
- Produces: `GenerateThumbnail` job (`__construct(public Media $media)`, `handle(): void`), plus static helper `GenerateThumbnail::dispatchFor(Media $media): void` which respects the `thumbnail.queue` / `thumbnail.queue_name` config (later tasks call only `dispatchFor`).

- [ ] **Step 1: Write failing tests** (`tests/GenerateThumbnailTest.php`)

```php
<?php

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use MarvelousDigital\FilamentMediaManager\Enums\ThumbnailStatus;
use MarvelousDigital\FilamentMediaManager\Jobs\GenerateThumbnail;
use MarvelousDigital\FilamentMediaManager\Models\Media;

function createStoredImage(int $width = 1200, int $height = 800): Media
{
    Storage::fake('public');

    $file = UploadedFile::fake()->image('photo.jpg', $width, $height);
    $path = $file->storeAs('media/01JOB', 'photo.jpg', ['disk' => 'public']);

    return Media::factory()->create([
        'disk' => 'public',
        'path' => $path,
        'mime_type' => 'image/jpeg',
    ]);
}

it('generates a webp thumbnail and records dimensions', function () {
    $media = createStoredImage(1200, 800);

    (new GenerateThumbnail($media))->handle();

    $media->refresh();

    expect($media->thumbnail_status)->toBe(ThumbnailStatus::Completed)
        ->and($media->thumbnail_path)->toBe('media/01JOB/thumb.webp')
        ->and($media->width)->toBe(1200)
        ->and($media->height)->toBe(800);

    Storage::disk('public')->assertExists('media/01JOB/thumb.webp');
});

it('marks the media failed when the source is not a readable image', function () {
    Storage::fake('public');
    Storage::disk('public')->put('media/01BAD/broken.jpg', 'not-an-image');

    $media = Media::factory()->create([
        'disk' => 'public',
        'path' => 'media/01BAD/broken.jpg',
        'mime_type' => 'image/jpeg',
    ]);

    (new GenerateThumbnail($media))->handle();

    expect($media->refresh()->thumbnail_status)->toBe(ThumbnailStatus::Failed);
});

it('dispatches queued when configured', function () {
    Queue::fake();
    config()->set('filament-media-manager.thumbnail.queue', true);

    $media = createStoredImage();
    GenerateThumbnail::dispatchFor($media);

    Queue::assertPushed(GenerateThumbnail::class);
});

it('runs synchronously when queueing is disabled', function () {
    config()->set('filament-media-manager.thumbnail.queue', false);

    $media = createStoredImage();
    GenerateThumbnail::dispatchFor($media);

    expect($media->refresh()->thumbnail_status)->toBe(ThumbnailStatus::Completed);
});
```

- [ ] **Step 2: Run** `vendor/bin/pest tests/GenerateThumbnailTest.php` — expect FAIL.

- [ ] **Step 3: Implement the job**

`src/Jobs/GenerateThumbnail.php`:

```php
<?php

namespace MarvelousDigital\FilamentMediaManager\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Image;
use MarvelousDigital\FilamentMediaManager\Enums\MediaType;
use MarvelousDigital\FilamentMediaManager\Enums\ThumbnailStatus;
use MarvelousDigital\FilamentMediaManager\Models\Media;
use Throwable;

class GenerateThumbnail implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function __construct(public Media $media) {}

    public static function dispatchFor(Media $media): void
    {
        if (config('filament-media-manager.thumbnail.queue')) {
            static::dispatch($media)->onQueue(config('filament-media-manager.thumbnail.queue_name'));
        } else {
            static::dispatchSync($media);
        }
    }

    public function handle(): void
    {
        if ($this->media->type !== MediaType::Image || $this->media->mime_type === 'image/svg+xml') {
            $this->media->update(['thumbnail_status' => ThumbnailStatus::Skipped]);

            return;
        }

        try {
            $image = Image::fromStorage($this->media->path, $this->media->disk)->orient();

            [$width, $height] = $image->dimensions();

            $format = config('filament-media-manager.thumbnail.format', 'webp');
            $thumbnailName = 'thumb.' . $format;

            $path = $image
                ->scale(width: config('filament-media-manager.thumbnail.width', 600))
                ->optimize(format: $format, quality: config('filament-media-manager.thumbnail.quality', 70))
                ->storeAs(
                    path: $this->media->directory(),
                    name: $thumbnailName,
                    disk: $this->media->disk,
                );

            if ($path === false) {
                throw new \RuntimeException("Could not store thumbnail for media [{$this->media->id}].");
            }

            $this->media->update([
                'thumbnail_path' => $path,
                'thumbnail_status' => ThumbnailStatus::Completed,
                'width' => $width,
                'height' => $height,
            ]);
        } catch (Throwable $exception) {
            $this->media->update(['thumbnail_status' => ThumbnailStatus::Failed]);

            report($exception);
        }
    }
}
```

Implementation notes:
- `dimensions()` is read **before** `scale()` so the stored width/height are the original's (post-EXIF-orientation).
- If `optimize(format:, quality:)` is unavailable in the installed framework build, use `->toWebp()->quality(...)` (and a `match` on format if supporting jpg).
- If `storeAs` places the file elsewhere than `{directory}/thumb.webp`, adjust arguments until the first test's exact-path assertion passes — the path contract matters (spec).

- [ ] **Step 4: Run** `vendor/bin/pest tests/GenerateThumbnailTest.php` — expect PASS (requires GD; if the environment lacks GD, `composer update` would have failed earlier — GD ships with most PHP installs).

- [ ] **Step 5: Commit**

```bash
vendor/bin/pint --dirty
git add -A && git commit -m "feat: add thumbnail generation job using the Laravel Image facade"
```

---

### Task 4: UploadMedia action class

**Files:**
- Create: `src/Actions/UploadMedia.php`
- Test: `tests/UploadMediaTest.php`

**Interfaces:**
- Consumes: `Media`, `MediaType`, `ThumbnailStatus`, `GenerateThumbnail::dispatchFor()`, config `filament-media-manager.{disk,directory,accepted_mime_types,max_file_size}`.
- Produces: `UploadMedia::execute(UploadedFile $file, ?MediaFolder $folder = null): Media` — throws `Illuminate\Validation\ValidationException` on rejected mime/size. Later tasks call `app(UploadMedia::class)->execute(...)`.

- [ ] **Step 1: Write failing tests** (`tests/UploadMediaTest.php`)

```php
<?php

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use MarvelousDigital\FilamentMediaManager\Actions\UploadMedia;
use MarvelousDigital\FilamentMediaManager\Enums\MediaType;
use MarvelousDigital\FilamentMediaManager\Enums\ThumbnailStatus;
use MarvelousDigital\FilamentMediaManager\Jobs\GenerateThumbnail;
use MarvelousDigital\FilamentMediaManager\Models\MediaFolder;

beforeEach(function () {
    Storage::fake('public');
    Queue::fake();
});

it('stores the original untouched and creates a media row', function () {
    $file = UploadedFile::fake()->image('My Photo.jpg', 800, 600);
    $originalBytes = file_get_contents($file->getRealPath());

    $folder = MediaFolder::create(['name' => 'Summer']);

    $media = app(UploadMedia::class)->execute($file, $folder);

    expect($media->folder_id)->toBe($folder->id)
        ->and($media->name)->toBe('My Photo')
        ->and($media->file_name)->toBe('My Photo.jpg')
        ->and($media->type)->toBe(MediaType::Image)
        ->and($media->thumbnail_status)->toBe(ThumbnailStatus::Pending)
        ->and($media->size)->toBeGreaterThan(0)
        ->and($media->path)->toEndWith('/My Photo.jpg');

    Storage::disk('public')->assertExists($media->path);
    expect(Storage::disk('public')->get($media->path))->toBe($originalBytes);

    Queue::assertPushed(GenerateThumbnail::class);
});

it('marks non-image uploads as thumbnail skipped and does not dispatch the job', function () {
    $file = UploadedFile::fake()->create('report.pdf', 100, 'application/pdf');

    $media = app(UploadMedia::class)->execute($file);

    expect($media->type)->toBe(MediaType::Document)
        ->and($media->thumbnail_status)->toBe(ThumbnailStatus::Skipped);

    Queue::assertNothingPushed();
});

it('rejects disallowed mime types', function () {
    config()->set('filament-media-manager.accepted_mime_types', ['image/jpeg']);

    $file = UploadedFile::fake()->create('script.exe', 10, 'application/x-msdownload');

    app(UploadMedia::class)->execute($file);
})->throws(ValidationException::class);

it('rejects files over the max size', function () {
    config()->set('filament-media-manager.max_file_size', 1); // 1 KB

    $file = UploadedFile::fake()->create('big.bin', 10, 'application/octet-stream'); // 10 KB

    app(UploadMedia::class)->execute($file);
})->throws(ValidationException::class);

it('sanitizes path-hostile filenames', function () {
    $file = UploadedFile::fake()->image('../../evil.jpg');

    $media = app(UploadMedia::class)->execute($file);

    expect($media->file_name)->not->toContain('/')
        ->and($media->file_name)->not->toContain('..');
});
```

- [ ] **Step 2: Run** `vendor/bin/pest tests/UploadMediaTest.php` — expect FAIL.

- [ ] **Step 3: Implement**

`src/Actions/UploadMedia.php`:

```php
<?php

namespace MarvelousDigital\FilamentMediaManager\Actions;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use MarvelousDigital\FilamentMediaManager\Enums\MediaType;
use MarvelousDigital\FilamentMediaManager\Enums\ThumbnailStatus;
use MarvelousDigital\FilamentMediaManager\Jobs\GenerateThumbnail;
use MarvelousDigital\FilamentMediaManager\Models\Media;
use MarvelousDigital\FilamentMediaManager\Models\MediaFolder;

class UploadMedia
{
    public function execute(UploadedFile $file, ?MediaFolder $folder = null): Media
    {
        $this->validate($file);

        $disk = config('filament-media-manager.disk');
        $directory = trim(config('filament-media-manager.directory'), '/') . '/' . Str::ulid();
        $fileName = $this->sanitizeFileName($file->getClientOriginalName());
        $mimeType = $file->getMimeType() ?? 'application/octet-stream';
        $type = MediaType::fromMimeType($mimeType);

        $path = $file->storeAs($directory, $fileName, ['disk' => $disk]);

        $needsThumbnail = $type === MediaType::Image && $mimeType !== 'image/svg+xml';

        $media = Media::create([
            'folder_id' => $folder?->id,
            'name' => pathinfo($fileName, PATHINFO_FILENAME),
            'file_name' => $fileName,
            'disk' => $disk,
            'path' => $path,
            'mime_type' => $mimeType,
            'type' => $type,
            'size' => $file->getSize(),
            'thumbnail_status' => $needsThumbnail ? ThumbnailStatus::Pending : ThumbnailStatus::Skipped,
            'created_by' => Auth::id(),
        ]);

        if ($needsThumbnail) {
            GenerateThumbnail::dispatchFor($media);
        }

        return $media;
    }

    protected function validate(UploadedFile $file): void
    {
        $accepted = config('filament-media-manager.accepted_mime_types');

        if (is_array($accepted) && ! in_array($file->getMimeType(), $accepted, true)) {
            throw ValidationException::withMessages([
                'file' => "The file type of \"{$file->getClientOriginalName()}\" is not allowed.",
            ]);
        }

        $maxKilobytes = config('filament-media-manager.max_file_size');

        if ($maxKilobytes !== null && $file->getSize() > $maxKilobytes * 1024) {
            throw ValidationException::withMessages([
                'file' => "\"{$file->getClientOriginalName()}\" exceeds the maximum size of {$maxKilobytes} KB.",
            ]);
        }
    }

    protected function sanitizeFileName(string $fileName): string
    {
        $fileName = basename(str_replace('\\', '/', $fileName));
        $fileName = str_replace(['..', '#', '?'], '', $fileName);

        return $fileName === '' ? 'file' : $fileName;
    }
}
```

- [ ] **Step 4: Run** `vendor/bin/pest tests/UploadMediaTest.php` — expect PASS. (If the queued-dispatch assertion fails because `Queue::fake()` doesn't intercept `dispatchFor` with `thumbnail.queue=true` default — it does; but confirm config default is `true`.)

- [ ] **Step 5: Commit**

```bash
vendor/bin/pint --dirty
git add -A && git commit -m "feat: add UploadMedia action storing untouched originals"
```

---

### Task 5: MediaLibrary page — folder navigation and grid listing

**Files:**
- Modify: `src/Pages/MediaLibrary.php`, `resources/views/media-library.blade.php`, `resources/dist/filament-media-manager.css`
- Test: `tests/MediaLibraryPageTest.php`

**Interfaces:**
- Consumes: models, `MediaManagerPlugin` registration (Task 1), config `per_page`.
- Produces (used by Tasks 6–9):
  - Page Livewire properties: `#[Url] public ?int $folderId = null;`, `#[Url] public string $search = '';`, `public int $loadedPages = 1;`, `public array $selected = [];`
  - Methods: `openFolder(?int $folderId): void`, `loadMore(): void`, `currentFolder(): ?MediaFolder`.
  - View data: `folders` (Collection of current-level MediaFolder), `mediaItems` (Collection of Media, limited), `hasMore` (bool), `breadcrumbFolders` (array from `ancestors()` + current).
  - Blade view sections marked with placeholder comments `{{-- @upload-ui --}}`, `{{-- @folder-actions --}}`, `{{-- @media-actions --}}`, `{{-- @bulk-actions --}}` where later tasks graft in.

- [ ] **Step 1: Write failing tests** (`tests/MediaLibraryPageTest.php`)

```php
<?php

use Livewire\Livewire;
use MarvelousDigital\FilamentMediaManager\Models\Media;
use MarvelousDigital\FilamentMediaManager\Models\MediaFolder;
use MarvelousDigital\FilamentMediaManager\Pages\MediaLibrary;

beforeEach(function () {
    $this->actingAsUser();
});

it('renders folders and media at the root', function () {
    $folder = MediaFolder::create(['name' => 'Campaigns']);
    Media::factory()->create(['name' => 'root-photo']);
    Media::factory()->create(['name' => 'nested-photo', 'folder_id' => $folder->id]);

    Livewire::test(MediaLibrary::class)
        ->assertSee('Campaigns')
        ->assertSee('root-photo')
        ->assertDontSee('nested-photo');
});

it('navigates into a folder and shows breadcrumbs', function () {
    $parent = MediaFolder::create(['name' => 'Campaigns']);
    $child = MediaFolder::create(['name' => 'Summer', 'parent_id' => $parent->id]);
    Media::factory()->create(['name' => 'inside-summer', 'folder_id' => $child->id]);

    Livewire::test(MediaLibrary::class)
        ->call('openFolder', $child->id)
        ->assertSet('folderId', $child->id)
        ->assertSee('inside-summer')
        ->assertSee('Campaigns'); // breadcrumb
});

it('scopes search to the current folder by name and alt text', function () {
    Media::factory()->create(['name' => 'sunset-beach']);
    Media::factory()->create(['name' => 'x', 'alt_text' => 'a red sunset']);
    Media::factory()->create(['name' => 'mountain']);

    Livewire::test(MediaLibrary::class)
        ->set('search', 'sunset')
        ->assertSee('sunset-beach')
        ->assertSee('a red sunset')
        ->assertDontSee('mountain');
});

it('paginates with load more', function () {
    config()->set('filament-media-manager.per_page', 2);
    Media::factory()->count(5)->sequence(fn ($seq) => ['name' => 'file-' . $seq->index])->create();

    $component = Livewire::test(MediaLibrary::class);
    expect($component->viewData('mediaItems'))->toHaveCount(2)
        ->and($component->viewData('hasMore'))->toBeTrue();

    $component->call('loadMore');
    expect($component->viewData('mediaItems'))->toHaveCount(4);
});

it('resets pagination when the search changes', function () {
    config()->set('filament-media-manager.per_page', 2);
    Media::factory()->count(5)->create();

    Livewire::test(MediaLibrary::class)
        ->call('loadMore')
        ->set('search', 'anything')
        ->assertSet('loadedPages', 1);
});
```

- [ ] **Step 2: Run** `vendor/bin/pest tests/MediaLibraryPageTest.php` — expect FAIL.

- [ ] **Step 3: Implement the page**

Replace `src/Pages/MediaLibrary.php`:

```php
<?php

namespace MarvelousDigital\FilamentMediaManager\Pages;

use Filament\Pages\Page;
use Illuminate\Contracts\Database\Eloquent\Builder;
use Livewire\Attributes\Url;
use MarvelousDigital\FilamentMediaManager\Models\Media;
use MarvelousDigital\FilamentMediaManager\Models\MediaFolder;

class MediaLibrary extends Page
{
    protected string $view = 'filament-media-manager::media-library';

    #[Url]
    public ?int $folderId = null;

    #[Url]
    public string $search = '';

    public int $loadedPages = 1;

    /** @var array<int> */
    public array $selected = [];

    public static function getNavigationLabel(): string
    {
        return 'Media';
    }

    public static function getNavigationIcon(): ?string
    {
        return 'heroicon-o-photo';
    }

    public function getTitle(): string
    {
        return 'Media';
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
        $this->selected = [];
    }

    public function loadMore(): void
    {
        $this->loadedPages++;
    }

    public function updatedSearch(): void
    {
        $this->loadedPages = 1;
    }

    protected function mediaQuery(): Builder
    {
        return Media::query()
            ->where('folder_id', $this->folderId)
            ->when($this->search !== '', function (Builder $query) {
                $query->where(function (Builder $query) {
                    $query->where('name', 'like', "%{$this->search}%")
                        ->orWhere('alt_text', 'like', "%{$this->search}%");
                });
            })
            ->latest();
    }

    protected function getViewData(): array
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

Note: if the current folder was deleted elsewhere, `currentFolder()` returns null and the query shows `folder_id = {stale id}` (empty grid) — acceptable; `openFolder(null)` recovers. If `Media::where('folder_id', null)` needs `whereNull`, use `$this->folderId === null ? $q->whereNull('folder_id') : $q->where('folder_id', $this->folderId)` — the root-listing test catches this (SQLite `where('folder_id', null)` does NOT match nulls, so write it with the explicit conditional from the start).

- [ ] **Step 4: Implement the view**

Replace `resources/views/media-library.blade.php`:

```blade
<x-filament-panels::page>
    <div class="fmm-library">
        {{-- Toolbar --}}
        <div class="fmm-toolbar">
            <nav class="fmm-breadcrumbs">
                <button type="button" wire:click="openFolder(null)" @class(['fmm-crumb', 'fmm-crumb-current' => ! $currentFolder])>
                    Media
                </button>
                @foreach ($breadcrumbFolders as $crumb)
                    <span class="fmm-crumb-sep">/</span>
                    <button type="button" wire:click="openFolder({{ $crumb->id }})" @class(['fmm-crumb', 'fmm-crumb-current' => $crumb->id === $folderId])>
                        {{ $crumb->name }}
                    </button>
                @endforeach
            </nav>

            <div class="fmm-toolbar-actions">
                <input
                    type="search"
                    wire:model.live.debounce.400ms="search"
                    placeholder="Search this folder…"
                    class="fmm-search fi-input"
                />
                {{-- @folder-actions --}}
                {{-- @upload-ui --}}
            </div>
        </div>

        {{-- @bulk-actions --}}

        {{-- Grid --}}
        <div class="fmm-grid">
            @foreach ($folders as $folder)
                <div class="fmm-card fmm-folder" wire:key="folder-{{ $folder->id }}">
                    <button type="button" class="fmm-card-body" wire:click="openFolder({{ $folder->id }})">
                        <x-filament::icon icon="heroicon-o-folder" class="fmm-folder-icon" />
                        <span class="fmm-card-name">{{ $folder->name }}</span>
                    </button>
                    {{-- @folder-card-actions --}}
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
                    {{-- @media-actions --}}
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
    </div>
</x-filament-panels::page>
```

Note: image cards render `thumbnailUrl()` only when the thumbnail exists; while `thumbnail_status` is pending/failed the fallback icon shows. That is the spec's failure behavior.

- [ ] **Step 5: Write the stylesheet**

`resources/dist/filament-media-manager.css`:

```css
.fmm-library { display: flex; flex-direction: column; gap: 1rem; }
.fmm-toolbar { display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: 0.75rem; }
.fmm-breadcrumbs { display: flex; align-items: center; gap: 0.25rem; font-size: 0.875rem; }
.fmm-crumb { color: rgb(107 114 128); }
.fmm-crumb:hover { color: rgb(17 24 39); text-decoration: underline; }
.dark .fmm-crumb:hover { color: rgb(243 244 246); }
.fmm-crumb-current { font-weight: 600; color: rgb(17 24 39); }
.dark .fmm-crumb-current { color: rgb(243 244 246); }
.fmm-crumb-sep { color: rgb(156 163 175); }
.fmm-toolbar-actions { display: flex; align-items: center; gap: 0.5rem; }
.fmm-search { min-width: 14rem; border-radius: 0.5rem; border: 1px solid rgb(209 213 219); padding: 0.375rem 0.75rem; font-size: 0.875rem; background: transparent; }
.dark .fmm-search { border-color: rgb(55 65 81); }
.fmm-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(10rem, 1fr)); gap: 0.75rem; }
.fmm-card { position: relative; border: 1px solid rgb(229 231 235); border-radius: 0.75rem; overflow: hidden; background: white; }
.dark .fmm-card { background: rgb(24 24 27); border-color: rgb(55 65 81); }
.fmm-card-body { display: flex; flex-direction: column; align-items: stretch; width: 100%; text-align: start; }
.fmm-folder .fmm-card-body { flex-direction: row; align-items: center; gap: 0.5rem; padding: 0.75rem; cursor: pointer; }
.fmm-folder-icon { width: 1.5rem; height: 1.5rem; color: rgb(245 158 11); flex: none; }
.fmm-thumb { aspect-ratio: 1 / 1; display: flex; align-items: center; justify-content: center; background: rgb(243 244 246); }
.dark .fmm-thumb { background: rgb(39 39 42); }
.fmm-thumb img { width: 100%; height: 100%; object-fit: cover; }
.fmm-type-icon { width: 2.5rem; height: 2.5rem; color: rgb(156 163 175); }
.fmm-card-name { padding: 0.5rem 0.75rem; font-size: 0.8125rem; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.fmm-empty { display: flex; flex-direction: column; align-items: center; gap: 0.5rem; padding: 3rem 0; color: rgb(107 114 128); }
.fmm-empty-icon { width: 3rem; height: 3rem; }
.fmm-load-more { display: flex; justify-content: center; }
```

- [ ] **Step 6: Run** `vendor/bin/pest tests/MediaLibraryPageTest.php` — expect PASS. Then full suite `vendor/bin/pest`.

- [ ] **Step 7: Commit**

```bash
vendor/bin/pint --dirty
git add -A && git commit -m "feat: add media library page with folder navigation, search, load more"
```

---

### Task 6: Upload UI (drag-and-drop + button, per-file progress)

**Files:**
- Modify: `src/Pages/MediaLibrary.php`, `resources/views/media-library.blade.php` (at `{{-- @upload-ui --}}`), `resources/dist/filament-media-manager.css`
- Test: `tests/MediaLibraryUploadTest.php`

**Interfaces:**
- Consumes: `UploadMedia::execute()`, `currentFolder()`.
- Produces: `public array $uploads = [];` (Livewire `WithFileUploads`), hook `updatedUploads(): void` that persists every file and resets the property. Notifications via `Filament\Notifications\Notification`.

- [ ] **Step 1: Write failing tests** (`tests/MediaLibraryUploadTest.php`)

```php
<?php

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use MarvelousDigital\FilamentMediaManager\Jobs\GenerateThumbnail;
use MarvelousDigital\FilamentMediaManager\Models\Media;
use MarvelousDigital\FilamentMediaManager\Models\MediaFolder;
use MarvelousDigital\FilamentMediaManager\Pages\MediaLibrary;

beforeEach(function () {
    Storage::fake('public');
    Queue::fake();
    $this->actingAsUser();
});

it('persists uploaded files into the current folder', function () {
    $folder = MediaFolder::create(['name' => 'Summer']);

    Livewire::test(MediaLibrary::class)
        ->call('openFolder', $folder->id)
        ->set('uploads', [
            UploadedFile::fake()->image('one.jpg'),
            UploadedFile::fake()->image('two.jpg'),
        ]);

    expect(Media::query()->count())->toBe(2)
        ->and(Media::query()->pluck('folder_id')->unique()->all())->toBe([$folder->id]);

    Queue::assertPushed(GenerateThumbnail::class, 2);
});

it('continues the batch when one file fails validation', function () {
    config()->set('filament-media-manager.accepted_mime_types', ['image/jpeg']);

    Livewire::test(MediaLibrary::class)
        ->set('uploads', [
            UploadedFile::fake()->image('good.jpg'),
            UploadedFile::fake()->create('bad.pdf', 10, 'application/pdf'),
        ])
        ->assertNotified();

    expect(Media::query()->count())->toBe(1)
        ->and(Media::query()->first()->file_name)->toBe('good.jpg');
});

it('clears the pending uploads after processing', function () {
    Livewire::test(MediaLibrary::class)
        ->set('uploads', [UploadedFile::fake()->image('one.jpg')])
        ->assertSet('uploads', []);
});
```

(If `assertNotified()` is unavailable, assert via `Notification::assertNotified()` from `Filament\Notifications\Testing`, or drop that single assertion and keep the row-count ones.)

- [ ] **Step 2: Run** — expect FAIL.

- [ ] **Step 3: Implement page changes**

In `MediaLibrary`, add the trait and hook:

```php
use Filament\Notifications\Notification;
use Illuminate\Validation\ValidationException;
use Livewire\WithFileUploads;
use MarvelousDigital\FilamentMediaManager\Actions\UploadMedia;

class MediaLibrary extends Page
{
    use WithFileUploads;

    /** @var array<\Illuminate\Http\UploadedFile> */
    public array $uploads = [];

    // ... existing code ...

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
                    ->body(implode(' ', $exception->validator?->errors()->all() ?? $exception->errors()['file'] ?? []))
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
}
```

(`ValidationException::withMessages()` produces `$exception->errors()` — use `implode(' ', collect($exception->errors())->flatten()->all())` if the above accessor is awkward.)

- [ ] **Step 4: Implement view changes**

Replace `{{-- @upload-ui --}}` in the toolbar with:

```blade
<div
    x-data="{
        progress: 0,
        uploading: false,
        handleFiles(files) {
            if (! files.length) return;
            this.uploading = true;
            $wire.uploadMultiple('uploads', files,
                () => { this.uploading = false; this.progress = 0; },
                () => { this.uploading = false; this.progress = 0; },
                (event) => { this.progress = event.detail.progress; },
            );
        },
    }"
    x-on:dragover.prevent
    x-on:drop.prevent="handleFiles($event.dataTransfer.files)"
    class="fmm-upload"
>
    <input type="file" multiple class="fmm-upload-input" x-ref="input" x-on:change="handleFiles($event.target.files); $event.target.value = null;" />
    <x-filament::button icon="heroicon-o-arrow-up-tray" x-on:click="$refs.input.click()" x-show="! uploading">
        Upload
    </x-filament::button>
    <div class="fmm-upload-progress" x-show="uploading" x-cloak>
        <div class="fmm-upload-progress-bar" x-bind:style="`width: ${progress}%`"></div>
    </div>
</div>
```

And make the whole grid a drop target by wrapping `.fmm-library`'s inner content — add to the root `div.fmm-library`:

```blade
<div
    class="fmm-library"
    x-data
    x-on:dragover.prevent
    x-on:drop.prevent="
        const input = document.querySelector('.fmm-upload');
        if (input) Alpine.$data(input).handleFiles($event.dataTransfer.files);
    "
>
```

Add CSS:

```css
.fmm-upload { display: flex; align-items: center; }
.fmm-upload-input { display: none; }
.fmm-upload-progress { width: 8rem; height: 0.5rem; border-radius: 9999px; background: rgb(229 231 235); overflow: hidden; }
.dark .fmm-upload-progress { background: rgb(55 65 81); }
.fmm-upload-progress-bar { height: 100%; background: rgb(59 130 246); transition: width 150ms ease; }
```

- [ ] **Step 5: Run** `vendor/bin/pest` — expect PASS.

- [ ] **Step 6: Commit**

```bash
vendor/bin/pint --dirty
git add -A && git commit -m "feat: add multi-file upload with drag-and-drop and progress"
```

---

### Task 7: Folder actions — create, rename, delete (empty only)

**Files:**
- Modify: `src/Pages/MediaLibrary.php`, `resources/views/media-library.blade.php` (at `{{-- @folder-actions --}}` and `{{-- @folder-card-actions --}}`)
- Test: `tests/FolderActionsTest.php`

**Interfaces:**
- Consumes: `MediaFolder`, page state `folderId`.
- Produces: Filament page actions `createFolder`, `renameFolder` (argument `folder` = id), `deleteFolder` (argument `folder` = id). Filament action-mount API: `mountAction('renameFolder', ['folder' => $id])` — the same argument convention is used by Task 8's media actions.

- [ ] **Step 1: Write failing tests** (`tests/FolderActionsTest.php`)

```php
<?php

use Livewire\Livewire;
use MarvelousDigital\FilamentMediaManager\Models\Media;
use MarvelousDigital\FilamentMediaManager\Models\MediaFolder;
use MarvelousDigital\FilamentMediaManager\Pages\MediaLibrary;

beforeEach(function () {
    $this->actingAsUser();
});

it('creates a folder inside the current folder', function () {
    $parent = MediaFolder::create(['name' => 'Campaigns']);

    Livewire::test(MediaLibrary::class)
        ->call('openFolder', $parent->id)
        ->callAction('createFolder', ['name' => 'Summer']);

    expect(MediaFolder::query()->where('name', 'Summer')->first()->parent_id)->toBe($parent->id);
});

it('rejects duplicate folder names at the same level', function () {
    MediaFolder::create(['name' => 'Summer']);

    Livewire::test(MediaLibrary::class)
        ->callAction('createFolder', ['name' => 'Summer'])
        ->assertHasActionErrors(['name']);
});

it('renames a folder', function () {
    $folder = MediaFolder::create(['name' => 'Old']);

    Livewire::test(MediaLibrary::class)
        ->callAction('renameFolder', ['name' => 'New'], arguments: ['folder' => $folder->id]);

    expect($folder->refresh()->name)->toBe('New');
});

it('deletes an empty folder', function () {
    $folder = MediaFolder::create(['name' => 'Empty']);

    Livewire::test(MediaLibrary::class)
        ->callAction('deleteFolder', arguments: ['folder' => $folder->id]);

    expect(MediaFolder::query()->count())->toBe(0);
});

it('refuses to delete a non-empty folder', function () {
    $folder = MediaFolder::create(['name' => 'Full']);
    Media::factory()->create(['folder_id' => $folder->id]);

    Livewire::test(MediaLibrary::class)
        ->callAction('deleteFolder', arguments: ['folder' => $folder->id])
        ->assertNotified();

    expect(MediaFolder::query()->count())->toBe(1);
});
```

- [ ] **Step 2: Run** — expect FAIL.

- [ ] **Step 3: Implement the actions**

Add to `MediaLibrary` (imports: `Filament\Actions\Action`, `Filament\Forms\Components\TextInput`, `Illuminate\Validation\Rule`):

```php
public function createFolderAction(): Action
{
    return Action::make('createFolder')
        ->label('New folder')
        ->icon('heroicon-o-folder-plus')
        ->color('gray')
        ->modalHeading('New folder')
        ->schema([
            TextInput::make('name')
                ->required()
                ->maxLength(255)
                ->rules([
                    fn () => Rule::unique('media_folders', 'name')->where('parent_id', $this->folderId),
                ]),
        ])
        ->action(function (array $data): void {
            MediaFolder::create(['name' => $data['name'], 'parent_id' => $this->folderId]);
        });
}

public function renameFolderAction(): Action
{
    return Action::make('renameFolder')
        ->label('Rename')
        ->icon('heroicon-o-pencil')
        ->modalHeading('Rename folder')
        ->fillForm(fn (array $arguments): array => [
            'name' => MediaFolder::find($arguments['folder'])?->name,
        ])
        ->schema([
            TextInput::make('name')->required()->maxLength(255),
        ])
        ->action(function (array $arguments, array $data): void {
            MediaFolder::find($arguments['folder'])?->update(['name' => $data['name']]);
        });
}

public function deleteFolderAction(): Action
{
    return Action::make('deleteFolder')
        ->label('Delete')
        ->icon('heroicon-o-trash')
        ->color('danger')
        ->requiresConfirmation()
        ->modalHeading('Delete folder')
        ->action(function (array $arguments): void {
            $folder = MediaFolder::find($arguments['folder']);

            if (! $folder) {
                return;
            }

            if (! $folder->isEmpty()) {
                Notification::make()
                    ->title('Folder is not empty')
                    ->body('Move or delete its contents first.')
                    ->warning()
                    ->send();

                return;
            }

            $folder->delete();
        });
}
```

- [ ] **Step 4: Wire into the view**

At `{{-- @folder-actions --}}` (toolbar): `{{ $this->createFolderAction }}`

At `{{-- @folder-card-actions --}}` (inside each folder card):

```blade
<div class="fmm-card-menu">
    <x-filament::icon-button icon="heroicon-o-pencil" color="gray" size="sm" label="Rename"
        wire:click="mountAction('renameFolder', { folder: {{ $folder->id }} })" />
    <x-filament::icon-button icon="heroicon-o-trash" color="danger" size="sm" label="Delete"
        wire:click="mountAction('deleteFolder', { folder: {{ $folder->id }} })" />
</div>
```

CSS addition:

```css
.fmm-card-menu { position: absolute; top: 0.375rem; right: 0.375rem; display: none; gap: 0.125rem; }
.fmm-card:hover .fmm-card-menu { display: flex; }
```

(If `mountAction` in the installed Filament version takes arguments differently — e.g. `mountAction('renameFolder', JSON)` — check how Filament's own table action buttons render and match that. The Livewire tests define correctness.)

- [ ] **Step 5: Run** `vendor/bin/pest` — expect PASS.

- [ ] **Step 6: Commit**

```bash
vendor/bin/pint --dirty
git add -A && git commit -m "feat: add folder create, rename, and empty-only delete actions"
```

---

### Task 8: Media detail slide-over — edit, download, delete, retry thumbnail

**Files:**
- Modify: `src/Pages/MediaLibrary.php`, `resources/views/media-library.blade.php` (at `{{-- @media-actions --}}`)
- Create: `resources/views/media-details.blade.php`
- Test: `tests/MediaActionsTest.php`

**Interfaces:**
- Consumes: `Media`, `MediaFolder::treeOptions()`, `GenerateThumbnail::dispatchFor()`.
- Produces: page actions `editMedia` (argument `media` = id; slide-over; fields `name`, `alt_text`, `folder_id`), `deleteMedia` (argument `media`), `downloadMedia` (argument `media`), `retryThumbnail` (argument `media`).

- [ ] **Step 1: Write failing tests** (`tests/MediaActionsTest.php`)

```php
<?php

use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use MarvelousDigital\FilamentMediaManager\Enums\ThumbnailStatus;
use MarvelousDigital\FilamentMediaManager\Jobs\GenerateThumbnail;
use MarvelousDigital\FilamentMediaManager\Models\Media;
use MarvelousDigital\FilamentMediaManager\Models\MediaFolder;
use MarvelousDigital\FilamentMediaManager\Pages\MediaLibrary;

beforeEach(function () {
    Storage::fake('public');
    $this->actingAsUser();
});

it('edits name, alt text, and folder', function () {
    $media = Media::factory()->create();
    $folder = MediaFolder::create(['name' => 'Target']);

    Livewire::test(MediaLibrary::class)
        ->callAction('editMedia', [
            'name' => 'Renamed',
            'alt_text' => 'A description',
            'folder_id' => $folder->id,
        ], arguments: ['media' => $media->id]);

    $media->refresh();

    expect($media->name)->toBe('Renamed')
        ->and($media->alt_text)->toBe('A description')
        ->and($media->folder_id)->toBe($folder->id);
});

it('prefills the edit form', function () {
    $media = Media::factory()->create(['name' => 'Original', 'alt_text' => 'alt']);

    Livewire::test(MediaLibrary::class)
        ->mountAction('editMedia', arguments: ['media' => $media->id])
        ->assertSchemaStateSet(['name' => 'Original', 'alt_text' => 'alt']);
});

it('deletes media and its files', function () {
    Storage::disk('public')->put('media/01DEL/x.jpg', 'bytes');
    $media = Media::factory()->create(['disk' => 'public', 'path' => 'media/01DEL/x.jpg']);

    Livewire::test(MediaLibrary::class)
        ->callAction('deleteMedia', arguments: ['media' => $media->id]);

    expect(Media::query()->count())->toBe(0);
    Storage::disk('public')->assertMissing('media/01DEL/x.jpg');
});

it('downloads the original file', function () {
    Storage::disk('public')->put('media/01DL/x.jpg', 'bytes');
    $media = Media::factory()->create(['disk' => 'public', 'path' => 'media/01DL/x.jpg', 'file_name' => 'x.jpg']);

    Livewire::test(MediaLibrary::class)
        ->callAction('downloadMedia', arguments: ['media' => $media->id])
        ->assertFileDownloaded('x.jpg');
});

it('retries a failed thumbnail', function () {
    Queue::fake();
    $media = Media::factory()->create(['thumbnail_status' => ThumbnailStatus::Failed]);

    Livewire::test(MediaLibrary::class)
        ->callAction('retryThumbnail', arguments: ['media' => $media->id]);

    expect($media->refresh()->thumbnail_status)->toBe(ThumbnailStatus::Pending);
    Queue::assertPushed(GenerateThumbnail::class);
});
```

(If `assertSchemaStateSet` doesn't exist in the installed Filament test API, use `assertActionDataSet` / `assertFormSet` — check `vendor/filament/actions/src/Testing` for the current name.)

- [ ] **Step 2: Run** — expect FAIL.

- [ ] **Step 3: Implement the actions**

Add to `MediaLibrary` (additional imports: `Filament\Forms\Components\Select`, `Filament\Forms\Components\Textarea`, `Filament\Schemas\Components\View` — or `Filament\Forms\Components\View` depending on version):

```php
public function editMediaAction(): Action
{
    return Action::make('editMedia')
        ->label('Details')
        ->slideOver()
        ->modalHeading(fn (array $arguments): string => Media::find($arguments['media'])?->name ?? 'Media')
        ->fillForm(function (array $arguments): array {
            $media = Media::find($arguments['media']);

            return [
                'name' => $media?->name,
                'alt_text' => $media?->alt_text,
                'folder_id' => $media?->folder_id,
            ];
        })
        ->schema(fn (array $arguments): array => [
            View::make('filament-media-manager::media-details')
                ->viewData(['media' => Media::find($arguments['media'])]),
            TextInput::make('name')->required()->maxLength(255),
            Textarea::make('alt_text')->label('Alt text')->rows(3)->maxLength(255),
            Select::make('folder_id')
                ->label('Folder')
                ->options(MediaFolder::treeOptions())
                ->placeholder('Root')
                ->nullable(),
        ])
        ->action(function (array $arguments, array $data): void {
            Media::find($arguments['media'])?->update([
                'name' => $data['name'],
                'alt_text' => $data['alt_text'],
                'folder_id' => $data['folder_id'],
            ]);
        });
}

public function deleteMediaAction(): Action
{
    return Action::make('deleteMedia')
        ->label('Delete')
        ->icon('heroicon-o-trash')
        ->color('danger')
        ->requiresConfirmation()
        ->action(function (array $arguments): void {
            Media::find($arguments['media'])?->delete();
        });
}

public function downloadMediaAction(): Action
{
    return Action::make('downloadMedia')
        ->label('Download')
        ->icon('heroicon-o-arrow-down-tray')
        ->action(function (array $arguments) {
            $media = Media::find($arguments['media']);

            if (! $media) {
                return null;
            }

            return Storage::disk($media->disk)->download($media->path, $media->file_name);
        });
}

public function retryThumbnailAction(): Action
{
    return Action::make('retryThumbnail')
        ->label('Retry thumbnail')
        ->icon('heroicon-o-arrow-path')
        ->action(function (array $arguments): void {
            $media = Media::find($arguments['media']);

            if (! $media) {
                return;
            }

            $media->update(['thumbnail_status' => ThumbnailStatus::Pending]);
            GenerateThumbnail::dispatchFor($media);
        });
}
```

`resources/views/media-details.blade.php` (preview + file facts inside the slide-over):

```blade
<div class="fmm-details">
    <div class="fmm-details-preview">
        @if ($media?->type === \MarvelousDigital\FilamentMediaManager\Enums\MediaType::Image && $media->url())
            <img src="{{ $media->url() }}" alt="{{ $media->alt_text ?? $media->name }}" />
        @else
            <x-filament::icon icon="heroicon-o-document" class="fmm-type-icon" />
        @endif
    </div>
    @if ($media)
        <dl class="fmm-details-facts">
            <div><dt>File</dt><dd>{{ $media->file_name }}</dd></div>
            <div><dt>Type</dt><dd>{{ $media->mime_type }}</dd></div>
            <div><dt>Size</dt><dd>{{ \Illuminate\Support\Number::fileSize($media->size) }}</dd></div>
            @if ($media->width)
                <div><dt>Dimensions</dt><dd>{{ $media->width }} × {{ $media->height }}</dd></div>
            @endif
            <div><dt>Uploaded</dt><dd>{{ $media->created_at->diffForHumans() }}</dd></div>
        </dl>
    @endif
</div>
```

- [ ] **Step 4: Wire into the view**

At `{{-- @media-actions --}}` inside each media card:

```blade
<button type="button" class="fmm-card-overlay" wire:click="mountAction('editMedia', { media: {{ $media->id }} })" aria-label="Open details for {{ $media->name }}"></button>
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
```

CSS additions:

```css
.fmm-card-overlay { position: absolute; inset: 0; cursor: pointer; }
.fmm-card-menu { z-index: 1; }
.fmm-details { display: flex; flex-direction: column; gap: 1rem; margin-bottom: 1rem; }
.fmm-details-preview { display: flex; justify-content: center; background: rgb(243 244 246); border-radius: 0.75rem; overflow: hidden; }
.dark .fmm-details-preview { background: rgb(39 39 42); }
.fmm-details-preview img { max-height: 20rem; object-fit: contain; }
.fmm-details-facts { display: grid; grid-template-columns: repeat(2, 1fr); gap: 0.5rem; font-size: 0.8125rem; }
.fmm-details-facts dt { color: rgb(107 114 128); }
```

- [ ] **Step 5: Run** `vendor/bin/pest` — expect PASS.

- [ ] **Step 6: Commit**

```bash
vendor/bin/pint --dirty
git add -A && git commit -m "feat: add media detail slide-over with edit, download, delete, retry"
```

---

### Task 9: Multi-select and bulk actions

**Files:**
- Modify: `src/Pages/MediaLibrary.php`, `resources/views/media-library.blade.php` (at `{{-- @bulk-actions --}}` + checkbox in media cards), `resources/dist/filament-media-manager.css`
- Test: `tests/BulkActionsTest.php`

**Interfaces:**
- Consumes: `$selected` (declared Task 5), `MediaFolder::treeOptions()`.
- Produces: page actions `bulkDelete`, `bulkMove` (field `folder_id`); selection cleared after each bulk action and on folder change (already handled by `openFolder`).

- [ ] **Step 1: Write failing tests** (`tests/BulkActionsTest.php`)

```php
<?php

use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use MarvelousDigital\FilamentMediaManager\Models\Media;
use MarvelousDigital\FilamentMediaManager\Models\MediaFolder;
use MarvelousDigital\FilamentMediaManager\Pages\MediaLibrary;

beforeEach(function () {
    Storage::fake('public');
    $this->actingAsUser();
});

it('bulk deletes selected media', function () {
    $keep = Media::factory()->create();
    $a = Media::factory()->create();
    $b = Media::factory()->create();

    Livewire::test(MediaLibrary::class)
        ->set('selected', [$a->id, $b->id])
        ->callAction('bulkDelete')
        ->assertSet('selected', []);

    expect(Media::query()->pluck('id')->all())->toBe([$keep->id]);
});

it('bulk moves selected media to a folder', function () {
    $folder = MediaFolder::create(['name' => 'Target']);
    $a = Media::factory()->create();
    $b = Media::factory()->create();

    Livewire::test(MediaLibrary::class)
        ->set('selected', [$a->id, $b->id])
        ->callAction('bulkMove', ['folder_id' => $folder->id])
        ->assertSet('selected', []);

    expect($a->refresh()->folder_id)->toBe($folder->id)
        ->and($b->refresh()->folder_id)->toBe($folder->id);
});
```

- [ ] **Step 2: Run** — expect FAIL.

- [ ] **Step 3: Implement the actions**

```php
public function bulkDeleteAction(): Action
{
    return Action::make('bulkDelete')
        ->label('Delete selected')
        ->icon('heroicon-o-trash')
        ->color('danger')
        ->requiresConfirmation()
        ->modalHeading('Delete selected media')
        ->action(function (): void {
            Media::query()->whereIn('id', $this->selected)->get()->each->delete();
            $this->selected = [];
        });
}

public function bulkMoveAction(): Action
{
    return Action::make('bulkMove')
        ->label('Move selected')
        ->icon('heroicon-o-folder-arrow-down')
        ->color('gray')
        ->schema([
            Select::make('folder_id')
                ->label('Move to')
                ->options(MediaFolder::treeOptions())
                ->placeholder('Root')
                ->nullable(),
        ])
        ->action(function (array $data): void {
            Media::query()->whereIn('id', $this->selected)->update(['folder_id' => $data['folder_id']]);
            $this->selected = [];
        });
}
```

(`->get()->each->delete()` — not `->delete()` on the query — so the model `deleted` hook removes files.)

- [ ] **Step 4: Wire into the view**

At `{{-- @bulk-actions --}}`:

```blade
@if (count($selected) > 0)
    <div class="fmm-bulk-bar">
        <span>{{ count($selected) }} selected</span>
        {{ $this->bulkMoveAction }}
        {{ $this->bulkDeleteAction }}
        <x-filament::link tag="button" wire:click="$set('selected', [])">Clear</x-filament::link>
    </div>
@endif
```

Checkbox inside each media card (before `.fmm-card-menu`):

```blade
<label class="fmm-card-check" x-on:click.stop>
    <input type="checkbox" value="{{ $media->id }}" wire:model.live="selected" class="fi-checkbox-input" />
</label>
```

CSS additions:

```css
.fmm-bulk-bar { display: flex; align-items: center; gap: 0.75rem; padding: 0.5rem 0.75rem; border-radius: 0.75rem; background: rgb(243 244 246); font-size: 0.875rem; }
.dark .fmm-bulk-bar { background: rgb(39 39 42); }
.fmm-card-check { position: absolute; top: 0.375rem; left: 0.375rem; z-index: 1; }
.fmm-card:not(:hover) .fmm-card-check:not(:has(:checked)) { display: none; }
```

- [ ] **Step 5: Run** `vendor/bin/pest` — expect PASS.

- [ ] **Step 6: Commit**

```bash
vendor/bin/pint --dirty
git add -A && git commit -m "feat: add multi-select with bulk delete and bulk move"
```

---

### Task 10: Docs, static analysis, final verification

**Files:**
- Modify: `README.md`, `CHANGELOG.md`, `phpstan-baseline.neon` (regenerate if needed)

**Interfaces:**
- Consumes: everything.
- Produces: shippable v1.

- [ ] **Step 1: Rewrite README.md**

Cover, in this order (write real prose, not placeholders): what the plugin is (media library page for Filament v5); requirements (PHP 8.2+, Laravel 13.20+, Filament 5, GD or Imagick); installation (`composer require marvelous-digital/filament-media-manager`, `php artisan filament-media-manager:install`, register `MediaManagerPlugin::make()` in the panel provider, `php artisan filament:assets`, `php artisan storage:link` when using the public disk); the full config file with each key explained (copy from `config/filament-media-manager.php`); a note that originals are stored untouched and thumbnails are queued by default (point to `thumbnail.queue` for sync); a Roadmap section listing the deferred features (focus points, responsive size variations, picker component, video thumbnails).

- [ ] **Step 2: Update CHANGELOG.md**

Add an `## v0.1.0 - 2026-07-16` entry summarizing: media library page, nested folders, multi-upload with drag-and-drop, untouched originals, WebP thumbnails via Laravel Image facade, edit name/alt/folder, bulk move/delete, search.

- [ ] **Step 3: Full verification**

```bash
vendor/bin/pint
vendor/bin/pest
composer analyse
```

Expected: pint clean, all tests pass, phpstan green (fix findings or, for vendor-signature false positives only, regenerate the baseline with `vendor/bin/phpstan analyse --generate-baseline phpstan-baseline.neon`).

- [ ] **Step 4: Commit**

```bash
git add -A && git commit -m "docs: add README and changelog for v0.1.0"
```

---

## Self-Review Notes (already applied)

- Spec coverage: folders (T2, T7), untouched originals (T4), thumbnail job + retry (T3, T8), custom grid page with breadcrumbs/search/load-more (T5), drag-and-drop multi-upload with per-file failure isolation (T6), detail slide-over with edit/download/delete (T8), bulk ops (T9), config (T1), error handling (T3/T4/T6/T7), README (T10). Focus points / conversions / picker are explicitly out of scope.
- Filament v5 API drift is the main execution risk. Where the exact method name may differ (`schema()` vs `form()`, `assertSchemaStateSet`, `mountAction` argument syntax, `$view` property signature), the plan says which vendor file to check; the Livewire tests define correct behavior either way.
- Type consistency: `GenerateThumbnail::dispatchFor()` is the single dispatch entry point used in T4 and T8; action argument key is always `media` / `folder`; `selected` is declared in T5 and consumed in T9.

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
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\LivewireServiceProvider;
use MarvelousDigital\FilamentMediaManager\MediaManagerServiceProvider;
use MarvelousDigital\FilamentMediaManager\Tests\Fixtures\AdminPanelProvider;
use MarvelousDigital\FilamentMediaManager\Tests\Fixtures\User;
use Orchestra\Testbench\TestCase as Orchestra;
use RyanChandler\BladeCaptureDirective\BladeCaptureDirectiveServiceProvider;

use function Orchestra\Testbench\default_migration_path;

class TestCase extends Orchestra
{
    use RefreshDatabase;

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
            NotificationsServiceProvider::class,
            SchemasServiceProvider::class,
            SupportServiceProvider::class,
            // LivewireServiceProvider must register after SupportServiceProvider: Filament's
            // SupportServiceProvider::register() calls $app->bind(DataStore::class, ...)
            // (non-singleton), which drops any prior singleton instance for that abstract.
            // Livewire's own LivewireServiceProvider::registerMechanisms() binds DataStore
            // as a singleton instance via app()->instance(...), which always wins over a
            // later bind() call. Registering Livewire after Filament's Support provider
            // ensures Livewire's singleton registration is the one that sticks — otherwise
            // every DataStore resolution creates a fresh instance, silently discarding
            // component state (e.g. error bags) and breaking every Livewire::test() call.
            LivewireServiceProvider::class,
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
        $app['config']->set('app.key', 'base64:' . base64_encode(random_bytes(32)));
    }

    protected function defineDatabaseMigrations(): void
    {
        // Route Laravel's default migrations (users, cache, jobs) through the same
        // loadMigrationsFrom() path as the package migrations below. loadLaravelMigrations()
        // runs eagerly via its own MigrateProcessor, outside of RefreshDatabase's migration
        // path registration; when RefreshDatabase later runs `migrate:fresh` (triggered by
        // the first database query of a test), it drops all tables and only re-applies
        // migrations registered via loadMigrationsFrom()'s deferred path, so the eagerly
        // created `users` table would be dropped and never recreated. Loading both sets of
        // migrations the same way keeps them both known to `migrate:fresh`.
        $this->loadMigrationsFrom(default_migration_path());
        $this->loadMigrationsFrom(__DIR__ . '/../database/migrations');
    }
}

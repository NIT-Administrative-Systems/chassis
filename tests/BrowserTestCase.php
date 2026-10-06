<?php

declare(strict_types=1);

namespace Northwestern\SysDev\Chassis\Tests;

use BladeUI\Heroicons\BladeHeroiconsServiceProvider;
use BladeUI\Icons\BladeIconsServiceProvider;
use Filament\Actions\ActionsServiceProvider;
use Filament\FilamentServiceProvider;
use Filament\Forms\FormsServiceProvider;
use Filament\Infolists\InfolistsServiceProvider;
use Filament\Notifications\NotificationsServiceProvider;
use Filament\QueryBuilder\QueryBuilderServiceProvider;
use Filament\Schemas\SchemasServiceProvider;
use Filament\Support\SupportServiceProvider;
use Filament\Tables\TablesServiceProvider;
use Filament\Widgets\WidgetsServiceProvider;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;
use Kirschbaum\PowerJoins\PowerJoinsServiceProvider;
use Livewire\Livewire;
use Livewire\LivewireServiceProvider;
use Northwestern\SysDev\Chassis\ChassisServiceProvider;
use Northwestern\SysDev\Chassis\Testing\Browser\Concerns\InteractsWithBrowser;
use Northwestern\SysDev\Chassis\Tests\Fixtures\Browser\AdminPanelProvider;
use Northwestern\SysDev\Chassis\Tests\Fixtures\Browser\Search;
use Northwestern\SysDev\Chassis\Tests\Fixtures\Browser\User;
use Orchestra\Testbench\TestCase as BaseTestCase;
use RuntimeException;
use RyanChandler\BladeCaptureDirective\BladeCaptureDirectiveServiceProvider;

/**
 * Browser tests run the application in the test process, with Filament, Livewire and a small
 * panel at `/admin`. The providers are listed rather than discovered, so the suite doesn't
 * depend on Testbench's package manifest.
 */
abstract class BrowserTestCase extends BaseTestCase
{
    use InteractsWithBrowser;

    private static bool $assetsPublished = false;

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('users', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->string('password');
            $table->rememberToken();
            $table->timestamps();
        });

        Schema::create('widgets', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->timestamps();
        });

        Livewire::component('search', Search::class);

        if (! self::$assetsPublished) {
            $this->publishFilamentAssets();
            self::$assetsPublished = true;
        }
    }

    /**
     * Publish Filament's assets into Testbench's public directory, one parallel worker at a time:
     * workers that publish together race to create the same directories.
     */
    private function publishFilamentAssets(): void
    {
        $lock = fopen(sys_get_temp_dir() . '/chassis-browser-tests-filament-assets.lock', 'c');

        if ($lock === false) {
            throw new RuntimeException('Could not open the lock file for publishing Filament assets.');
        }

        try {
            flock($lock, LOCK_EX);
            Artisan::call('filament:assets');
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    protected function getPackageProviders($app): array
    {
        // The order package discovery uses: Filament's providers register before Livewire's.
        return [
            BladeHeroiconsServiceProvider::class,
            BladeIconsServiceProvider::class,
            ActionsServiceProvider::class,
            FilamentServiceProvider::class,
            FormsServiceProvider::class,
            InfolistsServiceProvider::class,
            NotificationsServiceProvider::class,
            QueryBuilderServiceProvider::class,
            SchemasServiceProvider::class,
            SupportServiceProvider::class,
            TablesServiceProvider::class,
            WidgetsServiceProvider::class,
            PowerJoinsServiceProvider::class,
            LivewireServiceProvider::class,
            BladeCaptureDirectiveServiceProvider::class,
            ChassisServiceProvider::class,
            AdminPanelProvider::class,
        ];
    }

    protected function getEnvironmentSetUp($app): void
    {
        $app['config']->set('app.key', 'base64:' . base64_encode(str_repeat('c', 32)));
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);
        $app['config']->set('auth.providers.users.model', User::class);
    }

    protected function signIn(): User
    {
        $user = User::query()->create([
            'name' => 'Pat Example',
            'email' => 'pat@example.com',
            'password' => 'not-used',
        ]);

        $this->actingAs($user);

        return $user;
    }
}

<?php

declare(strict_types=1);

namespace Northwestern\SysDev\Chassis\Tests;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;
use Northwestern\SysDev\Chassis\ChassisServiceProvider;
use Northwestern\SysDev\Chassis\Testing\Browser\Concerns\InteractsWithBrowser;
use Northwestern\SysDev\Chassis\Tests\Fixtures\Browser\AdminPanelProvider;
use Northwestern\SysDev\Chassis\Tests\Fixtures\Browser\Search;
use Northwestern\SysDev\Chassis\Tests\Fixtures\Browser\User;
use Orchestra\Testbench\TestCase as BaseTestCase;

/**
 * Browser tests run the application in the test process, with Filament and Livewire loaded
 * through package discovery and a small panel at `/admin`.
 */
abstract class BrowserTestCase extends BaseTestCase
{
    use InteractsWithBrowser;

    /** @var bool */
    protected $enablesPackageDiscoveries = true;

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
            Artisan::call('filament:assets');
            self::$assetsPublished = true;
        }
    }

    protected function getPackageProviders($app): array
    {
        return [
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

<?php

declare(strict_types=1);

namespace Northwestern\SysDev\Chassis;

use Illuminate\Support\Facades\Blade;
use Northwestern\SysDev\Chassis\Console\Commands\AutoSeedListCommand;
use Northwestern\SysDev\Chassis\Console\Commands\RebuildDatabaseCommand;
use Northwestern\SysDev\Chassis\Console\Commands\RestoreLocalEnvironmentFilesCommand;
use Northwestern\SysDev\Chassis\Console\Commands\ValidateConfigurationCommand;
use Northwestern\SysDev\Chassis\Console\Commands\WakeDatabaseCommand;
use Northwestern\SysDev\Chassis\Database\SchemaChecksumManager;
use Northwestern\SysDev\Chassis\Seeding\IdempotentSeederResolver;
use Northwestern\SysDev\Chassis\Services\ConfigValidatorResolver;
use Northwestern\SysDev\Chassis\Services\DateTimeFormatter;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;

class ChassisServiceProvider extends PackageServiceProvider
{
    public function configurePackage(Package $package): void
    {
        $package->name('chassis');
    }

    public function registeringPackage(): void
    {
        $this->app->singleton(IdempotentSeederResolver::class);
        $this->app->singleton(ConfigValidatorResolver::class);
        $this->app->singleton(SchemaChecksumManager::class);
    }

    public function bootingPackage(): void
    {
        Blade::directive('datetime', resolve(DateTimeFormatter::class)->buildDatetimeDirective());

        $this->commands([
            ValidateConfigurationCommand::class,
            AutoSeedListCommand::class,
            RebuildDatabaseCommand::class,
            WakeDatabaseCommand::class,
            RestoreLocalEnvironmentFilesCommand::class,
        ]);

        $this->registerSnapshotCommands();
    }

    private function registerSnapshotCommands(): void
    {
        if (! class_exists(\Spatie\DbSnapshots\DbDumperFactory::class)) {
            return;
        }

        $this->commands([
            Console\Commands\Snapshots\CreateDatabaseSnapshotCommand::class,
            Console\Commands\Snapshots\RestoreDatabaseSnapshotCommand::class,
            Console\Commands\Snapshots\ListDatabaseSnapshotsCommand::class,
            Console\Commands\Snapshots\DeleteDatabaseSnapshotCommand::class,
            Console\Commands\Snapshots\InfoDatabaseSnapshotCommand::class,
        ]);
    }
}

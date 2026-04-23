<?php

declare(strict_types=1);

namespace Northwestern\SysDev\Chassis;

use Illuminate\Support\Facades\Blade;
use Northwestern\SysDev\Chassis\Console\Commands\AutoSeedListCommand;
use Northwestern\SysDev\Chassis\Console\Commands\Migrate\MigrateToChassisCommand;
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
    /**
     * Maps chassis commands to the old app-level classes they replace.
     * If the app-level class exists AND does not extend the chassis version,
     * the chassis's command is skipped (pre-migration state).
     *
     * @var array<class-string, string>
     */
    private const array COMMAND_OVERRIDES = [
        ValidateConfigurationCommand::class => 'App\\Console\\Commands\\ValidateConfigurationCommand',
        AutoSeedListCommand::class => 'App\\Console\\Commands\\AutoSeedListCommand',
        RebuildDatabaseCommand::class => 'App\\Console\\Commands\\RebuildDatabaseCommand',
    ];

    private const string SNAPSHOT_OVERRIDE = 'App\\Console\\Commands\\DatabaseSnapshots\\CreateDatabaseSnapshotCommand';

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

        if ($this->app->runningInConsole()) {
            $this->commands([
                MigrateToChassisCommand::class,
                WakeDatabaseCommand::class,
                RestoreLocalEnvironmentFilesCommand::class,
            ]);
            $this->registerCommandsSelectively();
            $this->registerSnapshotCommands();
        }
    }

    /**
     * Register each chassis command only if the app doesn't have its own
     * pre-migration version. Post-migration subclasses (which extend the
     * chassis base) are fine — they register themselves via Laravel's
     * command discovery and the chassis version is skipped.
     */
    private function registerCommandsSelectively(): void
    {
        $toRegister = [];

        foreach (self::COMMAND_OVERRIDES as $chassisClass => $appClass) {
            if ($this->appHasOriginalCommand($appClass, $chassisClass)) {
                continue;
            }

            // If the app has a subclass that extends the chassis command,
            // don't register the chassis's base — the app's version wins
            if ($this->appHasSubclass($appClass, $chassisClass)) {
                continue;
            }

            $toRegister[] = $chassisClass;
        }

        if ($toRegister !== []) {
            $this->commands($toRegister);
        }
    }

    private function registerSnapshotCommands(): void
    {
        if (! class_exists(\Spatie\DbSnapshots\DbDumperFactory::class)) {
            return;
        }

        // Skip if the app still has its own pre-migration snapshot commands
        if ($this->appHasOriginalCommand(self::SNAPSHOT_OVERRIDE, Console\Commands\Snapshots\CreateDatabaseSnapshotCommand::class)) {
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

    /**
     * Check if an app-level class exists and is NOT a subclass of the chassis version.
     * This means the app still has its original pre-migration implementation.
     */
    private function appHasOriginalCommand(string $appClass, string $chassisClass): bool
    {
        if (! class_exists($appClass)) {
            return false;
        }

        // If the app class extends the chassis class, it's a post-migration subclass — not a conflict
        return ! is_subclass_of($appClass, $chassisClass);
    }

    /**
     * Check if an app-level class exists and IS a subclass of the chassis version.
     */
    private function appHasSubclass(string $appClass, string $chassisClass): bool
    {
        return class_exists($appClass) && is_subclass_of($appClass, $chassisClass);
    }
}

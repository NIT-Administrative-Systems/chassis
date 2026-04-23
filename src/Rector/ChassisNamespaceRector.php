<?php

declare(strict_types=1);

namespace Northwestern\SysDev\Chassis\Rector;

/**
 * Rector rule set for migrating consuming apps from the starter's inline
 * classes to the chassis package namespace.
 *
 * Usage in your project's rector.php:
 *
 * ```php
 * use Northwestern\SysDev\Chassis\Rector\ChassisNamespaceRector;
 * use Rector\Renaming\Rector\Name\RenameClassRector;
 *
 * return RectorConfig::configure()
 *     ->withPaths([__DIR__ . '/app', __DIR__ . '/tests'])
 *     ->withConfiguredRule(RenameClassRector::class, ChassisNamespaceRector::CLASS_RENAMES);
 * ```
 */
final class ChassisNamespaceRector
{
    /**
     * Class rename map: old FQCN => new FQCN.
     *
     * @var array<string, string>
     */
    public const array CLASS_RENAMES = [
        // Models
        'App\Domains\Core\Models\BaseModel' => 'Northwestern\SysDev\Chassis\Models\BaseModel',
        'App\Domains\Core\Models\Scopes\AutomaticallyOrderedScope' => 'Northwestern\SysDev\Chassis\Models\Scopes\AutomaticallyOrderedScope',

        // Attributes
        'App\Domains\Core\Attributes\AutomaticallyOrdered' => 'Northwestern\SysDev\Chassis\Attributes\AutomaticallyOrdered',
        'App\Domains\Core\Attributes\AutoSeed' => 'Northwestern\SysDev\Chassis\Attributes\AutoSeed',
        'App\Domains\Core\Attributes\StarterValidator' => 'Northwestern\SysDev\Chassis\Attributes\ValidatesConfig',

        // Contracts
        'App\Domains\Core\Contracts\ConfigValidator' => 'Northwestern\SysDev\Chassis\Contracts\ConfigValidator',
        'App\Domains\Core\Contracts\IdempotentSeederInterface' => 'Northwestern\SysDev\Chassis\Contracts\IdempotentSeederInterface',

        // Seeding
        'App\Domains\Core\Seeders\IdempotentSeeder' => 'Northwestern\SysDev\Chassis\Seeding\IdempotentSeeder',
        'App\Domains\Core\Seeders\Concerns\AuditsSeederChanges' => 'Northwestern\SysDev\Chassis\Seeding\Concerns\AuditsSeederChanges',
        'App\Domains\Core\Services\IdempotentSeederResolver' => 'Northwestern\SysDev\Chassis\Seeding\IdempotentSeederResolver',
        'App\Domains\Core\Seeders\DiscoverSeeders' => 'Northwestern\SysDev\Chassis\Seeding\IdempotentSeederResolver',
        'App\Domains\Core\Database\ValueObjects\SeederInfo' => 'Northwestern\SysDev\Chassis\Seeding\ValueObjects\SeederInfo',

        // Database / Snapshots
        'App\Domains\Core\Database\SchemaChecksumManager' => 'Northwestern\SysDev\Chassis\Database\SchemaChecksumManager',
        'App\Domains\Core\Database\ConfigurableDbDumperFactory' => 'Northwestern\SysDev\Chassis\Database\ConfigurableDbDumperFactory',
        'App\Domains\Core\Database\ValueObjects\SchemaSnapshot' => 'Northwestern\SysDev\Chassis\Database\ValueObjects\SchemaSnapshot',
        'App\Domains\Core\Database\ValueObjects\SnapshotListItem' => 'Northwestern\SysDev\Chassis\Database\ValueObjects\SnapshotListItem',
        'App\Domains\Core\Database\ValueObjects\SchemaFileCollection' => 'Northwestern\SysDev\Chassis\Database\ValueObjects\SchemaFileCollection',

        // HTTP / Responses
        'App\Http\Responses\ProblemDetails' => 'Northwestern\SysDev\Chassis\Http\Responses\ProblemDetails',

        // Exceptions
        'App\Domains\Core\Exceptions\ProblemDetailsRenderer' => 'Northwestern\SysDev\Chassis\Exceptions\ProblemDetailsRenderer',
        'App\Domains\Core\Exceptions\MissingRequestIpForRestrictedTokenException' => 'Northwestern\SysDev\Chassis\Exceptions\MissingRequestIpForRestrictedTokenException',
        'App\Domains\Core\Exceptions\MissingRequestIpForRestrictedToken' => 'Northwestern\SysDev\Chassis\Exceptions\MissingRequestIpForRestrictedTokenException',

        // Value Objects
        'App\Domains\Core\ValueObjects\ApiRequestContext' => 'Northwestern\SysDev\Chassis\ValueObjects\ApiRequestContext',
        'App\Domains\Core\ValueObjects\ResolvedValidator' => 'Northwestern\SysDev\Chassis\ValueObjects\ResolvedValidator',

        // Rules
        'App\Domains\Core\Rules\ValidIpOrCidrRule' => 'Northwestern\SysDev\Chassis\Rules\ValidIpOrCidrRule',

        // Config Validation
        'App\Domains\Core\Services\ConfigValidatorResolver' => 'Northwestern\SysDev\Chassis\Services\ConfigValidatorResolver',

        // Console
        'App\Console\Commands\Concerns\RunsSteps' => 'Northwestern\SysDev\Chassis\Console\Concerns\RunsSteps',
        // Snapshot commands (newer: DatabaseSnapshots/ subdirectory)
        'App\Console\Commands\DatabaseSnapshots\DatabaseSnapshotCommand' => 'Northwestern\SysDev\Chassis\Console\Commands\Snapshots\DatabaseSnapshotCommand',
        'App\Console\Commands\DatabaseSnapshots\CreateDatabaseSnapshotCommand' => 'Northwestern\SysDev\Chassis\Console\Commands\Snapshots\CreateDatabaseSnapshotCommand',
        'App\Console\Commands\DatabaseSnapshots\RestoreDatabaseSnapshotCommand' => 'Northwestern\SysDev\Chassis\Console\Commands\Snapshots\RestoreDatabaseSnapshotCommand',
        'App\Console\Commands\DatabaseSnapshots\ListDatabaseSnapshotsCommand' => 'Northwestern\SysDev\Chassis\Console\Commands\Snapshots\ListDatabaseSnapshotsCommand',
        'App\Console\Commands\DatabaseSnapshots\DeleteDatabaseSnapshotCommand' => 'Northwestern\SysDev\Chassis\Console\Commands\Snapshots\DeleteDatabaseSnapshotCommand',
        'App\Console\Commands\DatabaseSnapshots\InfoDatabaseSnapshotCommand' => 'Northwestern\SysDev\Chassis\Console\Commands\Snapshots\InfoDatabaseSnapshotCommand',
        // Snapshot commands (older: flat in Commands/)
        'App\Console\Commands\DatabaseSnapshotCommand' => 'Northwestern\SysDev\Chassis\Console\Commands\Snapshots\DatabaseSnapshotCommand',
        'App\Console\Commands\CreateDatabaseSnapshotCommand' => 'Northwestern\SysDev\Chassis\Console\Commands\Snapshots\CreateDatabaseSnapshotCommand',
        'App\Console\Commands\RestoreDatabaseSnapshotCommand' => 'Northwestern\SysDev\Chassis\Console\Commands\Snapshots\RestoreDatabaseSnapshotCommand',
        'App\Console\Commands\ListDatabaseSnapshotsCommand' => 'Northwestern\SysDev\Chassis\Console\Commands\Snapshots\ListDatabaseSnapshotsCommand',
        'App\Console\Commands\DeleteDatabaseSnapshotCommand' => 'Northwestern\SysDev\Chassis\Console\Commands\Snapshots\DeleteDatabaseSnapshotCommand',
        'App\Console\Commands\InfoDatabaseSnapshotCommand' => 'Northwestern\SysDev\Chassis\Console\Commands\Snapshots\InfoDatabaseSnapshotCommand',

        // Middleware
        'App\Http\Middleware\EnsureApiEnabled' => 'Northwestern\SysDev\Chassis\Http\Middleware\EnsureFeatureEnabled',
        'App\Http\Middleware\EnvironmentLockdown' => 'Northwestern\SysDev\Chassis\Http\Middleware\EnvironmentLockdown',

        // v1.1 additions
        'App\Domains\Core\Models\Concerns\Auditable' => 'Northwestern\SysDev\Chassis\Models\Concerns\Auditable',
        'App\Domains\Core\Enums\ApiRequestFailure' => 'Northwestern\SysDev\Chassis\Enums\ApiRequestFailure',
        'App\Domains\Core\Services\DateTimeFormatter' => 'Northwestern\SysDev\Chassis\Services\DateTimeFormatter',
        'App\Domains\Core\Exceptions\SentryExceptionHandler' => 'Northwestern\SysDev\Chassis\Exceptions\SentryExceptionHandler',
        'App\Console\Commands\RebuildDatabaseCommand' => 'Northwestern\SysDev\Chassis\Console\Commands\RebuildDatabaseCommand',
        'App\Console\Commands\WakeDatabaseCommand' => 'Northwestern\SysDev\Chassis\Console\Commands\WakeDatabaseCommand',
        'App\Console\Commands\RestoreLocalEnvironmentFilesCommand' => 'Northwestern\SysDev\Chassis\Console\Commands\RestoreLocalEnvironmentFilesCommand',
    ];
}
